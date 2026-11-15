<?php
namespace OCA\Joplix\Service;

use OCA\Joplix\AppInfo\Application;
use OCA\Joplix\Sync\JoplinServerClient;
use OCA\Joplix\Sync\JoplinServerException;
use OCA\Joplix\Sync\SyncEngine;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Per-user Joplin Server synchronisation: settings, credentials, state and
 * running the sync engine. Used by the API controller and the background job.
 *
 * Local servers: Nextcloud refuses connections to private addresses. An administrator lists the Joplin
 * servers in the own network that may be reached anyway (app setting sync_local_hosts, "host" or
 * "host:port"); only those get Nextcloud's allow_local_address, and only those may use plain http://.
 * Until 2026.11.81 this was one switch (sync_allow_local) that opened the whole internal network to every
 * user; localHosts() turns it into the list of hosts configured at that time, once.
 *
 * Deletions: JoplixWriteService reports the item files Joplix deleted; they are kept in the user setting
 * sync_deleted until the engine has deleted them on the server (see SyncEngine, "Deletions").
 */
class JoplixSyncService {
    private const APP_ID = Application::APP_ID;
    /** Confirmed deletions older than this are forgotten (the server copy then counts as "missing"). */
    private const DELETED_KEEP_DAYS = 90;

    private IRootFolder $rootFolder;
    private IConfig $config;
    private ICrypto $crypto;
    private IClientService $clientService;
    private IAppDataFactory $appDataFactory;
    private ILockingProvider $locking;
    private JoplixWriteLock $writeLock;
    private LoggerInterface $logger;
    private JoplixFolders $folders;
    private IFactory $l10nFactory;
    private IUserManager $userManager;
    private IDBConnection $db;

    public function __construct(IRootFolder $rootFolder, IConfig $config, ICrypto $crypto, IClientService $clientService, IAppDataFactory $appDataFactory, ILockingProvider $locking, JoplixWriteLock $writeLock, LoggerInterface $logger, JoplixFolders $folders, IFactory $l10nFactory, IUserManager $userManager, IDBConnection $db) {
        $this->rootFolder = $rootFolder;
        $this->config = $config;
        $this->crypto = $crypto;
        $this->clientService = $clientService;
        $this->appDataFactory = $appDataFactory;
        $this->locking = $locking;
        $this->writeLock = $writeLock;
        $this->logger = $logger;
        $this->folders = $folders;
        $this->l10nFactory = $l10nFactory;
        $this->userManager = $userManager;
        $this->db = $db;
    }

    // ------------------------------------------------------------- settings

    /** Settings and last status for the UI. Never contains the password. */
    public function status(string $uid, bool $isAdmin = false): array {
        $last = json_decode($this->get($uid, 'sync_status', '{}'), true);
        if (!is_array($last)) {
            $last = [];
        }
        $url = $this->get($uid, 'sync_server_url');
        $out = [
            'enabled' => $this->get($uid, 'sync_enabled', 'no') === 'yes',
            'server_url' => $url,
            'email' => $this->get($uid, 'sync_email'),
            'password_set' => $this->get($uid, 'sync_password') !== '',
            'configured' => $this->isConfigured($uid),
            'allow_local' => $url !== '' && $this->isLocalHost(JoplinServerClient::hostKey($url)),
            'last_run' => (int)($last['last_run'] ?? 0),
            'last_ok' => (int)($last['last_ok'] ?? 0),
            'last_status' => (string)($last['status'] ?? ''),
            'last_message' => (string)($last['message'] ?? ''),
            'last_code' => (string)($last['code'] ?? ''),
            'last_result' => is_array($last['result'] ?? null) ? $last['result'] : null,
            'rejected_items' => $this->rejectedItems($uid),
        ];
        if ($isAdmin) {
            $out['local_hosts'] = implode(', ', $this->localHosts());
        }
        return $out;
    }

