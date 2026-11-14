<?php
namespace OCA\Joplix\Sync;

use OCP\Files\File;
use OCP\Files\Folder;

/**
 * Two-way synchronisation between a Nextcloud folder holding Joplin sync
 * items (<id>.md plus .resource/<id>) and a Joplin Server account.
 *
 * The folder is Joplix's working copy and, because every write goes through
 * the Nextcloud files API, also a versioned backup of the server content.
 *
 * A run is time-boxed and resumable: when the budget is used up it returns
 * "partial" and the next run continues where this one stopped.
 *
 * State layout:
 *   clientId     stable id used for the server-side sync lock
 *   cursor       delta cursor of the last fully processed page
 *   items        name => ['e' => local etag, 'r' => remote updated_time (ms), 'b' => blob_updated_time]
 *   blobs        resource id => local etag of the blob at last sync
 *   blobPending  resource id => 1, blobs that were not yet on the server
 *   rejected     name => ['e' => local etag, 'm' => message], uploads the server refused
 *   resync       true while a full resynchronisation still has to be reconciled
 */
class SyncEngine {
    private const MD_PATTERN = '/^[a-f0-9]{32}\.md$/';
    private const ID_PATTERN = '/^[a-f0-9]{32}$/';
    private const RESOURCE_DIR = '.resource';
    private const SUPPORTED_SYNC_VERSION = 3;
    private const LOCK_REFRESH_SECONDS = 60;
    /** Deleting more than this many items on the server in one go needs confirmation... */
    private const MASS_DELETE_MIN = 5;
    /** ...when it is also more than this share of everything known. */
    private const MASS_DELETE_RATIO = 0.25;

    private Folder $folder;
    private JoplinServerClient $client;
    private array $state;
    /** @var callable|null */
    private $persist;

    /** @var array<string, File> */
    private array $md = [];
    /** @var array<string, File> */
    private array $blobs = [];
    private ?Folder $resourceFolder = null;
    /** True when the folder held no items at the start of the run although some were synchronised before. */
    private bool $folderWasEmptied = false;

    private float $deadline = 0.0;
    private float $lockTime = 0.0;
    private array $stats = [];

    /**
     * @param callable|null $persist called with the current state at safe points so progress survives an abort
     */
    public function __construct(Folder $folder, JoplinServerClient $client, array $state, ?callable $persist = null) {
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
    }

    public function state(): array {
        return $this->state;
    }

    /**
     * @return array{status: string, message?: string, code?: string, pulled: int, pushed: int, deleted_local: int, deleted_remote: int, conflicts: int, rejected: int}
     */
    public function run(int $budgetSeconds, bool $allowMassDelete = false): array {
        $this->deadline = microtime(true) + max(1, $budgetSeconds);
        $this->stats = ['pulled' => 0, 'pushed' => 0, 'deleted_local' => 0, 'deleted_remote' => 0, 'conflicts' => 0, 'rejected' => 0];
        $clientId = (string)$this->state['clientId'];

        try {
            $this->client->acquireSyncLock($clientId);
        } catch (JoplinServerException $e) {
            if (in_array($e->serverCode(), ['hasExclusiveLock', 'hasSyncLock'], true)) {
                return ['status' => 'busy', 'message' => 'Another Joplin client currently holds a lock on the server.'] + $this->stats;
            }
            throw $e;
        }
        $this->lockTime = microtime(true);

        try {
            $this->checkSyncTarget();
            $this->scanLocal();
            if ($this->folderWasEmptied && !$allowMassDelete) {
                // An emptied, moved or unmounted folder must never wipe the server.
                throw new SyncAbortException(
                    'The Nextcloud folder no longer contains any of the ' . count($this->state['items']) . ' synchronised items. '
                    . 'Nothing was deleted on the server. Confirm the synchronisation to delete them there, or restore the folder.',
                    'massDelete'
                );
            }
            $complete = $this->pull();
            if ($complete) {
                $complete = $this->push($allowMassDelete);
            }
            return ['status' => $complete ? 'done' : 'partial'] + $this->stats;
        } catch (SyncAbortException $e) {
            return ['status' => 'error', 'message' => $e->getMessage(), 'code' => $e->reason()] + $this->stats;
        } finally {
            $this->save();
            try {
                $this->client->releaseSyncLock($clientId);
            } catch (\Throwable $e) {
                // The lock expires on its own after three minutes.
            }
        }
    }

