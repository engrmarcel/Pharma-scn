<?php

namespace App\Livewire\Admin;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Services\OrganizationVerificationService;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Compliance officers' queue of organizations awaiting platform verification.
 * Every action re-checks authorization: Livewire actions are endpoints.
 */
#[Layout('layouts.app')]
#[Title('Verification queue')]
class VerificationQueue extends Component
{
    use WithPagination;

    public string $status = 'pending_review';

    public ?string $reviewing = null;

    public string $notes = '';

    public function mount(): void
    {
        Gate::authorize('platform.organizations.view');
    }

    public function review(string $organizationId): void
    {
        Gate::authorize('platform.organizations.view');
        $this->reviewing = $organizationId;
        $this->notes = '';
        $this->resetErrorBag();
    }

    public function decide(string $decision, OrganizationVerificationService $verification): void
    {
        Gate::authorize('platform.organizations.verify');
        $this->validate(['notes' => ['nullable', 'string', 'max:5000']]);

        $organization = Organization::query()->findOrFail($this->reviewing);

        try {
            $verification->decide($organization, $decision, auth()->user(), $this->notes ?: null);
        } catch (BusinessRuleViolation $e) {
            $this->addError('notes', $e->getMessage());

            return;
        }

        session()->flash('status', "{$organization->name}: decision recorded ({$decision}).");
        $this->reviewing = null;
    }

    public function render()
    {
        $current = $this->reviewing ? Organization::query()->with(['verifications.actor', 'documents'])->find($this->reviewing) : null;

        return view('livewire.admin.verification-queue', [
            'organizations' => Organization::query()->where('verification_status', $this->status)->oldest('updated_at')->paginate(20),
            'current' => $current,
            'canDecide' => Gate::allows('platform.organizations.verify'),
        ]);
    }
}
