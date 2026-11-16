<?php
namespace OCA\JoplixSync\Sync;

use OCA\JoplixSync\Service\JoplinItem;
use OCP\IL10N;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

/**
 * Two-way synchronisation between a Nextcloud folder holding Joplin sync
 * items (<id>.md plus .resource/<id>) and a Joplin Server account.
 *
 * The folder is JoplixSync's working copy and, because every write goes through
 * the Nextcloud files API, also a versioned backup of the server content.
 *
 * A run is time-boxed and resumable: when the budget is used up it returns
 * "partial" and the next run continues where this one stopped.
 *
 * The folder is edited by users while a run is in progress. The listing taken
 * in scanLocal() only says which files to look at; whether a file was changed
 * locally is always decided from a fresh lookup (current()) immediately before
 * the engine writes or deletes it, inside the write lock ($guard) that the API
 * takes for its own writes. Never decide from the nodes cached in $md alone:
 * their etags are as old as the scan.
 *
 * State layout:
 *   clientId     stable id used for the server-side sync lock
 *   cursor       delta cursor of the last fully processed page
 *   items        name => ['e' => local etag, 'r' => remote updated_time (ms), 'b' => blob_updated_time]
 *   blobs        resource id => local etag of the blob at last sync
 *   blobPending  resource id => 1, blobs that were not yet on the server
 *   rejected     name => ['e' => local etag, 'm' => message], uploads the server refused
 *   resync       true while a full resynchronisation still has to be reconciled
 *
 * Deletions (finding Y5): a synchronised file that is gone from the folder is deleted on the server only
 * when JoplixSync itself deleted it ($deleted, the names JoplixWriteService reported), or when the user chose
 * "delete" for the missing items. Any other disappearance (Files app, desktop client, unmounted storage) is
 * reported as "missing" and left alone until the user chooses to delete it on the server or to restore it.
 */
class SyncEngine {
    private const MD_PATTERN = '/^[a-f0-9]{32}\.md$/';
    private const ID_PATTERN = '/^[a-f0-9]{32}$/';
    private const RESOURCE_DIR = '.resource';
    private const SUPPORTED_SYNC_VERSION = 3;
    private const LOCK_REFRESH_SECONDS = 60;
    /** Joplin Server drops a sync lock after three minutes; a blob transfer must end well before that. */
    private const BLOB_TIMEOUT_SECONDS = 150;

    private Folder $folder;
    private JoplinServerClient $client;
    private array $state;
    /** @var callable|null */
    private $persist;
    /** @var callable|null runs a closure under the per-user write lock; null = run it directly */
    private $guard;

    /** @var array<string, File> */
    private array $md = [];
    /** @var array<string, File> */
    private array $blobs = [];
    private ?Folder $resourceFolder = null;
    /** True when the folder held no items at the start of the run although some were synchronised before. */
    private bool $folderWasEmptied = false;

    /** @var array<string,true> item names JoplixSync deleted on purpose */
    private array $deleted;
    /** @var string[] names from $deleted that were dealt with in this run */
    private array $handledDeletes = [];
    private ?IL10N $l;
    private bool $lockHeld = false;

    private float $deadline = 0.0;
    private float $lockTime = 0.0;
    private array $stats = [];

    /**
     * @param callable|null $persist called with the current state at safe points so progress survives an abort
     * @param callable|null $guard fn(callable $critical): mixed, runs $critical while holding the lock that
     *                             API writes to the same folder take (JoplixWriteLock)
     */
    /**
     * @param string[] $deleted names of item files JoplixSync deleted (confirmed deletions)
     */
    public function __construct(Folder $folder, JoplinServerClient $client, array $state, ?callable $persist = null, ?callable $guard = null, array $deleted = [], ?IL10N $l = null) {
        $this->deleted = array_fill_keys($deleted, true);
        $this->l = $l;
        $this->folder = $folder;
        $this->client = $client;
        $this->state = $state + ['cursor' => '', 'items' => [], 'blobs' => [], 'blobPending' => [], 'rejected' => [], 'resync' => false];
        foreach (['items', 'blobs', 'blobPending', 'rejected'] as $key) {
            if (!is_array($this->state[$key])) {
                $this->state[$key] = [];
            }
        }
        if (empty($this->state['clientId']) || !preg_match(self::ID_PATTERN, (string)$this->state['clientId'])) {
            $this->state['clientId'] = bin2hex(random_bytes(16));
        }
        $this->persist = $persist;
        $this->guard = $guard;
    }

