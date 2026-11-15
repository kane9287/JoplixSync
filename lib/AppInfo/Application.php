<?php
namespace OCA\Joplix\AppInfo;

use OCA\Joplix\Listener\OfficeFileWrittenListener;
use OCA\Joplix\Listener\UserDeletedListener;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
    public const APP_ID = 'joplix';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
        $context->registerEventListener(NodeWrittenEvent::class, OfficeFileWrittenListener::class);
    }

    public function boot(IBootContext $context): void {
    }
}
