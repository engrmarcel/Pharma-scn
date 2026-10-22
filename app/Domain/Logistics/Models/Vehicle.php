<?php

namespace App\Domain\Logistics\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['status' => 'active', 'refrigerated' => false];

    protected function casts(): array
    {
        return ['refrigerated' => 'boolean'];
    }
}
