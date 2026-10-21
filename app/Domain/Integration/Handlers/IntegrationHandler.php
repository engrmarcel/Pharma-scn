<?php

namespace App\Domain\Integration\Handlers;

use App\Domain\Integration\Models\IntegrationConnector;
use App\Domain\Integration\Models\IntegrationMessage;

/**
 * Applies one validated canonical record to the business records, inside the
 * processor's transaction. Handlers must be idempotent: applying the same
 * record twice leaves the same state (the second time reports "unchanged").
 * A BusinessRuleViolation quarantines the message with its code and message.
 */
interface IntegrationHandler
{
    /** @return array the result returned to the source system (our ids, action taken, warnings) */
    public function handle(IntegrationMessage $message, IntegrationConnector $connector, array $data): array;
}
