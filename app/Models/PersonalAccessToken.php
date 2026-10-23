<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Sanctum writes last_used_at on every authenticated request. It is only shown in the token list,
 * so a write within a minute of the previous one is skipped: one write less per request
 * (Phase 10 load test).
 */
class PersonalAccessToken extends SanctumToken
{
    public const LAST_USED_RESOLUTION_SECONDS = 60;

    public function save(array $options = []): bool
    {
        $previous = $this->getOriginal('last_used_at');
        if ($this->exists && array_keys($this->getDirty()) === ['last_used_at'] && $previous !== null
            && abs($this->asDateTime($previous)->diffInSeconds(now())) < self::LAST_USED_RESOLUTION_SECONDS) {
            return true;
        }

        return parent::save($options);
    }
}
