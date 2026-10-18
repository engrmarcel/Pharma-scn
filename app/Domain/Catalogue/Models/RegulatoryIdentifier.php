<?php

namespace App\Domain\Catalogue\Models;

use App\Domain\Catalogue\Enums\RegulatoryVerificationStatus;
use App\Domain\Platform\Models\RegulatoryAuthority;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An identifier issued by a regulator (e.g. a NAFDAC Registration Number).
 * Storing one records a claim; only RegulatoryVerification rows record
 * whether the platform checked it, and neither is a statement by the regulator.
 */
class RegulatoryIdentifier extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'verification_status' => RegulatoryVerificationStatus::class,
            'registration_date' => 'date',
            'expiry_date' => 'date',
            'last_verified_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function authority(): BelongsTo
    {
        return $this->belongsTo(RegulatoryAuthority::class, 'authority_id');
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(RegulatoryVerification::class)->orderByDesc('verified_at');
    }

    /**
     * Verification status as of today: a verified record whose declared expiry
     * date has passed is reported as expired without rewriting history.
     */
    public function effectiveStatus(): RegulatoryVerificationStatus
    {
        if ($this->verification_status === RegulatoryVerificationStatus::Verified
            && $this->expiry_date !== null && $this->expiry_date->isPast() && ! $this->expiry_date->isToday()) {
            return RegulatoryVerificationStatus::Expired;
        }

        return $this->verification_status;
    }

    /** Reserved prefixes for clearly fictional demo/test identifiers. */
    public static function isSynthetic(string $value): bool
    {
        return (bool) preg_match('/^(DEMO|TEST)-/i', $value);
    }

    public static function normalizeValue(string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', $value));
    }
}
