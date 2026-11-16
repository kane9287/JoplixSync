<?php
namespace OCA\JoplixSync\Service;

use OCA\JoplixSync\AppInfo\Application;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\L10N\IFactory;

/**
 * The one definition of "the user's Joplin folder" (setting joplin_path, default /JoplinV2), used by the
 * API, the synchronisation and the background job alike.
 *
 * The folder is only created when the caller asks for it ($create): the synchronisation does so when a user
 * runs it from the browser; the API and the background job never do, so a mistyped path or a moved folder
 * shows up as an error instead of a new empty folder that then fills with server content.
 */
class JoplixFolders {
    public const DEFAULT_PATH = '/JoplinV2';

    private IRootFolder $rootFolder;
    private IConfig $config;
    private IFactory $l10nFactory;

    public function __construct(IRootFolder $rootFolder, IConfig $config, IFactory $l10nFactory) {
        $this->rootFolder = $rootFolder;
        $this->config = $config;
        $this->l10nFactory = $l10nFactory;
    }

    /** Leading slash, forward slashes, no trailing slash; empty or "/" means the default. Same rule as normalizePath() in js/app.js. */
    public static function normalizePath(string $path): string {
        $path = trim(preg_replace('#/+#', '/', str_replace('\\', '/', trim($path))) ?? '', '/');
        return $path === '' ? self::DEFAULT_PATH : '/' . $path;
    }

    public function joplinPath(string $uid): string {
        return self::normalizePath($this->config->getUserValue($uid, Application::APP_ID, 'joplin_path', self::DEFAULT_PATH));
    }

    /** @throws \RuntimeException when the path is invalid, missing (and $create is false) or not a folder */
    public function joplinFolder(string $uid, bool $create = false): Folder {
        $path = $this->joplinPath($uid);
        $node = $this->rootFolder->getUserFolder($uid);
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new \RuntimeException($this->l()->t('The JoplixSync folder path is not valid: %1$s', [$path]));
            }
            try {
                $child = $node->get($segment);
            } catch (NotFoundException $e) {
                if (!$create) {
                    throw new \RuntimeException($this->l()->t('The JoplixSync folder %1$s does not exist. Check the path in the settings.', [$path]));
                }
                $child = $node->newFolder($segment);
            }
            if (!$child instanceof Folder) {
                throw new \RuntimeException($this->l()->t('The JoplixSync path is not a folder: %1$s', [$path]));
            }
            $node = $child;
        }
        return $node;
    }

    private function l(): IL10N {
        return $this->l10nFactory->get(Application::APP_ID);
    }
}
