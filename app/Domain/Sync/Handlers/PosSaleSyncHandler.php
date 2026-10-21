<?php

namespace App\Domain\Sync\Handlers;

use App\Domain\Sync\Models\SyncInboxItem;
use App\Support\Exceptions\BusinessRuleViolation;

/** Offline POS sale replay (Phase 3b). */
class PosSaleSyncHandler
{
    public function handle(SyncInboxItem $item): array
    {
        throw new BusinessRuleViolation('unsupported_envelope', "Unsupported transaction type ({$item->type}).", 422);
    }
}
