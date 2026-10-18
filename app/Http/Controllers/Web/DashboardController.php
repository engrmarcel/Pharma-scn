<?php

namespace App\Http\Controllers\Web;

use App\Domain\Platform\Services\DashboardMetrics;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** Figures appear only for an organization that ResolveTenant (optional mode) accepted for this request. */
    public function show(Request $request, TenantContext $tenant, DashboardMetrics $metrics): View
    {
        $user = $request->user();
        $memberships = $user->activeMemberships()->with(['organization.modules', 'roles'])->get();
        $currentId = $tenant->id() ?? $request->session()->get(config('platform.tenancy.session_key'));

        return view('dashboard', [
            'memberships' => $memberships,
            'current' => $memberships->firstWhere('organization_id', $currentId),
            'tenantActive' => $tenant->has(),
            'platformRoles' => $user->platformRoles()->pluck('name'),
            'metrics' => $metrics->for($user),
        ]);
    }

    /** The chosen organization is re-validated by ResolveTenant on every tenant request. */
    public function switchOrganization(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'ulid', Rule::exists('organization_memberships', 'organization_id')
                ->where('user_id', $request->user()->id)->where('status', 'active')],
        ]);

        $request->session()->put(config('platform.tenancy.session_key'), $data['organization_id']);

        return redirect()->route('dashboard');
    }
}
