<?php

namespace App\Domain\Organizations\Models;

use Illuminate\Database\Eloquent\Model;

class OrganizationModule extends Model
{
    protected $primaryKey = null;

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
