<?php

namespace App\Livewire\Admin;

use App\Domain\Catalogue\Models\RegulatoryIdentifier;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Services\OnboardingRequirements;
use App\Domain\Organizations\Services\OrganizationVerificationService;
use App\Domain\Platform\Services\AuditLogger;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
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

        $this->dispatch('notify', type: 'success', message: "{$organization->name}: ".['approve' => 'verified', 'reject' => 'rejected', 'request_changes' => 'changes requested'][$decision].'.');
        $this->reviewing = null;
        $this->notes = '';
    }

    /** Open an uploaded document; every viewing is audited against the organization. */
    public function downloadDocument(string $attachmentId)
    {
        Gate::authorize('platform.organizations.verify');
        $attachment = Organization::query()->findOrFail($this->reviewing)->documents()->findOrFail($attachmentId);
        app(AuditLogger::class)->record('organization.document_viewed_by_platform', $attachment, organizationId: $attachment->organization_id);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
    }

    public function render()
    {
        $current = $this->reviewing ? Organization::query()->with(['verifications.actor', 'documents'])->find($this->reviewing) : null;

        return view('livewire.admin.verification-queue', [
            'organizations' => Organization::query()->where('verification_status', $this->status)->oldest('updated_at')->paginate(20),
            'current' => $current,
            'identifiers' => $current ? RegulatoryIdentifier::query()->with('authority')->where('subject_type', 'organization')->where('subject_id', $current->id)->get() : collect(),
            'requirements' => $current ? app(OnboardingRequirements::class)->for($current) : null,
            'canDecide' => Gate::allows('platform.organizations.verify'),
        ]);
    }
}
