<?php

namespace App\Domain\Pos\Services;

use App\Domain\Catalogue\Models\ProductClassification;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

/**
 * Dispensing controls (spec §11.1). Products classified prescription-only or
 * controlled in the organization's country can only be sold by a user with
 * the dispensing permission (a pharmacist), with prescription details
 * recorded. Organizations can turn the control off only where their
 * jurisdiction allows (pos.enforce_prescription_controls).
 *
 * This records evidence of the professional check; it does not replace it.
 */
class PrescriptionGuard
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function classification(string $productId): ?string
    {
        $organization = $this->tenant->organization();
        $today = today()->toDateString();

        return ProductClassification::query()->where('product_id', $productId)->where('country_code', $organization->country_code)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->orderByDesc('effective_from')->value('classification');
    }

    /** @return array<string, mixed>|null the prescription evidence to store */
    public function check(string $productId, ?array $prescription, int $line): ?array
    {
        $class = $this->classification($productId);
        $enforced = (bool) data_get($this->tenant->organization()->settings, 'pos.enforce_prescription_controls', true);

        if (! in_array($class, ['prescription_only', 'controlled'], true) || ! $enforced) {
            return $prescription ?: null;
        }

        if (! Gate::allows('pos.dispense.prescription')) {
            throw new BusinessRuleViolation('dispensing_permission_required', 'This product must be dispensed by an authorized pharmacist.', 403, ['line' => $line, 'classification' => $class]);
        }
        if (blank($prescription['reference'] ?? null) || blank($prescription['prescriber_name'] ?? null) || blank($prescription['prescribed_on'] ?? null)) {
            throw new BusinessRuleViolation('prescription_required', 'Record the prescription reference, prescriber and date for this product.', 422, ['line' => $line, 'classification' => $class]);
        }

        return [
            'classification' => $class,
            'reference' => $prescription['reference'],
            'prescriber_name' => $prescription['prescriber_name'],
            'prescribed_on' => $prescription['prescribed_on'],
            'checked_by_user_id' => auth()->id(),
        ];
    }
}
