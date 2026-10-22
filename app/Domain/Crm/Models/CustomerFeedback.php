<?php

namespace App\Domain\Crm\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Structured customer feedback. A possible adverse event is flagged for pharmacovigilance; the CRM does not hold patient data. */
class CustomerFeedback extends Model
{
    use BelongsToOrganization, HasUlids;

    public const CATEGORIES = ['complaint', 'product_quality', 'service', 'delivery', 'pricing', 'praise', 'suggestion', 'other'];

    protected $table = 'customer_feedback';

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['status' => 'open', 'possible_adverse_event' => false];

    protected function casts(): array
    {
        return ['possible_adverse_event' => 'boolean', 'rating' => 'integer', 'resolved_at' => 'datetime'];
    }
}
