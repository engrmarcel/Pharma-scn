<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Inventory\Services\LocationAccess;
use App\Support\BusinessCalendar;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Who may see which figures (spec §19.1: "users only see analytics for authorized
 * organizations and locations").
 *
 * - Organization: always the current tenant; every analytical query filters on it.
 * - Locations: a member restricted to some branches or warehouses sees figures for
 *   those warehouses only. Dashboards that cannot be split by warehouse
 *   (marketplace, integrations, synchronization, recalls) need access to all locations.
 * - Permissions: each dashboard needs the view permission of its area; money and
 *   cost figures need their own permission and are otherwise withheld, not zeroed.
 */
class AnalyticsScope
{
    public function __construct(private readonly TenantContext $tenant, private readonly LocationAccess $locations) {}

    public function organizationId(): string
    {
        return $this->tenant->id() ?? throw new BusinessRuleViolation('tenant_required', 'Analytics are read for one organization.', 400);
    }

    /** @return list<string>|null null = all locations */
    public function warehouseIds(): ?array
    {
        return $this->locations->warehouseIds();
    }

    public function allLocations(): bool
    {
        return $this->warehouseIds() === null;
    }

    /** Narrow to one warehouse on request, within what the member may see. */
    public function warehouses(?string $warehouseId): ?array
    {
        if ($warehouseId === null) {
            return $this->warehouseIds();
        }
        $this->locations->assertWarehouse($warehouseId);

        return [$warehouseId];
    }

    public function scope($query, string $column, ?array $warehouses)
    {
        return $warehouses === null ? $query : $query->whereIn($column, $warehouses);
    }

    public function requireAllLocations(string $dashboard): void
    {
        if (! $this->allLocations()) {
            throw new BusinessRuleViolation('analytics_requires_all_locations', "The {$dashboard} figures cover the whole organization; they need access to all locations.", 403);
        }
    }

    public function can(string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if (Gate::allows($permission)) {
                return true;
            }
        }

        return false;
    }

    public function authorize(string ...$permissions): void
    {
        if (! $this->can(...$permissions)) {
            throw new BusinessRuleViolation('forbidden', 'You do not have permission to view these figures.', 403, ['requires_any' => $permissions]);
        }
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function period(string $from, string $to): array
    {
        // Local business days, as UTC instants for the timestamp columns.
        $start = app(BusinessCalendar::class)->dayStart($from);
        $end = app(BusinessCalendar::class)->dayEnd($to);
        if ($start->diffInDays($end) > config('analytics.max_period_days')) {
            throw new BusinessRuleViolation('analytics_period_too_long', 'Choose a period of at most two years.', 422);
        }

        return [$start, $end];
    }
}
