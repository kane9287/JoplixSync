<?php
namespace OCA\Joplix\Service;

use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Short exclusive lock per user around every change to the Joplin folder.
 *
 * Two parties write item files: the API (a user saving, trashing, tagging) and the
 * sync engine (applying a version from Joplin Server). Each takes this lock for the
 * moment in which it looks at a file and then writes it, so neither can act on a
 * state the other has just replaced. The sync engine holds it per item, never for a
 * whole run, which keeps saving possible while a long synchronisation is going on.
 *
 * Nextcloud's locking provider does not wait, so run() retries for a limited time.
 * With file locking disabled in Nextcloud (NoopLockingProvider) this is a no-op and
 * only the engine's re-reading of the file before it writes remains as protection.
 */
class JoplixWriteLock {
    private ILockingProvider $locking;

    public function __construct(ILockingProvider $locking) {
        $this->locking = $locking;
    }

    /**
     * @template T
     * @param callable():T $critical
     * @return T
     * @throws \RuntimeException when the lock stays taken for $waitSeconds
     */
    public function run(string $uid, callable $critical, float $waitSeconds = 10.0) {
        $key = 'joplix/write/' . md5($uid);
        $deadline = microtime(true) + $waitSeconds;
        while (true) {
            try {
                $this->locking->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
                break;
            } catch (LockedException $e) {
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException(\OCP\Server::get(\OCP\L10N\IFactory::class)->get(\OCA\Joplix\AppInfo\Application::APP_ID)->t('Joplix is busy writing this folder (synchronisation or another save). Please try again.'));
                }
                usleep(50000);
            }
        }
        try {
            return $critical();
        } finally {
            try {
                $this->locking->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
            } catch (\Throwable $e) {
                // Released at the end of the request in any case.
            }
        }
    }
}
