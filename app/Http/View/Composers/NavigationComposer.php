<?php

namespace App\Http\View\Composers;

use App\Domain\Billing\Services\EntitlementService;
use App\Domain\Platform\Services\BrandingService;
use App\Support\Tenancy\TenantContext;
use App\Support\Web\Navigation;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Data for the application chrome (header menus, organization switcher).
 * Menu entries are conveniences only: every page re-checks access itself.
 */
class NavigationComposer
{
    public function __construct(
        private readonly BrandingService $branding,
        private readonly TenantContext $tenant,
        private readonly EntitlementService $entitlements,
        private readonly Navigation $navigation,
    ) {}

    public function compose(View $view): void
    {
        $view->with($this->build());
    }

    private function build(): array
    {
        $user = auth()->user();
        if ($user === null) {
            return ['brand' => $this->branding->platform(), 'nav' => null];
        }

        $memberships = $user->activeMemberships()->with('organization')->get()->sortBy('organization.name')->values();
        $currentId = $this->tenant->id() ?? session(config('platform.tenancy.session_key'));
        $current = $memberships->firstWhere('organization_id', $currentId);
        $org = $current?->organization;

        $canPos = $org !== null && $org->isActive() && $org->hasModule('pos') && $this->entitlements->allows($org, 'module.pos')
            && $this->tenant->runAs($org, fn () => Gate::forUser($user)->allows('pos.sell'), $current);

        $canFieldDashboard = $org !== null && $org->isActive() && $org->hasModule('field_force') && $this->entitlements->allows($org, 'module.field_force')
            && $this->tenant->runAs($org, fn () => Gate::forUser($user)->any(['field_force.team.view', 'field_force.view_all', 'crm.plans.approve']), $current);

        $canAnalytics = $org !== null && $org->isActive() && $org->hasModule('analytics') && $this->entitlements->allows($org, 'module.analytics')
            && $this->tenant->runAs($org, fn () => Gate::forUser($user)->allows('analytics.view'), $current);

        $sections = $this->navigation->visible($user, $org, $current);
        $here = Navigation::find(request()->route()?->getName());

        return [
            'brand' => $this->branding->forOrganization($org),
            'nav' => [
                'sections' => $sections,
                'section' => $here !== null && isset($sections[$here[0]]) ? $here[0] : null,
                'memberships' => $memberships,
                'current' => $current,
                'pos' => $canPos,
                'field_dashboard' => $canFieldDashboard,
                'analytics' => $canAnalytics,
                'admin' => Gate::forUser($user)->any(['platform.organizations.view', 'platform.settings.manage', 'platform.documentation.view']),
                'admin_docs' => Gate::forUser($user)->allows('platform.documentation.view'),
                'admin_verifications' => Gate::forUser($user)->allows('platform.organizations.view'),
                'admin_landing' => Gate::forUser($user)->allows('platform.settings.manage'),
                'initials' => collect(preg_split('/\s+/', trim($user->name)))->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode(''),
            ],
        ];
    }
}
