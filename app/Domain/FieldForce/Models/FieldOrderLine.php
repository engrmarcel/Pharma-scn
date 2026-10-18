<?php

namespace App\Domain\FieldForce\Models;

use Illuminate\Database\Eloquent\Model;

/** Child of a tenant-scoped field order; always reached through it. */
class FieldOrderLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'indicative_unit_price' => 'decimal:4'];
    }
}