    // ---------------------------------------------------------------- setup

    private function checkSyncTarget(): void {
        $raw = $this->client->getText('info.json');
        if ($raw === null) {
            throw new SyncAbortException('The Joplin Server account holds no sync data yet. Synchronise once from a Joplin app first.', 'emptyTarget');
        }
        $info = json_decode($raw, true);
        if (!is_array($info)) {
            throw new SyncAbortException('The sync information on the server (info.json) is not readable.', 'badTarget');
        }
        $version = (int)($info['version'] ?? 0);
        if ($version !== self::SUPPORTED_SYNC_VERSION) {
            throw new SyncAbortException('Unsupported sync target version ' . $version . ' (Joplix supports version ' . self::SUPPORTED_SYNC_VERSION . ').', 'badVersion');
        }
        $e2ee = $info['e2ee'] ?? false;
        if (is_array($e2ee)) {
            $e2ee = $e2ee['value'] ?? false;
        }
        if ($e2ee) {
            throw new SyncAbortException('End-to-end encryption is enabled on this Joplin account. Joplix cannot read encrypted notes.', 'e2ee');
        }
        // Keep a copy for the backup. Joplix never writes info.json to the server.
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
        $node = $this->md[$name] ?? null;
        $known = $this->state['items'][$name] ?? null;
        $localModified = $node !== null && ($known === null || (string)($known['e'] ?? '') !== $node->getEtag());

        if ((int)($change['type'] ?? 0) === 3) {
            if ($node !== null && $localModified) {
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
                $node->delete();
                unset($this->md[$name]);
                $this->stats['deleted_local']++;
            }
            if (isset($this->blobs[$id])) {
                $this->blobs[$id]->delete();
                unset($this->blobs[$id]);
            }
            unset($this->state['items'][$name], $this->state['blobs'][$id], $this->state['blobPending'][$id], $this->state['rejected'][$name]);
            return;
        }

        $remoteTime = (int)($change['jop_updated_time'] ?? 0);
        if ($known !== null && $remoteTime > 0 && (int)($known['r'] ?? 0) === $remoteTime) {
            // Already had this version (typically our own upload echoed back). If the
            // file is gone it was deleted here, and the push phase deletes it remotely.
            return;
        }

        $remote = $this->client->getText($name);
        if ($remote === null) {
            // Gone again in the meantime; a delete change follows.
            return;
        }
        $meta = self::meta($remote);
        if ($remoteTime === 0) {
            $remoteTime = self::toMillis((string)($meta['updated_time'] ?? ''));
        }

        if ($localModified) {
            $local = $node->getContent();
            $localMeta = self::meta($local);
            $sameVersion = $local === $remote
                || (($localMeta['updated_time'] ?? '') !== '' && self::toMillis((string)$localMeta['updated_time']) === $remoteTime);
            if (!$sameVersion && ($localMeta['type_'] ?? '') === '1') {
                // Both sides changed the note. The server version wins; the local
                // edit survives as a conflict note, as Joplin itself does.
                $this->writeConflictCopy($local, $id);
                $this->stats['conflicts']++;
            }
        }

        if ($node !== null) {
            if ($node->getContent() !== $remote) {
                $node->putContent($remote);
            }
        } else {
            $this->folder->newFile($name, $remote);
        }
        $node = $this->freshFile($this->folder, $name);
        $this->md[$name] = $node;

        $record = ['e' => $node->getEtag(), 'r' => $remoteTime];
        if (($meta['type_'] ?? '') === '4') {
            $blobTime = (string)($meta['blob_updated_time'] ?? '');
            $record['b'] = $blobTime;
            $blobChanged = $known === null || (string)($known['b'] ?? '') !== $blobTime;
            if (!isset($this->blobs[$id]) || $blobChanged) {
                $this->fetchBlob($id);
            }
        }
        $this->state['items'][$name] = $record;
        unset($this->state['rejected'][$name]);
        $this->stats['pulled']++;
    }

