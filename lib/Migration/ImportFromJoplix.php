<?php
namespace OCA\JoplixSync\Migration;

use OCA\JoplixSync\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Until 2026.11.82 this app had the id "joplix". A new id is a new app for Nextcloud, so its settings, its
 * app settings and its synchronisation state would start empty. This step copies them over once, from the
 * old id to the new one, and then disables the old app so two copies never synchronise the same folder.
 *
 * What is copied:
 *   - user settings (preferences of app "joplix"), except the short-lived run markers in SKIP_USER_KEYS;
 *     the Joplin Server password is encrypted with the instance secret, not the app id, so it stays valid
 *   - app settings (appconfig of app "joplix"), except Nextcloud's own keys in SKIP_APP_KEYS
 *   - app data folder "sync" (per-user sync state); the index cache is not copied, it is rebuilt
 * Values the new app already has are never overwritten. Nothing of the old app is deleted, so its data
 * stays as it was if the admin wants to go back.
 *
 * Only the predecessor of this fork is imported: its versions are CalVer (2026.x). The upstream Joplix app
 * by Stoneubi (1.0.x versions) uses the same id with different state, so it is left alone.
 *
 * Registered as install and post-migration step; runs once (app setting "imported_from").
 * Not needed by new installations: remove once no installation of the old id is left.
 */
class ImportFromJoplix implements IRepairStep {
    private const OLD_ID = 'joplix';
    private const SKIP_USER_KEYS = ['sync_session', 'sync_running_until'];
    private const SKIP_APP_KEYS = ['enabled', 'installed_version', 'types'];
    private const MISSING = "\0missing";

    public function __construct(
        private IConfig $config,
        private IDBConnection $db,
        private IAppDataFactory $appDataFactory,
        private IAppManager $appManager,
        private LoggerInterface $logger,
    ) {
    }

    public function getName(): string {
        return 'Import settings and sync state from the previous app id "joplix"';
    }

    public function run(IOutput $output): void {
        if ($this->config->getAppValue(Application::APP_ID, 'imported_from', '') !== '') {
            return;
        }
        $oldVersion = (string)$this->config->getAppValue(self::OLD_ID, 'installed_version', '');
        if (!preg_match('/^20\d\d\.\d+\.\d+$/', $oldVersion)) {
            // Nothing to import (fresh installation or the upstream app). Mark as done so it is not retried.
            $this->config->setAppValue(Application::APP_ID, 'imported_from', 'none');
            return;
        }

        $users = $this->copyUserValues();
        $apps = $this->copyAppValues();
        $files = $this->copySyncState();
        $this->config->setAppValue(Application::APP_ID, 'imported_from', self::OLD_ID . ' ' . $oldVersion);
        $output->info(sprintf('Imported from joplix %s: %d user settings, %d app settings, %d sync state files.', $oldVersion, $users, $apps, $files));

        if ($this->config->getAppValue(self::OLD_ID, 'enabled', 'no') !== 'no') {
            try {
                $this->appManager->disableApp(self::OLD_ID);
                $output->warning('The previous app "joplix" was disabled so it does not synchronise the same folders. Its folder can be removed from the apps directory.');
            } catch (\Throwable $e) {
                $output->warning('Disable the previous app "joplix" by hand (occ app:disable joplix): ' . $e->getMessage());
            }
        }
    }

    private function copyUserValues(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('userid', 'configkey', 'configvalue')
            ->from('preferences')
            ->where($qb->expr()->eq('appid', $qb->createNamedParameter(self::OLD_ID)));
        $result = $qb->executeQuery();
        $count = 0;
        while (($row = $result->fetch()) !== false) {
            $uid = (string)$row['userid'];
            $key = (string)$row['configkey'];
            if (in_array($key, self::SKIP_USER_KEYS, true)
                || $this->config->getUserValue($uid, Application::APP_ID, $key, self::MISSING) !== self::MISSING) {
                continue;
            }
            $this->config->setUserValue($uid, Application::APP_ID, $key, (string)$row['configvalue']);
            $count++;
        }
        $result->closeCursor();
        return $count;
    }

    private function copyAppValues(): int {
        $count = 0;
        foreach ($this->config->getAppKeys(self::OLD_ID) as $key) {
            if (in_array($key, self::SKIP_APP_KEYS, true)
                || $this->config->getAppValue(Application::APP_ID, $key, self::MISSING) !== self::MISSING) {
                continue;
            }
            $this->config->setAppValue(Application::APP_ID, $key, (string)$this->config->getAppValue(self::OLD_ID, $key, ''));
            $count++;
        }
        return $count;
    }

    private function copySyncState(): int {
        try {
            $source = $this->appDataFactory->get(self::OLD_ID)->getFolder('sync');
        } catch (NotFoundException $e) {
            return 0;
        }
        $appData = $this->appDataFactory->get(Application::APP_ID);
        try {
            $target = $appData->getFolder('sync');
        } catch (NotFoundException $e) {
            $target = $appData->newFolder('sync');
        }
        $count = 0;
        foreach ($source->getDirectoryListing() as $file) {
            try {
                if (!$target->fileExists($file->getName())) {
                    $target->newFile($file->getName(), $file->getContent());
                    $count++;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('JoplixSync: could not import sync state {file}: {message}', ['app' => Application::APP_ID, 'file' => $file->getName(), 'message' => $e->getMessage()]);
            }
        }
        return $count;
    }
}
