<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts tenant-owned models to the current organization. Fails closed:
 * with no tenant context the query matches nothing. Cross-tenant access must
 * be requested explicitly with Model::acrossTenants() by code that has
 * already authorized a platform-level permission.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(TenantContext::class)->id();
        $column = $model->qualifyColumn('organization_id');

        if ($tenantId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($column, $tenantId);
    }
}
