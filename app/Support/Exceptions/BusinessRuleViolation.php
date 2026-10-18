<?php

namespace App\Support\Exceptions;

use RuntimeException;

/**
 * A request that is well-formed but breaks a domain rule. Rendered as a JSON
 * error with a stable machine-readable code.
 */
class BusinessRuleViolation extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
