<?php

namespace App\Domain\Logistics\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One physical transport. Shared by the shipper (organization_id) and, when assigned, a carrier
 * on the platform (carrier_organization_id); every read goes through ShipmentService::visible().
 *
 * planned → dispatched → in_transit → delivered | partially_delivered | failed | returned_to_origin; planned → cancelled
 */
class Shipment extends Model
{
    use HasUlids;

    public const OPEN = ['planned', 'dispatched', 'in_transit'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'planned', 'requires_cold_chain' => false];

    protected function casts(): array
    {
        return ['requires_cold_chain' => 'boolean', 'temperature_min_c' => 'decimal:2', 'temperature_max_c' => 'decimal:2',
            'planned_delivery_at' => 'datetime', 'dispatched_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class)->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function proof(): HasOne
    {
        return $this->hasOne(ProofOfDelivery::class);
    }

    public function readings(): HasMany
    {
        return $this->hasMany(TemperatureReading::class)->orderBy('recorded_at');
    }

    public function excursions(): HasMany
    {
        return $this->hasMany(TemperatureExcursion::class)->orderBy('started_at');
    }
}
