<?php
namespace OCA\JoplixSync\Service;

use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Cached notebook/note index of one user and one Joplin folder.
 *
 * Stored in app data (folder "index", file "<md5 uid>_<hash of folder path>.json"), next to the sync
 * state: the index repeats the text of every note for the search, so it must not sit in the user's files,
 * where it would be versioned on each save and synced to desktop clients. Until 2026.11.78 it was
 * ".joplix/index_<hash>.json" in the user's files; write() removes those.
 */
class JoplixIndexService {
    private IAppData $appData;
    private string $fileName;
    private ?Folder $userFolder;

    public function __construct(IAppData $appData, string $uid, string $joplinPath, ?Folder $userFolder = null) {
        $this->appData = $appData;
        $this->fileName = self::userPrefix($uid) . substr(hash('sha256', $joplinPath), 0, 16) . '.json';
        $this->userFolder = $userFolder;
    }

    /** Start of every app-data file name that belongs to $uid (also used for the sync state). */
    public static function userPrefix(string $uid): string {
        return md5($uid) . '_';
    }

    private function folder(): ISimpleFolder {
        try {
            return $this->appData->getFolder('index');
        } catch (NotFoundException $e) {
            return $this->appData->newFolder('index');
        }
    }

    public function read(): ?array {
        try {
            $data = json_decode($this->folder()->getFile($this->fileName)->getContent(), true);
        } catch (NotFoundException $e) {
            return null;
        }
        return is_array($data) ? $data : null;
    }

    public function write(array $index): void {
        $json = json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        $folder = $this->folder();
        try {
            $folder->getFile($this->fileName)->putContent($json);
        } catch (NotFoundException $e) {
            $folder->newFile($this->fileName, $json);
        }
        $this->removeLegacyFiles();
    }

    public function status(string $currentFingerprint): array {
        $idx = $this->read();
        if (!$idx) {
            return ['exists' => false, 'current' => false, 'built_at' => '', 'fingerprint' => ''];
        }
        return ['exists' => true, 'current' => (($idx['fingerprint'] ?? '') === $currentFingerprint), 'built_at' => $idx['built_at'] ?? '', 'fingerprint' => $idx['fingerprint'] ?? ''];
    }

    private function removeLegacyFiles(): void {
        if ($this->userFolder === null) {
            return;
        }
        try {
            if (!$this->userFolder->nodeExists('.joplix')) {
                return;
            }
            $dir = $this->userFolder->get('.joplix');
            if (!$dir instanceof Folder) {
                return;
            }
            foreach ($dir->getDirectoryListing() as $node) {
                if (preg_match('/^index_[a-f0-9]{16}\.json$/D', $node->getName())) {
                    $node->delete();
                }
            }
        } catch (\Throwable $e) {
            // Leftovers are harmless; the next write tries again.
        }
    }
}
