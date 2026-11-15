<?php
namespace OCA\Joplix\Listener;

use OCA\Joplix\AppInfo\Application;
use OCA\Joplix\Service\JoplixIndexService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\User\Events\UserDeletedEvent;

/**
 * Removes what Joplix keeps outside the user's files when an account is deleted: the cached index
 * (contains note text) and the sync state. Nextcloud removes the user's files and settings itself.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
    private IAppDataFactory $appDataFactory;

    public function __construct(IAppDataFactory $appDataFactory) {
        $this->appDataFactory = $appDataFactory;
    }

    public function handle(Event $event): void {
        if (!$event instanceof UserDeletedEvent) {
            return;
        }
        $prefix = JoplixIndexService::userPrefix($event->getUser()->getUID());
        $appData = $this->appDataFactory->get(Application::APP_ID);
        foreach (['index', 'sync'] as $name) {
            try {
                foreach ($appData->getFolder($name)->getDirectoryListing() as $file) {
                    if (strncmp($file->getName(), $prefix, strlen($prefix)) === 0) {
                        $file->delete();
                    }
                }
            } catch (NotFoundException $e) {
                // Nothing stored yet.
            } catch (\Throwable $e) {
                // Must never block the deletion of an account.
            }
        }
    }
}
