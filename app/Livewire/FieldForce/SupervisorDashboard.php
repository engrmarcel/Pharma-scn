<?php

namespace App\Livewire\FieldForce;

use App\Domain\Crm\Models\VisitPlan;
use App\Domain\Crm\Services\CrmAnalytics;
use App\Domain\Crm\Services\SupervisorDashboard as Dashboard;
use App\Domain\Crm\Services\VisitPlanService;
use App\Domain\FieldForce\Services\FieldForceScope;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Web supervisor dashboard: the day's field work of the team, plans waiting for
 * approval (approve or reject here), targets, pipeline and 30-day CRM analytics.
 * Uses the same services as the API, so it shows exactly what the API would.
 */
#[Layout('layouts.app')]
#[Title('Field force dashboard')]
class SupervisorDashboard extends Component
{
    public string $date = '';

    public ?string $rejecting = null;

    public string $notes = '';

    public function mount(): void
    {
        $this->authorizeView();
        $this->date = today()->toDateString();
    }

    public function approve(string $planId, VisitPlanService $plans, FieldForceScope $scope): void
    {
        $this->decide($planId, true, null, $plans, $scope);
    }

    public function startReject(string $planId): void
    {
        $this->rejecting = $planId;
        $this->notes = '';
        $this->resetErrorBag();
    }

    public function reject(VisitPlanService $plans, FieldForceScope $scope): void
    {
        $this->validate(['notes' => ['required', 'string', 'max:2000']]);
        $this->decide($this->rejecting, false, $this->notes, $plans, $scope);
        $this->rejecting = null;
    }

    public function render(Dashboard $dashboard, CrmAnalytics $analytics)
    {
        $this->authorizeView();
        $this->validate(['date' => ['required', 'date']]);

        return view('livewire.field-force.supervisor-dashboard', [
            'board' => $dashboard->build($this->date),
            'analytics' => Gate::allows('field_force.reports.view') ? $analytics->build(today()->subDays(29)->toDateString(), today()->toDateString()) : null,
        ]);
    }

    private function decide(string $planId, bool $approve, ?string $notes, VisitPlanService $plans, FieldForceScope $scope): void
    {
        Gate::authorize('crm.plans.approve');
        $plan = $scope->scopeRepresentatives(VisitPlan::query())->findOrFail($planId);

        try {
            $plans->decide($plan, $approve, $notes, auth()->user());
            session()->flash('status', $approve ? 'Visit plan approved.' : 'Visit plan rejected.');
        } catch (BusinessRuleViolation $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    private function authorizeView(): void
    {
        abort_unless(Gate::any(['field_force.team.view', 'field_force.view_all', 'crm.plans.approve']), 403);
    }
}
