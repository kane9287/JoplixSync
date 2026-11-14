<?php
namespace OCA\Joplix\Sync;

/**
 * Error reported by (or while talking to) a Joplin Server.
 *
 * $httpStatus is 0 for transport errors. $serverCode carries the server's own
 * error code where it sends one (for example "resyncRequired",
 * "hasExclusiveLock") or a local marker such as "connection".
 */
class JoplinServerException extends \RuntimeException {
    private int $httpStatus;
    private string $serverCode;

    public function __construct(string $message, int $httpStatus = 0, string $serverCode = '') {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->serverCode = $serverCode;
    }

    public function httpStatus(): int {
        return $this->httpStatus;
    }

    public function serverCode(): string {
        return $this->serverCode;
    }

    /** The server refused this item for good; retrying the same content is pointless. */
    public function isRejection(): bool {
        return in_array($this->httpStatus, [409, 413, 422], true) && $this->serverCode === '';
    }
}
