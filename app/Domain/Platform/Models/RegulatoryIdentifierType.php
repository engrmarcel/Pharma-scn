<?php

namespace App\Domain\Platform\Models;

use App\Domain\Platform\Support\CountryProfile;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An identifier a regulator issues, with what it applies to and its format when confirmed. */
class RegulatoryIdentifierType extends Model
{
    use HasUlids;

    public const SUBJECTS = ['product', 'organization', 'manufacturer'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['subject_types' => 'array', 'is_active' => 'boolean'];
    }

    public function authority(): BelongsTo
    {
        return $this->belongsTo(RegulatoryAuthority::class, 'authority_id');
    }

    public function appliesTo(string $subjectType): bool
    {
        return in_array($subjectType, $this->subject_types ?? [], true);
    }

    public function matchesFormat(string $value): bool
    {
        return $this->format_pattern === null || (bool) preg_match(CountryProfile::delimit($this->format_pattern), $value);
    }
}
