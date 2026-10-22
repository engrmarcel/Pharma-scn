<?php

namespace App\Support\Web;

use RuntimeException;

/** An API refusal surfaced to a web screen: the API's own message, code and status. */
class ApiError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $errorCode = null,
        public readonly array $details = [],
        public readonly ?string $correlationId = null,
    ) {
        parent::__construct($message, $status);
    }
}
