<?php
namespace OCA\Joplix\Service;

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
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Per-user Joplin Server synchronisation: settings, credentials, state and
 * running the sync engine. Used by the API controller and the background job.
 */
class JoplixSyncService {
    private const APP_ID = 'joplix';
    private const DEFAULT_PATH = '/JoplinV2';

    private IRootFolder $rootFolder;
    private IConfig $config;
    private ICrypto $crypto;
    private IClientService $clientService;
    private IAppDataFactory $appDataFactory;
    private ILockingProvider $locking;
    private JoplixWriteLock $writeLock;
    private LoggerInterface $logger;

    public function __construct(IRootFolder $rootFolder, IConfig $config, ICrypto $crypto, IClientService $clientService, IAppDataFactory $appDataFactory, ILockingProvider $locking, JoplixWriteLock $writeLock, LoggerInterface $logger) {
        $this->rootFolder = $rootFolder;
        $this->config = $config;
        $this->crypto = $crypto;
        $this->clientService = $clientService;
        $this->appDataFactory = $appDataFactory;
        $this->locking = $locking;
        $this->writeLock = $writeLock;
        $this->logger = $logger;
    }

    // ------------------------------------------------------------- settings

    /** Settings and last status for the UI. Never contains the password. */
    public function status(string $uid): array {
        $last = json_decode($this->get($uid, 'sync_status', '{}'), true);
        if (!is_array($last)) {
            $last = [];
        }
        return [
            'enabled' => $this->get($uid, 'sync_enabled', 'no') === 'yes',
            'server_url' => $this->get($uid, 'sync_server_url'),
            'email' => $this->get($uid, 'sync_email'),
            'password_set' => $this->get($uid, 'sync_password') !== '',
            'configured' => $this->isConfigured($uid),
            'allow_local' => $this->allowLocal(),
            'last_run' => (int)($last['last_run'] ?? 0),
            'last_ok' => (int)($last['last_ok'] ?? 0),
            'last_status' => (string)($last['status'] ?? ''),
            'last_message' => (string)($last['message'] ?? ''),
            'last_result' => is_array($last['result'] ?? null) ? $last['result'] : null,
        ];
    }

    /**
     * @param array{enabled?: mixed, server_url?: mixed, email?: mixed, password?: mixed, allow_local?: mixed} $values
     */
    public function saveSettings(string $uid, array $values, bool $isAdmin): void {
        $credentialsChanged = false;
        if (array_key_exists('server_url', $values) && $values['server_url'] !== null) {
            $url = trim((string)$values['server_url']);
            $url = $url === '' ? '' : JoplinServerClient::normalizeBaseUrl($url);
            if ($url !== $this->get($uid, 'sync_server_url')) {
                $this->set($uid, 'sync_server_url', $url);
                $credentialsChanged = true;
            }
        }
        if (array_key_exists('email', $values) && $values['email'] !== null) {
            $email = trim((string)$values['email']);
            if (strlen($email) > 320 || preg_match('/[\x00-\x1f]/', $email)) {
                throw new \InvalidArgumentException('The e-mail address is not valid.');
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
                throw new \InvalidArgumentException('Enter the server address, e-mail and password before enabling synchronisation.');
            }
            $this->set($uid, 'sync_enabled', $on ? 'yes' : 'no');
        }
        if ($isAdmin && array_key_exists('allow_local', $values) && $values['allow_local'] !== null) {
            $on = in_array($values['allow_local'], [true, 1, '1', 'yes', 'true'], true);
            $this->config->setAppValue(self::APP_ID, 'sync_allow_local', $on ? 'yes' : 'no');
        }
    }

    /** @return string[] user ids with synchronisation switched on */
    public function enabledUsers(): array {
        return $this->config->getUsersForUserValue(self::APP_ID, 'sync_enabled', 'yes');
    }

    // ------------------------------------------------------------ operations

