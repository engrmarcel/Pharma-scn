<?php

namespace App\Domain\FieldForce\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldVisit extends Model
{
    use BelongsToOrganization, HasUlids;

    public const TYPES = ['routine', 'detailing', 'order_taking', 'follow_up', 'complaint', 'collection', 'other'];

    public const OUTCOMES = ['successful', 'partially_successful', 'unsuccessful', 'customer_unavailable', 'rescheduled'];

    protected $guarded = [];

    protected $attributes = ['status' => 'in_progress', 'order_opportunity' => false, 'captured_offline' => false];

    protected function casts(): array
    {
        $casts = ['products_discussed' => 'array', 'order_opportunity' => 'boolean', 'captured_offline' => 'boolean', 'follow_up_date' => 'date:Y-m-d', 'location_purged_at' => 'datetime'];
        foreach (['check_in', 'check_out'] as $e) {
            $casts += ["{$e}_at" => 'datetime', "{$e}_received_at" => 'datetime', "{$e}_latitude" => 'decimal:7', "{$e}_longitude" => 'decimal:7',
                "{$e}_accuracy_m" => 'decimal:2', "{$e}_mock_location" => 'boolean', "{$e}_distance_m" => 'integer', "{$e}_device" => 'array'];
        }

        return $casts;
    }

    public function activities(): HasMany
    {
        return $this->hasMany(FieldVisitActivity::class);
    }

    public function isVerified(): bool
    {
        return $this->check_in_verification === 'verified';
    }
}
