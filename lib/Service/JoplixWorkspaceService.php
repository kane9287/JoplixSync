<?php
namespace OCA\Joplix\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;

class JoplixWorkspaceService {
    public const APP_VERSION = '2026.11.66';
    public const SCHEMA_VERSION = 1;

    private IRootFolder $rootFolder;
    private IConfig $config;
    private string $uid;

    public function __construct(IRootFolder $rootFolder, IConfig $config, string $uid) {
        $this->rootFolder = $rootFolder;
        $this->config = $config;
        $this->uid = $uid;
    }

    public function ensureWorkspace(): array {
        $userFolder = $this->rootFolder->getUserFolder($this->uid);
        $base = $this->folder($userFolder, '.joplix');
        foreach (['backups','cache','editing','logs','updates','updater'] as $name) {
            $this->folder($base, $name);
        }
        $this->removeLegacyCompatibilityState($base);
        $this->cleanup($base);
        $this->writeVersion($base);
        $this->ensureLock($base);
        return ['ok' => true, 'workspace' => '/.joplix'];
    }

    public function base(): Folder {
        $userFolder = $this->rootFolder->getUserFolder($this->uid);
        return $this->folder($userFolder, '.joplix');
    }

    public function child(string $name): Folder {
        return $this->folder($this->base(), trim($name, '/'));
    }

    private function removeLegacyCompatibilityState(Folder $base): void {
        foreach ([
            'compat_enabled', 'compat_repo', 'compat_branch', 'compat_check_interval',
            'compat_status', 'compat_last_checked_at', 'compat_last_release', 'compat_last_sha'
        ] as $key) {
            try { $this->config->deleteUserValue($this->uid, 'joplix', $key); } catch (\Throwable $e) {}
        }
        if ($base->nodeExists('joplin_compatibility_report.json')) {
            try { $base->get('joplin_compatibility_report.json')->delete(); } catch (\Throwable $e) {}
        }
    }

    private function cleanup(Folder $base): void {
        $now = time();
        $this->deleteOlderThan($this->folder($base, 'editing'), $now - 86400);
        $this->deleteOlderThan($this->folder($base, 'updater'), $now - 86400);
        $this->deleteOlderThan($this->folder($base, 'cache'), $now - 7 * 86400);
        $this->deleteOlderThan($this->folder($base, 'logs'), $now - 30 * 86400);

        $backupEnabled = $this->config->getUserValue($this->uid, 'joplix', 'update_backup_enabled', 'no') === 'yes';
        $updates = $this->folder($base, 'updates');
        if (!$backupEnabled) {
            $this->deleteOlderThan($updates, $now - 86400);
        } else {
            $this->keepNewest($this->folder($base, 'backups'), 5, $now - 30 * 86400);
        }
    }

    private function writeVersion(Folder $base): void {
        $old = [];
        if ($base->nodeExists('version.json')) {
            $node = $base->get('version.json');
            if ($node instanceof File) {
                $old = json_decode($node->getContent(), true) ?: [];
            }
        }
        $payload = [
            'app' => 'Joplix',
            'version' => self::APP_VERSION,
            'channel' => 'main',
            'previousVersion' => (string)($old['version'] ?? ''),
            'installedAt' => (string)($old['installedAt'] ?? gmdate('c')),
            'lastSuccessfulUpdate' => gmdate('c'),
            'schemaVersion' => self::SCHEMA_VERSION,
            'resourceEngine' => 2,
            'officeEngine' => 1,
            'uiEngine' => 2,
            'indexEngine' => 4,
        ];
        $this->writeJson($base, 'version.json', $payload);
    }

    private function ensureLock(Folder $base): void {
        if ($base->nodeExists('lock.json')) return;
        $this->writeJson($base, 'lock.json', [
            'update' => null,
            'index' => null,
            'cleanup' => null,
            'office' => new \stdClass(),
        ]);
    }

    private function writeJson(Folder $folder, string $name, array $payload): void {
        $content = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($folder->nodeExists($name)) {
            $node = $folder->get($name);
            if ($node instanceof File) { $node->putContent($content); return; }
            $node->delete();
        }
        $folder->newFile($name, $content);
    }

    private function folder(Folder $parent, string $name): Folder {
        if (!$parent->nodeExists($name)) $parent->newFolder($name);
        $node = $parent->get($name);
        if (!$node instanceof Folder) throw new \RuntimeException($name . ' ist kein Ordner');
        return $node;
    }

    private function deleteOlderThan(Folder $folder, int $cutoff): void {
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node->getMTime() < $cutoff) {
                try { $node->delete(); } catch (\Throwable $e) {}
            }
        }
    }

    private function keepNewest(Folder $folder, int $max, int $cutoff): void {
        $items = $folder->getDirectoryListing();
        usort($items, fn($a, $b) => $b->getMTime() <=> $a->getMTime());
        foreach ($items as $i => $node) {
            if ($i >= $max || $node->getMTime() < $cutoff) {
                try { $node->delete(); } catch (\Throwable $e) {}
            }
        }
    }
}
