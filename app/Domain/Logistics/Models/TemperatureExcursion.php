<?php

namespace App\Domain\Logistics\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A period of consecutive readings outside the shipment's required range. */
class TemperatureExcursion extends Model
{
    use HasUlids;

    public const ASSESSMENTS = ['product_usable', 'quarantine', 'destroy'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'open', 'readings' => 1];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime', 'assessed_at' => 'datetime', 'lowest_c' => 'decimal:2', 'highest_c' => 'decimal:2', 'readings' => 'integer'];
    }

    public function minutes(): ?int
    {
        return $this->ended_at ? (int) $this->started_at->diffInMinutes($this->ended_at) : null;
    }
}
