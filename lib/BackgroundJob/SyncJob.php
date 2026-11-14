<?php
namespace OCA\Joplix\BackgroundJob;

use OCA\Joplix\Service\JoplixSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Synchronises every user who enabled Joplin Server sync, so the Nextcloud
 * copy stays current even when Joplix is not open in a browser.
 */
class SyncJob extends TimedJob {
    private const INTERVAL_SECONDS = 300;
    /** Upper bound for one job execution across all users. */
    private const TOTAL_BUDGET_SECONDS = 240;
    private const PER_USER_BUDGET_SECONDS = 120;

    private JoplixSyncService $sync;
    private LoggerInterface $logger;

    public function __construct(ITimeFactory $time, JoplixSyncService $sync, LoggerInterface $logger) {
        parent::__construct($time);
        $this->sync = $sync;
        $this->logger = $logger;
        $this->setInterval(self::INTERVAL_SECONDS);
    }

    protected function run($argument): void {
        $deadline = time() + self::TOTAL_BUDGET_SECONDS;
        $users = $this->sync->enabledUsers();
        // Rotate the starting point so a slow first user cannot starve the others.
        if (count($users) > 1) {
            $offset = intdiv(time(), self::INTERVAL_SECONDS) % count($users);
            $users = array_merge(array_slice($users, $offset), array_slice($users, 0, $offset));
        }
        foreach ($users as $uid) {
            $remaining = $deadline - time();
            if ($remaining < 10) {
                break;
            }
            try {
                $this->sync->run((string)$uid, min(self::PER_USER_BUDGET_SECONDS, $remaining));
            } catch (\Throwable $e) {
                $this->logger->warning('Joplix background sync failed for {uid}: {message}', ['app' => 'joplix', 'uid' => $uid, 'message' => $e->getMessage()]);
            }
        }
    }
}
