<?php

namespace App\Domain\Pos\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentProviderEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'received_at' => 'datetime'];
    }
}
