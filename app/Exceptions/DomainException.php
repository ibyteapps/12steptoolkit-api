<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A business-rule failure that should reach the client with a specific status
 * and a safe, user-facing message (e.g. 409 conflict, 422 invalid code).
 */
class DomainException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly ?array $errors = null,
    ) {
        parent::__construct($message);
    }
}
