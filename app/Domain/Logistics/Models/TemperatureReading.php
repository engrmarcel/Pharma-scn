<?php

namespace App\Domain\Logistics\Models;

use Illuminate\Database\Eloquent\Model;

class TemperatureReading extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime', 'temperature_c' => 'decimal:2'];
    }
}
