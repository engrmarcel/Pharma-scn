<?php

namespace App\Domain\Crm\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** An approved key message for a product. Representatives may only record delivering approved, unexpired messages. */
class DetailingMessage extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['valid_until' => 'date:Y-m-d', 'approved_at' => 'datetime'];
    }

    public function isUsable(): bool
    {
        return $this->status === 'approved' && ($this->valid_until === null || ! $this->valid_until->copy()->endOfDay()->isPast());
    }
}