    private function fetchBlob(string $id): void {
        if (!preg_match(self::ID_PATTERN, $id)) {
            unset($this->state['blobPending'][$id]);
            return;
        }
        $stream = $this->client->getStream(self::RESOURCE_DIR . '/' . $id);
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
        foreach ($this->md as $name => $node) {
            if (isset($remote[$name])) {
                continue;
            }
            $known = $this->state['items'][$name] ?? null;
            if ($known === null || (string)($known['e'] ?? '') !== $node->getEtag()) {
                // New or edited here: the push phase uploads it.
                unset($this->state['items'][$name]);
                continue;
            }
            $id = substr($name, 0, 32);
            $node->delete();
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
    private function push(bool $allowMassDelete): bool {
        $gone = [];
        foreach ($this->state['items'] as $name => $_) {
            if (!isset($this->md[$name])) {
                $gone[] = (string)$name;
            }
        }
        $knownCount = count($this->state['items']);
        if (!$allowMassDelete && $gone !== []) {
            if (count($gone) > self::MASS_DELETE_MIN && count($gone) > $knownCount * self::MASS_DELETE_RATIO) {
                throw new SyncAbortException(
                    count($gone) . ' of ' . $knownCount . ' synchronised items are missing from the Nextcloud folder. '
                    . 'Nothing was deleted on the server. Confirm the synchronisation to delete them there, or restore the folder.',
                    'massDelete'
                );
            }
        }

        foreach ($this->md as $name => $node) {
            if ($this->timeUp()) {
                return false;
            }
            $etag = $node->getEtag();
            $known = $this->state['items'][$name] ?? null;
            if ($known !== null && (string)($known['e'] ?? '') === $etag) {
                continue;
            }
            $rejected = $this->state['rejected'][$name] ?? null;
            if ($rejected !== null && (string)($rejected['e'] ?? '') === $etag) {
                continue;
            }
            $this->uploadItem((string)$name, $node, $etag);
        }

        foreach ($gone as $name) {
            if ($this->timeUp()) {
                return false;
            }
            $id = substr($name, 0, 32);
            $this->client->delete($name);
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

    private function uploadItem(string $name, File $node, string $etag): void {
        $id = substr($name, 0, 32);
        $content = $node->getContent();
        $meta = self::meta($content);
        $type = (string)($meta['type_'] ?? '');
        if ($type === '' || (string)($meta['id'] ?? '') !== $id) {
            $this->reject($name, $etag, 'Not a valid Joplin item (id or type_ missing).');
            return;
        }
        try {
            if ($type === '4' && isset($this->blobs[$id])) {
                $blob = $this->blobs[$id];
                $blobEtag = $blob->getEtag();
                if ((string)($this->state['blobs'][$id] ?? '') !== $blobEtag) {
                    // Blob first, so no client ever sees a resource without its file.
                    $stream = $blob->fopen('rb');
                    try {
                        $this->client->put(self::RESOURCE_DIR . '/' . $id, $stream);
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
     * Reads the metadata block of a serialized Joplin item: the "key: value"
     * lines after the last blank line (or the whole text for body-less items).
     * @return array<string, string>
     */
    public static function meta(string $content): array {
        $content = rtrim(str_replace("\r\n", "\n", $content));
        $pos = strrpos($content, "\n\n");
        $block = $pos === false ? $content : substr($content, $pos + 2);
        $out = [];
        foreach (explode("\n", $block) as $line) {
            if (preg_match('/^([A-Za-z0-9_]+):\s?(.*)$/', $line, $m)) {
                $out[$m[1]] = $m[2];
            }
        }
        return $out;
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
                throw new SyncAbortException('.resource exists in the Joplix folder but is not a folder.', 'badFolder');
            }
            $this->resourceFolder = $node;
        }
        return $this->resourceFolder;
    }

    private function freshFile(Folder $folder, string $name): File {
        $node = $folder->get($name);
        if (!$node instanceof File) {
            throw new SyncAbortException($name . ' is not a file.', 'badFolder');
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
