<?php

namespace App\Domain\Catalogue\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductChangeRequest extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'reviewed_at' => 'datetime', 'base_version' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
