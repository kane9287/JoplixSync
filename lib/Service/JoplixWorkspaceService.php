<?php
namespace OCA\JoplixSync\Service;

use OCA\JoplixSync\AppInfo\Application;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;

/**
 * Owner of the folder /.joplix in the user's files. Since 2026.11.82 it only holds what has to be a
 * Nextcloud file:
 *   editing/<session>/   working copies opened in an Office editor (see JoplixOfficeService)
 *   updates/             default folder for development packages (admins only, JoplixDevUpdateService)
 * Nothing creates the folder until one of these is needed, so opening JoplixSync writes nothing into the user's
 * files. Index and synchronisation state live in app data (JoplixIndexService, JoplixSyncService).
 *
 * Older versions also wrote version.json and lock.json on every page load and kept the folders backups,
 * cache, logs and updater, which nothing read or wrote. removeLegacy() deletes them once per user.
 */
class JoplixWorkspaceService {
    /** Bump when removeLegacy() learns about more leftovers. */
    private const LEGACY_CLEANUP = '1';

    private IRootFolder $rootFolder;
    private IConfig $config;
    private string $uid;

    public function __construct(IRootFolder $rootFolder, IConfig $config, string $uid) {
        $this->rootFolder = $rootFolder;
        $this->config = $config;
        $this->uid = $uid;
    }

    /** .joplix/editing, created on demand. */
    public function editingFolder(): Folder {
        return $this->folder($this->folder($this->rootFolder->getUserFolder($this->uid), '.joplix'), 'editing');
    }

    /** Deletes the files and settings older versions left behind. Runs once per user; cheap afterwards. */
    public function removeLegacy(): void {
        if ($this->config->getUserValue($this->uid, Application::APP_ID, 'workspace_cleanup', '') === self::LEGACY_CLEANUP) {
            return;
        }
        $this->config->setUserValue($this->uid, Application::APP_ID, 'workspace_cleanup', self::LEGACY_CLEANUP);
        foreach ([
            'compat_enabled', 'compat_repo', 'compat_branch', 'compat_check_interval',
            'compat_status', 'compat_last_checked_at', 'compat_last_release', 'compat_last_sha', 'update_source',
        ] as $key) {
            try { $this->config->deleteUserValue($this->uid, Application::APP_ID, $key); } catch (\Throwable $e) {}
        }
        try {
            $userFolder = $this->rootFolder->getUserFolder($this->uid);
            if (!$userFolder->nodeExists('.joplix')) {
                return;
            }
            $base = $userFolder->get('.joplix');
            if (!$base instanceof Folder) {
                return;
            }
            foreach (['version.json', 'lock.json', 'joplin_compatibility_report.json', 'backups', 'cache', 'logs', 'updater'] as $name) {
                if ($base->nodeExists($name)) {
                    try { $base->get($name)->delete(); } catch (\Throwable $e) {}
                }
            }
        } catch (\Throwable $e) {
            // Leftovers are harmless.
        }
    }

    private function folder(Folder $parent, string $name): Folder {
        if (!$parent->nodeExists($name)) {
            $parent->newFolder($name);
        }
        $node = $parent->get($name);
        if (!$node instanceof Folder) {
            throw new \RuntimeException(\OCP\Server::get(\OCP\L10N\IFactory::class)->get(Application::APP_ID)->t('%1$s is not a folder', [$name]));
        }
        return $node;
    }
}