    public function state(): array {
        return $this->state;
    }

    /** @return string[] confirmed deletions that are done (deleted on the server or no longer pending) */
    public function handledDeletes(): array {
        return $this->handledDeletes;
    }

    /** Translated text when an IL10N was given, else the English source (tests, background without l10n). */
    private function t(string $text, array $params = []): string {
        return $this->l !== null ? $this->l->t($text, $params) : vsprintf($text, $params);
    }

    /**
     * @param string $missingAction what to do with synchronised files that disappeared without JoplixSync deleting
     *                              them: '' report them, 'delete' delete them on the server, 'restore' download them again
     * @return array{status: string, message?: string, code?: string, pulled: int, pushed: int, deleted_local: int, deleted_remote: int, conflicts: int, rejected: int, missing: int, overwritten: int, encrypted: int, restored: int}
     */
    public function run(int $budgetSeconds, string $missingAction = ''): array {
        $this->deadline = microtime(true) + max(1, $budgetSeconds);
        $this->stats = ['pulled' => 0, 'pushed' => 0, 'deleted_local' => 0, 'deleted_remote' => 0, 'conflicts' => 0, 'rejected' => 0, 'missing' => 0, 'overwritten' => 0, 'encrypted' => 0, 'restored' => 0];
        $clientId = (string)$this->state['clientId'];

        try {
            $this->client->acquireSyncLock($clientId);
        } catch (JoplinServerException $e) {
            if (in_array($e->serverCode(), ['hasExclusiveLock', 'hasSyncLock'], true)) {
                return ['status' => 'busy', 'message' => $this->t('Another Joplin client currently holds a lock on the server.')] + $this->stats;
            }
            throw $e;
        }
        $this->lockTime = microtime(true);
        $this->lockHeld = true;
        // If PHP ends the request (execution time limit) in the middle of a transfer, the finally block below
        // does not run; release the server lock anyway instead of leaving it for three minutes.
        register_shutdown_function(function () use ($clientId): void {
            if ($this->lockHeld) {
                try { $this->client->releaseSyncLock($clientId); } catch (\Throwable $e) {}
            }
        });

        try {
            $this->checkSyncTarget();
            $this->scanLocal();
            if ($this->folderWasEmptied && $missingAction === '') {
                // An emptied, moved or unmounted folder must never wipe the server.
                throw new SyncAbortException(
                    $this->t('The Nextcloud folder no longer contains any of the %1$s synchronised items. Nothing was deleted on the server. Restore them from the server, delete them there as well, or restore the folder.', [(string)count($this->state['items'])]),
                    'massDelete'
                );
            }
            $complete = $this->pull();
            if ($complete) {
                $complete = $this->push($missingAction);
            }
            $result = ['status' => $complete ? 'done' : 'partial'] + $this->stats;
            if ($complete && $this->stats['missing'] > 0) {
                $result['code'] = 'missing';
                $result['message'] = $this->t('%1$s synchronised items are missing from the Nextcloud folder, but JoplixSync did not delete them. They were not deleted on the server.', [(string)$this->stats['missing']]);
            }
            return $result;
        } catch (SyncAbortException $e) {
            return ['status' => 'error', 'message' => $e->getMessage(), 'code' => $e->reason()] + $this->stats;
        } finally {
            $this->save();
            try {
                $this->client->releaseSyncLock($clientId);
            } catch (\Throwable $e) {
                // The lock expires on its own after three minutes.
            }
            $this->lockHeld = false;
        }
    }

    /** Refreshes the server lock now, so a long transfer that follows starts with the full three minutes. */
    private function refreshLock(): void {
        $this->client->acquireSyncLock((string)$this->state['clientId']);
        $this->lockTime = microtime(true);
    }