    /**
     * @param array{enabled?: mixed, server_url?: mixed, email?: mixed, password?: mixed, local_hosts?: mixed} $values
     */
    public function saveSettings(string $uid, array $values, bool $isAdmin): void {
        $l = $this->l($uid);
        // The administrator's list first, so an admin can allow a local http:// server and use it in one step.
        if ($isAdmin && array_key_exists('local_hosts', $values) && $values['local_hosts'] !== null) {
            $hosts = [];
            foreach (preg_split('/[\s,;]+/', strtolower(trim((string)$values['local_hosts']))) ?: [] as $host) {
                if ($host === '') {
                    continue;
                }
                if (!preg_match('/^[a-z0-9.\-]+(?::\d{1,5})?$|^\[[0-9a-f:.]+\](?::\d{1,5})?$/D', $host)) {
                    throw new \InvalidArgumentException($l->t('Not a host name: %1$s. Enter host names or addresses, optionally with :port, separated by commas.', [$host]));
                }
                $hosts[$host] = true;
            }
            $this->config->setAppValue(self::APP_ID, 'sync_local_hosts', implode(',', array_keys($hosts)));
            $this->config->deleteAppValue(self::APP_ID, 'sync_allow_local');
        }
        $credentialsChanged = false;
        if (array_key_exists('server_url', $values) && $values['server_url'] !== null) {
            $url = trim((string)$values['server_url']);
            $url = $url === '' ? '' : JoplinServerClient::normalizeBaseUrl($url, $l, fn(string $key) => $this->isLocalHost($key));
            if ($url !== $this->get($uid, 'sync_server_url')) {
                $this->set($uid, 'sync_server_url', $url);
                $credentialsChanged = true;
            }
        }
        if (array_key_exists('email', $values) && $values['email'] !== null) {
            $email = trim((string)$values['email']);
            if (strlen($email) > 320 || preg_match('/[\x00-\x1f]/', $email)) {
                throw new \InvalidArgumentException($l->t('The e-mail address is not valid.'));
            }
            if ($email !== $this->get($uid, 'sync_email')) {
                $this->set($uid, 'sync_email', $email);
                $credentialsChanged = true;
            }
        }
        // An empty password means "keep the stored one".
        if (isset($values['password']) && (string)$values['password'] !== '') {
            $this->set($uid, 'sync_password', $this->crypto->encrypt((string)$values['password']));
            $credentialsChanged = true;
        }
        if ($credentialsChanged) {
            $this->config->deleteUserValue($uid, self::APP_ID, 'sync_session');
        }
        if (array_key_exists('enabled', $values) && $values['enabled'] !== null) {
            $on = in_array($values['enabled'], [true, 1, '1', 'yes', 'true'], true);
            if ($on && !$this->isConfigured($uid)) {
                throw new \InvalidArgumentException($l->t('Enter the server address, e-mail and password before enabling synchronisation.'));
            }
            $this->set($uid, 'sync_enabled', $on ? 'yes' : 'no');
        }
    }

    /** @return string[] user ids with synchronisation switched on */
    public function enabledUsers(): array {
        return $this->config->getUsersForUserValue(self::APP_ID, 'sync_enabled', 'yes');
    }

    /**
     * Remembers item files Joplix deleted, so the next run deletes them on the server (and only them).
     * @param string[] $names "<id>.md"
     */
    public function noteLocalDeletions(string $uid, array $names): void {
        if ($names === []) {
            return;
        }
        $log = $this->deletionLog($uid);
        foreach ($names as $name) {
            if (preg_match('/^[a-f0-9]{32}\.md$/D', $name)) {
                $log[$name] = time();
            }
        }
        $this->saveDeletionLog($uid, $log);
    }

    // ------------------------------------------------------------ operations

    /** Logs in and reads the sync information without changing anything. */
    public function test(string $uid): array {
        if (!$this->isConfigured($uid)) {
            throw new \InvalidArgumentException($this->l($uid)->t('Enter the server address, e-mail and password first.'));
        }
        $client = $this->client($uid, false);
        $client->login();
        $this->storeSession($uid, $client->sessionId());
        $raw = $client->getText('info.json');
        if ($raw === null) {
            return ['ok' => true, 'has_data' => false, 'e2ee' => false, 'version' => 0];
        }
        $info = json_decode($raw, true);
        $e2ee = is_array($info) ? ($info['e2ee'] ?? false) : false;
        if (is_array($e2ee)) {
            $e2ee = $e2ee['value'] ?? false;
        }
        return ['ok' => true, 'has_data' => true, 'e2ee' => (bool)$e2ee, 'version' => is_array($info) ? (int)($info['version'] ?? 0) : 0];
    }

