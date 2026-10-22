<?php

namespace App\Domain\Traceability\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A traceability incident the organization may have to report to its regulator. The reporting
 * deadline per incident type comes from the country profile (regulatory.incident_deadline_hours);
 * where none is configured the incident has no due date. Nigeria's values follow the NAFDAC
 * Pharmaceutical Products (Traceability) Regulations 2024 (docs/REGULATORY_TRACEABILITY_REVIEW.md).
 */
class RegulatoryIncident extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['serials' => 'array', 'quantity' => 'decimal:4', 'detected_at' => 'datetime', 'report_due_at' => 'datetime', 'reported_at' => 'datetime'];
    }

    public function isOverdue(): bool
    {
        return $this->status === 'open' && $this->report_due_at !== null && $this->report_due_at->isPast();
    }
}