    // ---------------------------------------------------------------- setup

    private function checkSyncTarget(): void {
        $raw = $this->client->getText('info.json');
        if ($raw === null) {
            throw new SyncAbortException($this->t('The Joplin Server account holds no sync data yet. Synchronise once from a Joplin app first.'), 'emptyTarget');
        }
        $info = json_decode($raw, true);
        if (!is_array($info)) {
            throw new SyncAbortException($this->t('The sync information on the server (info.json) is not readable.'), 'badTarget');
        }
        $version = (int)($info['version'] ?? 0);
        if ($version !== self::SUPPORTED_SYNC_VERSION) {
            throw new SyncAbortException($this->t('Unsupported sync target version %1$s (JoplixSync supports version %2$s).', [(string)$version, (string)self::SUPPORTED_SYNC_VERSION]), 'badVersion');
        }
        $e2ee = $info['e2ee'] ?? false;
        if (is_array($e2ee)) {
            $e2ee = $e2ee['value'] ?? false;
        }
        if ($e2ee) {
            throw new SyncAbortException($this->t('End-to-end encryption is enabled on this Joplin account. JoplixSync cannot read encrypted notes.'), 'e2ee');
        }
        // Keep a copy for the backup. JoplixSync never writes info.json to the server.
        try {
            if ($this->folder->nodeExists('info.json')) {
                $node = $this->folder->get('info.json');
                if ($node instanceof File && $node->getContent() !== $raw) {
                    $node->putContent($raw);
                }
            } else {
                $this->folder->newFile('info.json', $raw);
            }
        } catch (\Throwable $e) {
            // The copy is a convenience for restores, not a requirement for syncing.
        }
    }

    private function scanLocal(): void {
        $this->md = [];
        $this->blobs = [];
        foreach ($this->folder->getDirectoryListing() as $node) {
            if ($node instanceof File && preg_match(self::MD_PATTERN, $node->getName())) {
                $this->md[$node->getName()] = $node;
            }
        }
        $this->folderWasEmptied = $this->md === [] && $this->state['items'] !== [];
        if ($this->folder->nodeExists(self::RESOURCE_DIR)) {
            $rf = $this->folder->get(self::RESOURCE_DIR);
            if ($rf instanceof Folder) {
                $this->resourceFolder = $rf;
                foreach ($rf->getDirectoryListing() as $node) {
                    if ($node instanceof File && preg_match(self::ID_PATTERN, $node->getName())) {
                        $this->blobs[$node->getName()] = $node;
                    }
                }
            }
        }
    }

    // ----------------------------------------------------------------- pull

    /** @return bool true when everything the server reported has been applied */
    private function pull(): bool {
        while (true) {
            $cursor = (string)$this->state['cursor'];
            try {
                $page = $this->client->delta($cursor);
            } catch (JoplinServerException $e) {
                if ($e->serverCode() === 'resyncRequired' && $cursor !== '') {
                    // The server no longer knows our cursor. Start over and reconcile afterwards.
                    $this->state['cursor'] = '';
                    $this->state['resync'] = true;
                    continue;
                }
                throw $e;
            }
            foreach ($page['items'] as $change) {
                if ($this->timeUp()) {
                    // Cursor stays where it was. Items already applied are skipped next time.
                    return false;
                }
                if (is_array($change)) {
                    $this->applyRemoteChange($change);
                }
            }
            $this->state['cursor'] = $page['cursor'];
            $this->save();
            if (!$page['has_more']) {
                break;
            }
            if ($this->timeUp()) {
                return false;
            }
        }

        foreach (array_keys($this->state['blobPending']) as $id) {
            if ($this->timeUp()) {
                return false;
            }
            $this->fetchBlob((string)$id);
        }

        if (!empty($this->state['resync'])) {
            if (!$this->reconcileAfterResync()) {
                return false;
            }
            $this->state['resync'] = false;
        }
        return true;
    }

