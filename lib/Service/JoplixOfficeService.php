<?php
namespace OCA\JoplixSync\Service;

use OCA\JoplixSync\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IL10N;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Office editing of attachments.
 *
 * start() copies the attachment to /.joplix/editing/<session>/<name>, a Nextcloud file an Office app can open
 * by id. import() writes the working copy back into the attachment when its content changed. It runs
 *  - on the server whenever the working copy is written (OfficeFileWrittenListener), so changes saved in the
 *    editor reach the note even when the JoplixSync tab is closed, and
 *  - from the browser's status poll, which mostly reports what the listener already did.
 * Sessions are kept in the user setting office_sessions: resource id => session.
 *
 * cleanup() removes session folders older than a day, but only once their content has been imported; a
 * working copy that cannot be imported (the attachment was changed elsewhere meanwhile) is kept for
 * KEEP_UNIMPORTED_DAYS so the edit can still be recovered from the Files app.
 */
class JoplixOfficeService {
    private const SESSION_DAYS = 1;
    public const KEEP_UNIMPORTED_DAYS = 30;

    private IRootFolder $rootFolder;
    private IConfig $config;
    private JoplixFolders $folders;
    private JoplixWriteLock $writeLock;
    private IFactory $l10nFactory;
    private LoggerInterface $logger;

    public function __construct(IRootFolder $rootFolder, IConfig $config, JoplixFolders $folders, JoplixWriteLock $writeLock, IFactory $l10nFactory, LoggerInterface $logger) {
        $this->rootFolder = $rootFolder;
        $this->config = $config;
        $this->folders = $folders;
        $this->writeLock = $writeLock;
        $this->l10nFactory = $l10nFactory;
        $this->logger = $logger;
    }

    /** @return array{session_id:string,file_id:int,file_name:string} */
    public function start(string $uid, string $resourceId, File $attachment, string $title): array {
        $editing = $this->editingFolder($uid);
        $this->cleanup($uid, $editing);
        $binary = $attachment->getContent();
        $sessionId = bin2hex(random_bytes(16));
        $sessionFolder = $editing->newFolder($sessionId);
        $file = $sessionFolder->newFile($title, $binary);
        $hash = hash('sha256', $binary);
        $this->writeLock->run($uid, function () use ($uid, $resourceId, $sessionId, $title, $file, $hash): void {
            $sessions = $this->sessions($uid);
            $sessions[$resourceId] = ['session_id' => $sessionId, 'resource_id' => $resourceId, 'file_name' => $title, 'file_id' => $file->getId(), 'working_hash' => $hash, 'resource_hash' => $hash, 'started_at' => time(), 'last_import_at' => 0];
            $this->saveSessions($uid, $sessions);
        });
        return ['session_id' => $sessionId, 'file_id' => (int)$file->getId(), 'file_name' => $title];
    }

    /**
     * Imports the working copy of $resourceId when it changed since the last import.
     * @return array{active:bool,error?:string,updated?:bool,already_applied?:bool,file_name?:string,last_import_at?:int,sha256?:string,working_sha256?:string,working_mtime?:int,working_size?:int}
     */
    public function import(string $uid, string $resourceId): array {
        return $this->writeLock->run($uid, function () use ($uid, $resourceId): array {
            $sessions = $this->sessions($uid);
            if (!isset($sessions[$resourceId])) {
                return ['active' => false];
            }
            $session = $sessions[$resourceId];
            $file = $this->workingFile($uid, $session);
            if (is_string($file)) {
                return ['active' => false, 'error' => $file];
            }
            $binary = $file->getContent();
            $hash = hash('sha256', $binary);
            $updated = false;
            $alreadyApplied = false;
            if (!hash_equals((string)$session['working_hash'], $hash)) {
                $writer = new JoplixWriteService(new JoplixModelService($this->folders->joplinFolder($uid), $this->l()), $this->l());
                $result = $writer->updateResourceBinary($resourceId, $binary, (string)$session['resource_hash']);
                $alreadyApplied = (bool)($result['already_applied'] ?? false);
                $updated = !$alreadyApplied;
                $session['working_hash'] = $hash;
                $session['resource_hash'] = $result['sha256'];
                $session['last_import_at'] = time();
                $sessions[$resourceId] = $session;
                $this->saveSessions($uid, $sessions);
            }
            return ['active' => true, 'updated' => $updated, 'already_applied' => $alreadyApplied, 'file_name' => (string)$session['file_name'], 'last_import_at' => (int)($session['last_import_at'] ?? 0), 'sha256' => (string)$session['resource_hash'], 'working_sha256' => $hash, 'working_mtime' => (int)$file->getMTime(), 'working_size' => (int)$file->getSize()];
        }, 20.0);
    }

