<?php

namespace App\Domain\Crm\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A lead (prospect, no customer yet) or an opportunity with a customer, closed as won (with an order) or lost. */
class Opportunity extends Model
{
    use BelongsToOrganization, HasUlids;

    public const OPEN_STAGES = ['lead', 'qualified', 'proposal', 'negotiation'];

    public const STAGES = ['lead', 'qualified', 'proposal', 'negotiation', 'won', 'lost'];

    /** Default probability per stage (percent); the owner may override it while the opportunity is open. */
    public const PROBABILITY = ['lead' => 10, 'qualified' => 25, 'proposal' => 50, 'negotiation' => 75, 'won' => 100, 'lost' => 0];

    protected $table = 'crm_opportunities';

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['stage' => 'lead', 'probability' => 10];

    protected function casts(): array
    {
        return ['expected_value' => 'decimal:4', 'probability' => 'integer', 'expected_close_date' => 'date:Y-m-d', 'next_step_date' => 'date:Y-m-d',
            'product_ids' => 'array', 'closed_at' => 'datetime', 'last_activity_at' => 'datetime'];
    }

    public function isOpen(): bool
    {
        return in_array($this->stage, self::OPEN_STAGES, true);
    }
}