    private function applyRemoteChange(array $change): void {
        $name = (string)($change['item_name'] ?? '');
        if (!preg_match(self::MD_PATTERN, $name)) {
            // Resource blobs follow their resource item; locks, temp files and
            // anything else on the server are not part of the notes.
            return;
        }
        $id = substr($name, 0, 32);
        $known = $this->state['items'][$name] ?? null;

        if ((int)($change['type'] ?? 0) === 3) {
            $this->applyRemoteDelete($name, $id);
            return;
        }

        $remoteTime = (int)($change['jop_updated_time'] ?? 0);
        if ($known !== null && $remoteTime > 0 && (int)($known['r'] ?? 0) === $remoteTime) {
            // Already had this version (typically our own upload echoed back). If the
            // file is gone it was deleted here, and the push phase deletes it remotely.
            return;
        }

        // Network first, outside the write lock.
        $remote = $this->client->getText($name);
        if ($remote === null) {
            // Gone again in the meantime; a delete change follows.
            return;
        }
        $meta = self::meta($remote);
        if ($remoteTime === 0) {
            $remoteTime = self::toMillis((string)($meta['updated_time'] ?? ''));
        }
        if (($meta['encryption_applied'] ?? '') === '1') {
            // Encrypted item (left over from a time when E2EE was on): JoplixSync cannot read it and must not
            // replace a readable local copy with ciphertext. Remembered so it is not downloaded every run.
            // Only an item that was synchronised before gets the new time; an unknown one is not recorded,
            // or a missing local file would then count as "missing".
            if ($known !== null) {
                $this->state['items'][$name] = ['r' => $remoteTime] + $known;
            }
            $this->stats['encrypted']++;
            return;
        }

        // Look at the file and replace it in one locked step, so a save that lands
        // during this run is either seen here (conflict copy) or happens afterwards
        // against the new version (the API then reports the conflict to the user).
        $etag = $this->guarded(function () use ($name, $id, $known, $remote, $remoteTime): string {
            $node = $this->current($name);
            $localModified = $node !== null && ($known === null || (string)($known['e'] ?? '') !== $node->getEtag());
            $local = $node !== null ? $node->getContent() : null;
            if ($localModified && $local !== $remote) {
                $localMeta = self::meta((string)$local);
                $sameVersion = ($localMeta['updated_time'] ?? '') !== '' && self::toMillis((string)$localMeta['updated_time']) === $remoteTime;
                $localType = (string)($localMeta['type_'] ?? '');
                if (!$sameVersion && $localType === '1') {
                    // Both sides changed the note. The server version wins; the local
                    // edit survives as a conflict note, as Joplin itself does.
                    $this->writeConflictCopy((string)$local, $id);
                    $this->stats['conflicts']++;
                } elseif (!$sameVersion && ($localType === '2' || $localType === '5')) {
                    // A notebook or tag changed on both sides: the server version wins, as in Joplin. Counted
                    // so the result says so instead of replacing the local change silently.
                    $this->stats['overwritten']++;
                }
            }
            if ($node === null) {
                $this->folder->newFile($name, $remote);
            } elseif ($local !== $remote) {
                $node->putContent($remote);
            }
            $node = $this->freshFile($this->folder, $name);
            $this->md[$name] = $node;
            return $node->getEtag();
        });

        $record = ['e' => $etag, 'r' => $remoteTime];
        if (($meta['type_'] ?? '') === '4') {
            $blobTime = (string)($meta['blob_updated_time'] ?? '');
            $record['b'] = $blobTime;
            $blobChanged = $known === null || (string)($known['b'] ?? '') !== $blobTime;
            if ($blobChanged && $known !== null && isset($this->blobs[$id], $this->state['blobs'][$id])
                && (string)$this->state['blobs'][$id] !== $this->blobs[$id]->getEtag()) {
                // The attachment's file changed here (Office edit) and on the server: keep the local file as a
                // separate attachment in a conflict note before the server's file replaces it, as Joplin does.
                $this->writeResourceConflict($id, $meta);
            }
            if (!isset($this->blobs[$id]) || $blobChanged) {
                $this->fetchBlob($id);
            }
        }
        $this->state['items'][$name] = $record;
        unset($this->state['rejected'][$name]);
        $this->stats['pulled']++;
    }

