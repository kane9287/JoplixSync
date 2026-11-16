<?php
namespace OCA\JoplixSync\Sync;

/** A condition that stops a sync run and needs the user's attention. */
class SyncAbortException extends \RuntimeException {
    private string $reason;

    public function __construct(string $message, string $reason = '') {
        parent::__construct($message);
        $this->reason = $reason;
    }

    public function reason(): string {
        return $this->reason;
    }
}