    /** Called for every file written below /<uid>/files/.joplix/editing/<session>/. */
    public function importBySession(string $uid, string $sessionId): void {
        foreach ($this->sessions($uid) as $resourceId => $session) {
            if (($session['session_id'] ?? '') === $sessionId) {
                $this->import($uid, (string)$resourceId);
                return;
            }
        }
    }

    public function cleanup(string $uid, ?Folder $editing = null): void {
        try {
            $editing ??= $this->editingFolder($uid);
            $bySession = [];
            foreach ($this->sessions($uid) as $resourceId => $session) {
                $bySession[(string)($session['session_id'] ?? '')] = (string)$resourceId;
            }
            $now = time();
            foreach ($editing->getDirectoryListing() as $node) {
                if (!$node instanceof Folder || $node->getMTime() >= $now - self::SESSION_DAYS * 86400) {
                    continue;
                }
                $resourceId = $bySession[$node->getName()] ?? null;
                $imported = true;
                if ($resourceId !== null) {
                    try {
                        $state = $this->import($uid, $resourceId);
                        $imported = empty($state['error']) || !$state['active'];
                    } catch (\Throwable $e) {
                        $imported = false;
                        $this->logger->warning('JoplixSync could not import the Office working copy {session} of {uid}: {message}', ['app' => Application::APP_ID, 'uid' => $uid, 'session' => $node->getName(), 'message' => $e->getMessage()]);
                    }
                }
                if (!$imported && $node->getMTime() >= $now - self::KEEP_UNIMPORTED_DAYS * 86400) {
                    continue;
                }
                try { $node->delete(); } catch (\Throwable $e) {}
                if ($resourceId !== null) {
                    $this->writeLock->run($uid, function () use ($uid, $resourceId, $node): void {
                        $sessions = $this->sessions($uid);
                        if (($sessions[$resourceId]['session_id'] ?? '') === $node->getName()) {
                            unset($sessions[$resourceId]);
                            $this->saveSessions($uid, $sessions);
                        }
                    });
                }
            }
        } catch (\Throwable $e) {
            // Cleanup is best effort.
        }
    }

    /** @return File|string the working file, or the reason why there is none */
    private function workingFile(string $uid, array $session) {
        $sessionId = (string)($session['session_id'] ?? '');
        $editing = $this->editingFolder($uid);
        if ($sessionId === '' || !$editing->nodeExists($sessionId)) {
            return $this->l()->t('The editing session is missing');
        }
        $folder = $editing->get($sessionId);
        if (!$folder instanceof Folder || !$folder->nodeExists((string)$session['file_name'])) {
            return $this->l()->t('The working file is missing');
        }
        $file = $folder->get((string)$session['file_name']);
        return $file instanceof File ? $file : $this->l()->t('The working file is not valid');
    }

    private function editingFolder(string $uid): Folder {
        return (new JoplixWorkspaceService($this->rootFolder, $this->config, $uid))->editingFolder();
    }

    private function sessions(string $uid): array {
        $sessions = json_decode($this->config->getUserValue($uid, Application::APP_ID, 'office_sessions', '{}'), true);
        return is_array($sessions) ? $sessions : [];
    }

    private function saveSessions(string $uid, array $sessions): void {
        $this->config->setUserValue($uid, Application::APP_ID, 'office_sessions', json_encode((object)$sessions, JSON_UNESCAPED_SLASHES));
    }

    private function l(): IL10N {
        return $this->l10nFactory->get(Application::APP_ID);
    }
}