    private function applyRemoteDelete(string $name, string $id): void {
        $known = $this->state['items'][$name] ?? null;
        $node = $this->current($name);
        if ($node !== null && ($known === null || (string)($known['e'] ?? '') !== $node->getEtag())) {
            // Edited here after the last sync and deleted elsewhere: keep the edit.
            // Dropping the record makes the push phase upload it again.
            unset($this->state['items'][$name]);
            return;
        }
        if ($node !== null) {
            if ($this->client->exists($name)) {
                // Deleted and created again since; a later change describes the current version.
                return;
            }
            $deleted = $this->guarded(function () use ($name, $known): bool {
                // Same check again under the lock: a save may have arrived during the request above.
                $node = $this->current($name);
                if ($node === null) {
                    return true;
                }
                if ((string)($known['e'] ?? '') !== $node->getEtag()) {
                    return false;
                }
                $node->delete();
                unset($this->md[$name]);
                $this->stats['deleted_local']++;
                return true;
            });
            if (!$deleted) {
                unset($this->state['items'][$name]);
                return;
            }
        }
        if (isset($this->blobs[$id])) {
            $this->blobs[$id]->delete();
            unset($this->blobs[$id]);
        }
        unset($this->state['items'][$name], $this->state['blobs'][$id], $this->state['blobPending'][$id], $this->state['rejected'][$name]);
    }

