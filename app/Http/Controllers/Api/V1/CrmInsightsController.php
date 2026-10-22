<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Crm\Services\CrmAnalytics;
use App\Domain\Crm\Services\SupervisorDashboard;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Supervisor dashboard and CRM analytics, within the caller's activity scope (ADR-0014). */
class CrmInsightsController extends Controller
{
    public function dashboard(Request $request, SupervisorDashboard $dashboard): JsonResponse
    {
        abort_unless(Gate::any(['field_force.team.view', 'field_force.view_all', 'crm.plans.approve']), 403);
        $date = $request->validate(['date' => ['nullable', 'date']])['date'] ?? today()->toDateString();

        return response()->json(['data' => $dashboard->build($date)]);
    }

    public function analytics(Request $request, CrmAnalytics $analytics, TenantContext $tenant): JsonResponse
    {
        Gate::authorize('field_force.reports.view');
        $d = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'territory_id' => ['nullable', 'ulid', Rule::exists('territories', 'id')->where('organization_id', $tenant->id())]]);

        return response()->json(['data' => $analytics->build($d['from'] ?? today()->subDays(29)->toDateString(), $d['to'] ?? today()->toDateString(), $d['territory_id'] ?? null)]);
    }
}