    /**
     * Runs one time-boxed synchronisation.
     * @param string $missingAction '', 'delete' or 'restore' for synchronised files that disappeared (SyncEngine::run)
     * @param bool $interactive started by the user in the browser (may create the folder); false for the background job
     * @return array status is one of done, partial, busy, error, disabled
     */
    public function run(string $uid, int $budgetSeconds, string $missingAction = '', bool $interactive = true): array {
        if ($this->get($uid, 'sync_enabled', 'no') !== 'yes' || !$this->isConfigured($uid)) {
            return ['status' => 'disabled'];
        }
        $l = $this->l($uid);
        $lockKey = 'joplix/sync/' . md5($uid);
        try {
            $this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
        } catch (LockedException $e) {
            return ['status' => 'busy', 'message' => $l->t('A synchronisation is already running.')];
        }
        // Second guard for installations with Nextcloud's file locking switched off (no-op locking provider):
        // a lease in the user's settings. Not atomic, but it keeps a browser run and a cron run from routinely
        // running at the same time under the same client id.
        $lease = (int)$this->get($uid, 'sync_running_until', '0');
        if ($lease > time()) {
            $this->releaseLock($lockKey);
            return ['status' => 'busy', 'message' => $l->t('A synchronisation is already running.')];
        }
        $this->set($uid, 'sync_running_until', (string)(time() + $budgetSeconds + 60));
        $client = null;
        try {
            $path = $this->folders->joplinPath($uid);
            // Only a run the user started from the browser may create a missing folder; the background job
            // must not, or a mistyped or moved path would silently fill a new folder with the server content.
            $folder = $this->folders->joplinFolder($uid, $interactive);
            $stateFile = $this->stateFileName($uid, $path);
            $client = $this->client($uid, true);
            $deletionLog = $this->deletionLog($uid);
            $engine = new SyncEngine($folder, $client, $this->loadState($stateFile), function (array $state) use ($stateFile): void {
                $this->saveState($stateFile, $state);
            }, function (callable $critical) use ($uid) {
                // Per item, not per run: users keep saving while a long synchronisation is in progress.
                return $this->writeLock->run($uid, $critical, 20.0);
            }, array_keys($deletionLog), $l);
            $result = $engine->run($budgetSeconds, $missingAction);
            if ($engine->handledDeletes() !== []) {
                $this->forgetDeletions($uid, $engine->handledDeletes());
            }
            if (($result['pulled'] ?? 0) > 0 || ($result['deleted_local'] ?? 0) > 0 || ($result['conflicts'] ?? 0) > 0 || ($result['restored'] ?? 0) > 0) {
                $this->refreshIndex($uid, $path, $folder, $l);
            }
        } catch (\Throwable $e) {
            $code = $e instanceof JoplinServerException ? $e->serverCode() : '';
            $result = ['status' => 'error', 'message' => $e->getMessage(), 'code' => $code];
            $this->logger->warning('Joplix sync failed for {uid}: {message}', ['app' => self::APP_ID, 'uid' => $uid, 'message' => $e->getMessage(), 'exception' => $e]);
        } finally {
            if ($client !== null) {
                $this->storeSession($uid, $client->sessionId());
            }
            $this->config->deleteUserValue($uid, self::APP_ID, 'sync_running_until');
            $this->releaseLock($lockKey);
        }
        $this->recordStatus($uid, $result);
        return $result;
    }

    // -------------------------------------------------------------- internals

    /** Texts in the user's language (also in the background job, where there is no request language). */
    private function l(string $uid): IL10N {
        return $this->l10nFactory->get(self::APP_ID, $this->l10nFactory->getUserLanguage($this->userManager->get($uid)));
    }

    private function releaseLock(string $key): void {
        try {
            $this->locking->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
        } catch (\Throwable $e) {
            // Released at the end of the request in any case.
        }
    }

    private function isConfigured(string $uid): bool {
        return $this->get($uid, 'sync_server_url') !== '' && $this->get($uid, 'sync_email') !== '' && $this->get($uid, 'sync_password') !== '';
    }

    /** @return string[] hosts ("host" or "host:port") an administrator allowed although they are local */
    private function localHosts(): array {
        $value = $this->config->getAppValue(self::APP_ID, 'sync_local_hosts', "\0");
        if ($value === "\0") {
            $value = $this->migrateAllowLocal();
        }
        return array_values(array_filter(explode(',', $value), fn($h) => $h !== ''));
    }

