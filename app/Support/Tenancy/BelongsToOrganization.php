<?php

namespace App\Support\Tenancy;

use App\Domain\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * For tenant-owned models with an organization_id column.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            $tenantId = app(TenantContext::class)->id();

            if ($model->organization_id === null) {
                if ($tenantId === null) {
                    throw new LogicException('Cannot create '.static::class.' without a tenant context or explicit organization_id.');
                }
                $model->organization_id = $tenantId;
            } elseif ($tenantId !== null && $model->organization_id !== $tenantId) {
                throw new LogicException('Refusing to create '.static::class.' for another organization than the current tenant.');
            }
        });
    }

    public static function acrossTenants(): Builder
    {
        return static::query()->withoutGlobalScope(TenantScope::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
