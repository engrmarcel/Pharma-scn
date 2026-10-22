<?php

namespace App\Domain\Crm\Support;

use App\Domain\Crm\Models\CrmInteraction;
use App\Domain\Crm\Models\CustomerFeedback;
use App\Domain\Crm\Models\DetailingRecord;
use Illuminate\Validation\Rule;

/**
 * Validation shared by the CRM REST endpoints and the offline sync handler,
 * so a record is judged the same way however it arrives.
 */
final class CrmRules
{
    public static function detailing(): array
    {
        return [
            'client_reference' => ['nullable', 'string', 'max:64'],
            'product_id' => ['required', 'ulid'],
            'message_ids' => ['required', 'array', 'min:1', 'max:20'],
            'message_ids.*' => ['ulid'],
            'contact_id' => ['nullable', 'ulid'],
            'samples_quantity' => ['nullable', 'decimal:0,4', 'gt:0', 'max:100000'],
            'sample_batch_number' => ['nullable', 'required_with:samples_quantity', 'string', 'max:64'],
            'reception' => ['nullable', Rule::in(DetailingRecord::RECEPTIONS)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public static function feedback(string $organizationId): array
    {
        return [
            'client_reference' => ['nullable', 'string', 'max:64'],
            'customer_id' => ['required', 'ulid'],
            'contact_id' => ['nullable', 'ulid'],
            'field_visit_id' => ['nullable', 'ulid'],
            'product_id' => ['nullable', 'ulid', Rule::exists('organization_products', 'product_id')->where('organization_id', $organizationId)],
            'category' => ['required', Rule::in(CustomerFeedback::CATEGORIES)],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'description' => ['required', 'string', 'max:10000'],
            'possible_adverse_event' => ['sometimes', 'boolean'],
        ];
    }

    public static function interaction(): array
    {
        return [
            'client_reference' => ['nullable', 'string', 'max:64'],
            'customer_id' => ['required', 'ulid'],
            'contact_id' => ['nullable', 'ulid'],
            'field_visit_id' => ['nullable', 'ulid'],
            'channel' => ['required', Rule::in(CrmInteraction::CHANNELS)],
            'direction' => ['required', Rule::in(['outbound', 'inbound'])],
            'subject' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:10000'],
            'occurred_at' => ['required', 'date'],
        ];
    }

    public const PHARMACOVIGILANCE_NOTICE = 'Possible adverse event: report it to your pharmacovigilance or drug-safety contact now, following your organization\'s procedure. Do not record patient details in the CRM.';
}