    private function fetchBlob(string $id): void {
        if (!preg_match(self::ID_PATTERN, $id)) {
            unset($this->state['blobPending'][$id]);
            return;
        }
        $this->refreshLock();
        $stream = $this->client->getStream(self::RESOURCE_DIR . '/' . $id, self::BLOB_TIMEOUT_SECONDS);
        if ($stream === null) {
            // Joplin uploads blob and metadata separately; try again on the next run.
            $this->state['blobPending'][$id] = 1;
            return;
        }
        try {
            $rf = $this->resourceFolder();
            if (isset($this->blobs[$id])) {
                $this->blobs[$id]->putContent($stream);
            } else {
                $rf->newFile($id, $stream);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $node = $this->freshFile($this->resourceFolder(), $id);
        $this->blobs[$id] = $node;
        $this->state['blobs'][$id] = $node->getEtag();
        unset($this->state['blobPending'][$id]);
    }

    /**
     * After a full resynchronisation the delta feed may no longer mention items
     * that were deleted long ago. Remove local copies that are unchanged since
     * the last sync and no longer exist on the server.
     */
    private function reconcileAfterResync(): bool {
        $remote = [];
        $cursor = '';
        while (true) {
            if ($this->timeUp()) {
                return false;
            }
            $page = $this->client->children($cursor);
            foreach ($page['items'] as $item) {
                if (is_array($item) && isset($item['name'])) {
                    $remote[(string)$item['name']] = true;
                }
            }
            if (!$page['has_more'] || $page['cursor'] === '') {
                break;
            }
            $cursor = $page['cursor'];
        }
        foreach (array_keys($this->md) as $name) {
            $name = (string)$name;
            if (isset($remote[$name])) {
                continue;
            }
            $known = $this->state['items'][$name] ?? null;
            $removed = $this->guarded(function () use ($name, $known): ?bool {
                $node = $this->current($name);
                if ($node === null) {
                    return null;
                }
                if ($known === null || (string)($known['e'] ?? '') !== $node->getEtag()) {
                    return false;
                }
                $node->delete();
                return true;
            });
            if ($removed === null) {
                // Disappeared since the scan; the push phase handles it.
                continue;
            }
            if ($removed === false) {
                // New or edited here: the push phase uploads it.
                unset($this->state['items'][$name]);
                continue;
            }
            $id = substr($name, 0, 32);
            unset($this->md[$name], $this->state['items'][$name]);
            if (isset($this->blobs[$id])) {
                $this->blobs[$id]->delete();
                unset($this->blobs[$id], $this->state['blobs'][$id]);
            }
            $this->stats['deleted_local']++;
        }
        return true;
    }

    // ----------------------------------------------------------------- push

    /** @return bool true when every local change has been sent */
    private function push(string $missingAction): bool {
        $gone = [];
        foreach ($this->state['items'] as $name => $_) {
            $name = (string)$name;
            if (isset($this->md[$name])) {
                continue;
            }
            if (isset($this->deleted[$name]) || $missingAction === 'delete') {
                $gone[] = $name;
            } elseif ($missingAction === 'restore') {
                if ($this->timeUp()) {
                    return false;
                }
                $this->restoreItem($name);
            } else {
                $this->stats['missing']++;
            }
        }
        // Confirmed deletions of items that were never synchronised need nothing on the server.
        foreach (array_keys($this->deleted) as $name) {
            if (!isset($this->state['items'][$name])) {
                $this->handledDeletes[] = (string)$name;
            }
        }

        foreach ($this->md as $name => $node) {
            if ($this->timeUp()) {
                return false;
            }
            $name = (string)$name;
            $known = $this->state['items'][$name] ?? null;
            $rejected = $this->state['rejected'][$name] ?? null;
            // The scan's etag is enough to skip unchanged files; a save made since then
            // changes it and is picked up by the next run at the latest.
            $scanEtag = $node->getEtag();
            if (($known !== null && (string)($known['e'] ?? '') === $scanEtag) || ($rejected !== null && (string)($rejected['e'] ?? '') === $scanEtag)) {
                continue;
            }
            // Etag and content must describe the same version, so read both under the lock.
            $snapshot = $this->guarded(function () use ($name): ?array {
                $fresh = $this->current($name);
                return $fresh === null ? null : [$fresh->getEtag(), $fresh->getContent()];
            });
            if ($snapshot === null) {
                continue;
            }
            $this->uploadItem($name, $snapshot[1], $snapshot[0]);
        }

        foreach ($gone as $name) {
            if ($this->timeUp()) {
                return false;
            }
            if ($this->current($name) !== null) {
                // Back again since the scan (restored or re-created): not a deletion.
                continue;
            }
            $id = substr($name, 0, 32);
            $this->client->delete($name);
            $this->handledDeletes[] = $name;
            if (isset($this->state['blobs'][$id]) || isset($this->blobs[$id])) {
                $this->client->delete(self::RESOURCE_DIR . '/' . $id);
                if (isset($this->blobs[$id])) {
                    $this->blobs[$id]->delete();
                    unset($this->blobs[$id]);
                }
            }
            unset($this->state['items'][$name], $this->state['blobs'][$id], $this->state['blobPending'][$id], $this->state['rejected'][$name]);
            $this->stats['deleted_remote']++;
        }
        return true;
    }

    private function uploadItem(string $name, string $content, string $etag): void {
        $id = substr($name, 0, 32);
        $meta = self::meta($content);
        $type = (string)($meta['type_'] ?? '');
        if ($type === '' || (string)($meta['id'] ?? '') !== $id) {
            $this->reject($name, $etag, $this->t('Not a valid Joplin item (id or type_ missing).'));
            return;
        }
        try {
            if ($type === '4' && isset($this->blobs[$id])) {
                $blob = $this->blobs[$id];
                $blobEtag = $blob->getEtag();
                if ((string)($this->state['blobs'][$id] ?? '') !== $blobEtag) {
                    // Blob first, so no client ever sees a resource without its file.
                    $this->refreshLock();
                    $stream = $blob->fopen('rb');
                    try {
                        $this->client->put(self::RESOURCE_DIR . '/' . $id, $stream, self::BLOB_TIMEOUT_SECONDS);
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }
                    $this->state['blobs'][$id] = $blobEtag;
                }
            }
            $this->client->put($name, $content);
        } catch (JoplinServerException $e) {
            if ($e->isRejection()) {
                $this->reject($name, $etag, $e->getMessage());
                return;
            }
            throw $e;
        }
        $record = ['e' => $etag, 'r' => self::toMillis((string)($meta['updated_time'] ?? ''))];
        if ($type === '4') {
            $record['b'] = (string)($meta['blob_updated_time'] ?? '');
        }
        $this->state['items'][$name] = $record;
        unset($this->state['rejected'][$name]);
        $this->stats['pushed']++;
    }

    private function reject(string $name, string $etag, string $message): void {
        $this->state['rejected'][$name] = ['e' => $etag, 'm' => substr($message, 0, 300)];
        $this->stats['rejected']++;
    }

    // -------------------------------------------------------------- helpers

    private function writeConflictCopy(string $content, string $originalId): void {
        $content = rtrim(str_replace("\r\n", "\n", $content));
        $pos = strrpos($content, "\n\n");
        if ($pos === false) {
            return;
        }
        $body = substr($content, 0, $pos);
        $meta = substr($content, $pos + 2);
        $newId = bin2hex(random_bytes(16));
        $meta = self::setMetaLine($meta, 'id', $newId);
        $meta = self::setMetaLine($meta, 'is_conflict', '1');
        $meta = self::setMetaLine($meta, 'conflict_original_id', $originalId);
        $name = $newId . '.md';
        $this->folder->newFile($name, $body . "\n\n" . $meta);
        // No state record: the push phase uploads it as a new note.
        $this->md[$name] = $this->freshFile($this->folder, $name);
    }

    /** Downloads a synchronised item (and its file) that disappeared from the folder. */
    private function restoreItem(string $name): void {
        $remote = $this->client->getText($name);
        if ($remote === null) {
            unset($this->state['items'][$name]);
            return;
        }
        $etag = $this->guarded(function () use ($name, $remote): string {
            if ($this->current($name) === null) {
                $this->folder->newFile($name, $remote);
            }
            $node = $this->freshFile($this->folder, $name);
            $this->md[$name] = $node;
            return $node->getEtag();
        });
        $record = $this->state['items'][$name];
        $record['e'] = $etag;
        $this->state['items'][$name] = $record;
        $id = substr($name, 0, 32);
        if ((self::meta($remote)['type_'] ?? '') === '4' && !isset($this->blobs[$id])) {
            $this->fetchBlob($id);
        }
        $this->stats['restored']++;
    }

    /**
     * Copies the local file of attachment $id into a new attachment and adds a conflict note that links it,
     * so the local version survives when the server's version of the file replaces it.
     * @param array<string,string> $remoteMeta
     */
    private function writeResourceConflict(string $id, array $remoteMeta): void {
        $this->guarded(function () use ($id, $remoteMeta): void {
            $item = $this->current($id . '.md');
            $blob = $this->blobs[$id] ?? null;
            if ($item === null || $blob === null) {
                return;
            }
            $newId = bin2hex(random_bytes(16));
            $stream = $blob->fopen('rb');
            try {
                $this->resourceFolder()->newFile($newId, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $content = rtrim(str_replace(["\r\n", "\r"], "\n", $item->getContent()));
            $pos = strrpos($content, "\n\n");
            $body = $pos === false ? '' : substr($content, 0, $pos);
            $meta = $pos === false ? $content : substr($content, $pos + 2);
            $meta = self::setMetaLine($meta, 'id', $newId);
            $this->folder->newFile($newId . '.md', ($body !== '' ? $body . "\n\n" : '') . $meta);
            $this->md[$newId . '.md'] = $this->freshFile($this->folder, $newId . '.md');
            $this->blobs[$newId] = $this->freshFile($this->resourceFolder(), $newId);

            $title = $body !== '' ? strtok($body, "\n") : $id;
            // The conflict note goes into the notebook of a note that uses the attachment, so it is listed
            // next to it (JoplixSync has no separate "Conflicts" view). Conflicts are rare; the scan is acceptable.
            $parentId = '';
            foreach ($this->md as $mdName => $mdNode) {
                try {
                    $text = $mdNode->getContent();
                } catch (\Throwable $e) {
                    continue;
                }
                if (str_contains($text, ':/' . $id)) {
                    $m = self::meta($text);
                    if (($m['type_'] ?? '') === '1' && ($m['parent_id'] ?? '') !== '') {
                        $parentId = (string)$m['parent_id'];
                        break;
                    }
                }
            }
            $now = gmdate('Y-m-d\TH:i:s.000\Z');
            $noteId = bin2hex(random_bytes(16));
            $note = $this->t('Attachment conflict: %1$s', [$title]) . "\n\n"
                . $this->t('The attachment was changed here and on another device. The server version is now in the note; this is the version from Nextcloud:') . "\n\n"
                . '[' . str_replace(['[', ']'], ['\[', '\]'], $title) . '](:/' . $newId . ')' . "\n\n"
                . implode("\n", ['id: ' . $noteId, 'parent_id: ' . $parentId,'created_time: ' . $now, 'updated_time: ' . $now, 'is_conflict: 1', 'source: joplixsync', 'source_application: joplixsync', 'user_created_time: ' . $now, 'user_updated_time: ' . $now, 'encryption_cipher_text: ', 'encryption_applied: 0', 'markup_language: 1', 'conflict_original_id: ', 'type_: 1']);
            $this->folder->newFile($noteId . '.md', $note);
            $this->md[$noteId . '.md'] = $this->freshFile($this->folder, $noteId . '.md');
            $this->stats['conflicts']++;
        });
    }

    private static function setMetaLine(string $meta, string $key, string $value): string {
        $lines = explode("\n", $meta);
        $typeLine = null;
        foreach ($lines as $i => $line) {
            if (strncmp($line, $key . ':', strlen($key) + 1) === 0) {
                $lines[$i] = $key . ': ' . $value;
                return implode("\n", $lines);
            }
            if (strncmp($line, 'type_:', 6) === 0) {
                $typeLine = $i;
            }
        }
        if ($typeLine === null) {
            $lines[] = $key . ': ' . $value;
        } else {
            array_splice($lines, $typeLine, 0, [$key . ': ' . $value]);
        }
        return implode("\n", $lines);
    }

    /**
     * Metadata of a serialised Joplin item. Same parser as JoplixModelService, so what the engine uploads
     * and what JoplixSync shows agree on id and type_.
     * @return array<string, string>
     */
    public static function meta(string $content): array {
        return JoplinItem::meta($content);
    }

    /** Converts Joplin's ISO timestamps (or millisecond numbers) to milliseconds. */
    public static function toMillis(string $value): int {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        if (ctype_digit($value)) {
            return (int)$value;
        }
        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return 0;
        }
        return (int)$date->format('Uv');
    }

    private function resourceFolder(): Folder {
        if ($this->resourceFolder === null) {
            if (!$this->folder->nodeExists(self::RESOURCE_DIR)) {
                $this->folder->newFolder(self::RESOURCE_DIR);
            }
            $node = $this->folder->get(self::RESOURCE_DIR);
            if (!$node instanceof Folder) {
                throw new SyncAbortException($this->t('.resource exists in the JoplixSync folder but is not a folder.'), 'badFolder');
            }
            $this->resourceFolder = $node;
        }
        return $this->resourceFolder;
    }

    /** The item file as it is right now (fresh lookup, never the node cached by scanLocal), or null. */
    private function current(string $name): ?File {
        try {
            $node = $this->folder->get($name);
        } catch (NotFoundException $e) {
            unset($this->md[$name]);
            return null;
        }
        if (!$node instanceof File) {
            return null;
        }
        $this->md[$name] = $node;
        return $node;
    }

    /** Runs $critical while holding the write lock shared with the API, when one was supplied. */
    private function guarded(callable $critical) {
        return $this->guard !== null ? ($this->guard)($critical) : $critical();
    }

    private function freshFile(Folder $folder, string $name): File {
        $node = $folder->get($name);
        if (!$node instanceof File) {
            throw new SyncAbortException($this->t('%1$s is not a file.', [$name]), 'badFolder');
        }
        return $node;
    }

    private function timeUp(): bool {
        $now = microtime(true);
        if ($now - $this->lockTime > self::LOCK_REFRESH_SECONDS) {
            $this->client->acquireSyncLock((string)$this->state['clientId']);
            $this->lockTime = $now;
        }
        return $now >= $this->deadline;
    }

    private function save(): void {
        if ($this->persist !== null) {
            ($this->persist)($this->state);
        }
    }
}
