<?php

namespace App\Domain\Catalogue\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Jurisdiction-specific dispensing class (e.g. prescription-only in NG).
 */
class ProductClassification extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date'];
    }
}
