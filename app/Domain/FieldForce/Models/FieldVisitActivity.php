<?php

namespace App\Domain\FieldForce\Models;

use Illuminate\Database\Eloquent\Model;

/** Child of a tenant-scoped visit; always reached through it. */
class FieldVisitActivity extends Model
{
    public const TYPES = ['detailing', 'sample_request', 'order_request', 'complaint', 'stock_check', 'note'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }
}
