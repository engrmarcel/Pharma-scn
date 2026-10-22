<?php

namespace App\Domain\Traceability\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A traceability incident the organization must report to the regulator
 * (NAFDAC Pharmaceutical Products (Traceability) Regulations 2024):
 *   stolen, lost                           within 24 hours (reg. 14(b))
 *   identifier_data_error                  within 48 hours (reg. 12(4)(d))
 *   unscannable_carrier                    within 48 hours, before further action (reg. 12(7))
 *   missing_identifier                     within 48 hours (reg. 14(a))
 *   suspected_falsified                    48 hours (internal policy; report through the regulator's safety channel)
 */
class RegulatoryIncident extends Model
{
    use BelongsToOrganization, HasUlids;

    public const DEADLINE_HOURS = ['stolen' => 24, 'lost' => 24, 'identifier_data_error' => 48, 'unscannable_carrier' => 48, 'missing_identifier' => 48, 'suspected_falsified' => 48];

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['serials' => 'array', 'quantity' => 'decimal:4', 'detected_at' => 'datetime', 'report_due_at' => 'datetime', 'reported_at' => 'datetime'];
    }

    public function isOverdue(): bool
    {
        return $this->status === 'open' && $this->report_due_at->isPast();
    }
}