    private function isLocalHost(string $key): bool {
        $hosts = $this->localHosts();
        // "host" in the list covers every port of it.
        return in_array($key, $hosts, true) || in_array(preg_replace('/:\d+$/', '', $key), $hosts, true);
    }

    /** One-time: the old global switch becomes the list of the server hosts users had set up at that time. */
    private function migrateAllowLocal(): string {
        $hosts = [];
        if ($this->config->getAppValue(self::APP_ID, 'sync_allow_local', 'no') === 'yes') {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->select('configvalue')->from('preferences')
                    ->where($qb->expr()->eq('appid', $qb->createNamedParameter(self::APP_ID)))
                    ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('sync_server_url')));
                $rows = $qb->executeQuery();
                while (($url = $rows->fetchOne()) !== false) {
                    if ((string)$url !== '') {
                        $hosts[JoplinServerClient::hostKey((string)$url)] = true;
                    }
                }
                $rows->closeCursor();
            } catch (\Throwable $e) {
                $this->logger->warning('Joplix could not convert sync_allow_local: {message}', ['app' => self::APP_ID, 'message' => $e->getMessage()]);
            }
        }
        $value = implode(',', array_keys($hosts));
        $this->config->setAppValue(self::APP_ID, 'sync_local_hosts', $value);
        $this->config->deleteAppValue(self::APP_ID, 'sync_allow_local');
        return $value;
    }

    private function client(string $uid, bool $reuseSession): JoplinServerClient {
        $l = $this->l($uid);
        try {
            $password = $this->crypto->decrypt($this->get($uid, 'sync_password'));
        } catch (\Throwable $e) {
            throw new \RuntimeException($l->t('The stored Joplin Server password can no longer be decrypted. Please enter it again.'));
        }
        $session = '';
        if ($reuseSession) {
            $stored = $this->get($uid, 'sync_session');
            if ($stored !== '') {
                try {
                    $session = $this->crypto->decrypt($stored);
                } catch (\Throwable $e) {
                    $session = '';
                }
            }
        }
        $url = $this->get($uid, 'sync_server_url');
        $local = $this->isLocalHost(JoplinServerClient::hostKey($url));
        if (str_starts_with($url, 'http://') && !$local) {
            // Stored before 2026.11.82 or allowed then; never send the password over plain HTTP to a non-local host.
            throw new \RuntimeException($l->t('Use an https:// address. Plain http:// would send your Joplin password unencrypted; it is only accepted for servers an administrator allowed as local servers.'));
        }
        return new JoplinServerClient($this->clientService, $url, $this->get($uid, 'sync_email'), $password, $local, $session, $l);
    }

    private function storeSession(string $uid, string $sessionId): void {
        if ($sessionId === '') {
            $this->config->deleteUserValue($uid, self::APP_ID, 'sync_session');
            return;
        }
        $stored = $this->get($uid, 'sync_session');
        if ($stored !== '') {
            try {
                if ($this->crypto->decrypt($stored) === $sessionId) {
                    return;
                }
            } catch (\Throwable $e) {
                // Replace the unreadable value below.
            }
        }
        $this->set($uid, 'sync_session', $this->crypto->encrypt($sessionId));
    }

    private function recordStatus(string $uid, array $result): void {
        $previous = json_decode($this->get($uid, 'sync_status', '{}'), true);
        $status = (string)($result['status'] ?? '');
        if ($status === 'busy') {
            return;
        }
        $now = time();
        $record = [
            'last_run' => $now,
            'last_ok' => in_array($status, ['done', 'partial'], true) ? $now : (int)(is_array($previous) ? ($previous['last_ok'] ?? 0) : 0),
            'status' => $status,
            'message' => substr((string)($result['message'] ?? ''), 0, 500),
            'code' => (string)($result['code'] ?? ''),
            'result' => array_intersect_key($result, array_flip(['pulled', 'pushed', 'deleted_local', 'deleted_remote', 'conflicts', 'rejected', 'missing', 'overwritten', 'encrypted', 'restored'])),
        ];
        $this->set($uid, 'sync_status', json_encode($record, JSON_UNESCAPED_SLASHES));
    }

    /** Items the server refused, with its reason and the item's title, for the settings pane (finding Y7). */
    private function rejectedItems(string $uid): array {
        try {
            if (!$this->isConfigured($uid)) {
                return [];
            }
            $state = $this->loadState($this->stateFileName($uid, $this->folders->joplinPath($uid)));
            $rejected = is_array($state['rejected'] ?? null) ? $state['rejected'] : [];
            if ($rejected === []) {
                return [];
            }
            $folder = null;
            try {
                $folder = $this->folders->joplinFolder($uid);
            } catch (\Throwable $e) {
            }
            $out = [];
            foreach (array_slice($rejected, 0, 20, true) as $name => $entry) {
                $title = '';
                try {
                    $node = $folder !== null ? $folder->get((string)$name) : null;
                    if ($node instanceof \OCP\Files\File) {
                        $title = mb_substr(trim(strtok(JoplinItem::parse($node->getContent())['body'], "\n") ?: ''), 0, 120);
                    }
                } catch (\Throwable $e) {
                }
                $out[] = ['name' => (string)$name, 'title' => $title, 'message' => (string)($entry['m'] ?? '')];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array<string,int> name => time of deletion */
    private function deletionLog(string $uid): array {
        $log = json_decode($this->get($uid, 'sync_deleted', '{}'), true);
        if (!is_array($log)) {
            return [];
        }
        $cutoff = time() - self::DELETED_KEEP_DAYS * 86400;
        return array_filter($log, fn($t) => (int)$t >= $cutoff);
    }

    private function saveDeletionLog(string $uid, array $log): void {
        if ($log === []) {
            $this->config->deleteUserValue($uid, self::APP_ID, 'sync_deleted');
            return;
        }
        arsort($log);
        $this->set($uid, 'sync_deleted', json_encode(array_slice($log, 0, 20000, true), JSON_UNESCAPED_SLASHES));
    }

    /** @param string[] $names */
    private function forgetDeletions(string $uid, array $names): void {
        $log = $this->deletionLog($uid);
        foreach ($names as $name) {
            unset($log[$name]);
        }
        $this->saveDeletionLog($uid, $log);
    }

    /**
     * Rebuilds the cached index after the engine changed files, so a background run does not leave it
     * stale until somebody opens Joplix. Labels in the index use the user's language.
     */
    private function refreshIndex(string $uid, string $path, Folder $folder, IL10N $l): void {
        try {
            $this->writeLock->run($uid, function () use ($uid, $path, $folder, $l): void {
                $index = (new JoplixModelService($folder, $l))->fullIndex();
                (new JoplixIndexService($this->appDataFactory->get(self::APP_ID), $uid, $path))->write($index);
            }, 20.0);
        } catch (\Throwable $e) {
            // The browser notices the changed files itself and rebuilds then.
            $this->logger->info('Joplix index refresh after sync failed for {uid}: {message}', ['app' => self::APP_ID, 'uid' => $uid, 'message' => $e->getMessage()]);
        }
    }

    /** State is kept in app data, not in the user's files, so it is neither versioned nor synced to desktops. */
    private function stateFolder(): ISimpleFolder {
        $appData = $this->appDataFactory->get(self::APP_ID);
        try {
            return $appData->getFolder('sync');
        } catch (NotFoundException $e) {
            return $appData->newFolder('sync');
        }
    }

    private function stateFileName(string $uid, string $path): string {
        $target = hash('sha256', $path . "\n" . $this->get($uid, 'sync_server_url') . "\n" . strtolower($this->get($uid, 'sync_email')));
        return md5($uid) . '_' . substr($target, 0, 16) . '.json';
    }

    private function loadState(string $fileName): array {
        try {
            $data = json_decode($this->stateFolder()->getFile($fileName)->getContent(), true);
            return is_array($data) ? $data : [];
        } catch (NotFoundException $e) {
            return [];
        }
    }

    private function saveState(string $fileName, array $state): void {
        $json = json_encode($state, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        $folder = $this->stateFolder();
        try {
            $folder->getFile($fileName)->putContent($json);
        } catch (NotFoundException $e) {
            $folder->newFile($fileName, $json);
        }
    }

    private function get(string $uid, string $key, string $default = ''): string {
        return (string)$this->config->getUserValue($uid, self::APP_ID, $key, $default);
    }

    private function set(string $uid, string $key, string $value): void {
        $this->config->setUserValue($uid, self::APP_ID, $key, $value);
    }
}
