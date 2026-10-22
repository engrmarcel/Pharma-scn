<?php

namespace App\Domain\Logistics\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ProofOfDelivery extends Model
{
    use HasUlids;

    protected $table = 'proofs_of_delivery';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'cold_chain_unresolved' => 'boolean', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7'];
    }
}
