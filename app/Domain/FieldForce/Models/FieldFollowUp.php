<?php

namespace App\Domain\FieldForce\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class FieldFollowUp extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = [];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['due_on' => 'date:Y-m-d', 'completed_at' => 'datetime', 'escalated_at' => 'datetime'];
    }
}
