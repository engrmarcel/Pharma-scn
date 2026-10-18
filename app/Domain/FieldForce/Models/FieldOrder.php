<?php

namespace App\Domain\FieldForce\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldOrder extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = [];

    protected $attributes = ['status' => 'draft', 'captured_offline' => false];

    protected function casts(): array
    {
        return ['captured_at' => 'datetime', 'submitted_at' => 'datetime', 'validated_at' => 'datetime', 'rejection_reasons' => 'array',
            'captured_offline' => 'boolean', 'requested_date' => 'date:Y-m-d'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FieldOrderLine::class);
    }
}
