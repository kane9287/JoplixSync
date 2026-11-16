<?php
namespace OCA\JoplixSync\Service;

/**
 * The item was changed since the client loaded it (its known updated_time no longer matches).
 * ApiController answers it with HTTP 409, which the browser turns into the "changed in the
 * meantime" choice. The message is already translated for the user.
 */
class ConflictException extends \RuntimeException {
}
