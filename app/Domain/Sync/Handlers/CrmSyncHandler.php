<?php

namespace App\Domain\Sync\Handlers;

use App\Domain\Crm\Services\DetailingService;
use App\Domain\Crm\Services\InteractionService;
use App\Domain\Crm\Support\CrmRules;
use App\Domain\FieldForce\Models\FieldVisit;
use App\Domain\Sync\Models\SyncInboxItem;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * CRM records captured offline (Phase 6): detailing, feedback and logged
 * interactions. Same services and rules as the online endpoints; visits are
 * referenced by the device's client_reference.
 */
class CrmSyncHandler
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly DetailingService $detailing,
        private readonly InteractionService $interactions,
    ) {}

    public function handle(SyncInboxItem $item): array
    {
        Gate::authorize('field_force.visits.capture');
        $p = $item->payload;

        return match ($item->type) {
            'crm.detailing.record' => $this->detailing($p),
            'crm.feedback.record' => $this->feedback($p),
            'crm.interaction.log' => $this->interaction($p),
        };
    }

    private function detailing(array $p): array
    {
        $data = Validator::make($p, CrmRules::detailing() + ['visit_client_reference' => ['required', 'string', 'max:64']])->validate();
        $record = $this->detailing->record($this->ownVisit($data['visit_client_reference']), $data)['record'];

        return $record->only(['id', 'field_visit_id', 'product_id', 'reception']);
    }

    private function feedback(array $p): array
    {
        $data = Validator::make($p, CrmRules::feedback($this->tenant->id()) + ['visit_client_reference' => ['nullable', 'string', 'max:64']])->validate();
        if (filled($data['visit_client_reference'] ?? null)) {
            $data['field_visit_id'] = $this->ownVisit($data['visit_client_reference'])->id;
        }
        $feedback = $this->interactions->recordFeedback($data)['feedback'];

        return $feedback->only(['id', 'status', 'possible_adverse_event'])
            + ($feedback->possible_adverse_event ? ['pharmacovigilance_notice' => CrmRules::PHARMACOVIGILANCE_NOTICE] : []);
    }

    private function interaction(array $p): array
    {
        $data = Validator::make($p, CrmRules::interaction() + ['visit_client_reference' => ['nullable', 'string', 'max:64']])->validate();
        if (filled($data['visit_client_reference'] ?? null)) {
            $data['field_visit_id'] = $this->ownVisit($data['visit_client_reference'])->id;
        }

        return $this->interactions->log($data)['interaction']->only(['id', 'channel', 'occurred_at']);
    }

    private function ownVisit(string $reference): FieldVisit
    {
        return FieldVisit::query()->where('client_reference', $reference)->where('representative_id', Auth::id())->first()
            ?? throw new BusinessRuleViolation('visit_not_found', 'The visit this record belongs to was not found on the server.', 404);
    }
}
