<?php
namespace OCA\JoplixSync\Listener;

use OCA\JoplixSync\AppInfo\Application;
use OCA\JoplixSync\Service\JoplixOfficeService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Imports an Office working copy into its attachment as soon as an editor saves it (Collabora via WOPI,
 * ONLYOFFICE / Euro Office via their callback), independent of an open JoplixSync tab. Only files at
 * /<uid>/files/.joplix/editing/<32 hex session>/<name> are looked at; anything else returns at once.
 *
 * @template-implements IEventListener<NodeWrittenEvent>
 */
class OfficeFileWrittenListener implements IEventListener {
    private const PATH = '#^/([^/]+)/files/\.joplix/editing/([a-f0-9]{32})/[^/]+$#D';

    private JoplixOfficeService $office;
    private LoggerInterface $logger;

    public function __construct(JoplixOfficeService $office, LoggerInterface $logger) {
        $this->office = $office;
        $this->logger = $logger;
    }

    public function handle(Event $event): void {
        if (!$event instanceof NodeWrittenEvent) {
            return;
        }
        try {
            $node = $event->getNode();
            if (!$node instanceof File || !preg_match(self::PATH, $node->getPath(), $m)) {
                return;
            }
            $this->office->importBySession($m[1], $m[2]);
        } catch (\Throwable $e) {
            // Never break the editor's save. The browser poll or the next cleanup retries the import.
            $this->logger->warning('JoplixSync could not import an Office save: {message}', ['app' => Application::APP_ID, 'message' => $e->getMessage(), 'exception' => $e]);
        }
    }
}