    /** Logs in and reads the sync information without changing anything. */
    public function test(string $uid): array {
        if (!$this->isConfigured($uid)) {
            throw new \InvalidArgumentException('Enter the server address, e-mail and password first.');
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
     * @return array status is one of done, partial, busy, error, disabled
     */
    public function run(string $uid, int $budgetSeconds, bool $allowMassDelete = false): array {
        if ($this->get($uid, 'sync_enabled', 'no') !== 'yes' || !$this->isConfigured($uid)) {
            return ['status' => 'disabled'];
        }
        $lockKey = 'joplix/sync/' . md5($uid);
        try {
            $this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
        } catch (LockedException $e) {
            return ['status' => 'busy', 'message' => 'A synchronisation is already running.'];
        }
        $client = null;
        try {
            $path = $this->joplinPath($uid);
            $folder = $this->joplinFolder($uid, $path);
            $stateFile = $this->stateFileName($uid, $path);
            $client = $this->client($uid, true);
            $engine = new SyncEngine($folder, $client, $this->loadState($stateFile), function (array $state) use ($stateFile): void {
                $this->saveState($stateFile, $state);
            }, function (callable $critical) use ($uid) {
                // Per item, not per run: users keep saving while a long synchronisation is in progress.
                return $this->writeLock->run($uid, $critical, 20.0);
            });
            $result = $engine->run($budgetSeconds, $allowMassDelete);
        } catch (\Throwable $e) {
            $code = $e instanceof JoplinServerException ? $e->serverCode() : '';
            $result = ['status' => 'error', 'message' => $e->getMessage(), 'code' => $code];
            $this->logger->warning('Joplix sync failed for {uid}: {message}', ['app' => self::APP_ID, 'uid' => $uid, 'message' => $e->getMessage(), 'exception' => $e]);
        } finally {
            if ($client !== null) {
                $this->storeSession($uid, $client->sessionId());
            }
            try {
                $this->locking->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
            } catch (\Throwable $e) {
                // Released at the end of the request in any case.
            }
        }
        $this->recordStatus($uid, $result);
        return $result;
    }

    // -------------------------------------------------------------- internals

    private function isConfigured(string $uid): bool {
        return $this->get($uid, 'sync_server_url') !== '' && $this->get($uid, 'sync_email') !== '' && $this->get($uid, 'sync_password') !== '';
    }

    private function allowLocal(): bool {
        return $this->config->getAppValue(self::APP_ID, 'sync_allow_local', 'no') === 'yes';
    }

    private function client(string $uid, bool $reuseSession): JoplinServerClient {
        try {
            $password = $this->crypto->decrypt($this->get($uid, 'sync_password'));
        } catch (\Throwable $e) {
            throw new \RuntimeException('The stored Joplin Server password can no longer be decrypted. Please enter it again.');
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
        return new JoplinServerClient($this->clientService, $this->get($uid, 'sync_server_url'), $this->get($uid, 'sync_email'), $password, $this->allowLocal(), $session);
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
            'result' => array_intersect_key($result, array_flip(['pulled', 'pushed', 'deleted_local', 'deleted_remote', 'conflicts', 'rejected'])),
        ];
        $this->set($uid, 'sync_status', json_encode($record, JSON_UNESCAPED_SLASHES));
    }

    private function joplinPath(string $uid): string {
        $path = '/' . trim($this->get($uid, 'joplin_path', self::DEFAULT_PATH), '/');
        return $path === '/' ? self::DEFAULT_PATH : $path;
    }

    private function joplinFolder(string $uid, string $path): Folder {
        $node = $this->rootFolder->getUserFolder($uid);
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \RuntimeException('The Joplix folder path is not valid: ' . $path);
            }
            try {
                $child = $node->get($segment);
            } catch (NotFoundException $e) {
                $child = $node->newFolder($segment);
            }
            if (!$child instanceof Folder) {
                throw new \RuntimeException('The Joplix folder path is not a folder: ' . $path);
            }
            $node = $child;
        }
        return $node;
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
