<?php

namespace App\Domain\Catalogue\Services;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Catalogue\Models\ProductChangeRequest;
use App\Domain\Catalogue\Support\ProductRules;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Platform\Services\AuditLogger;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Governance for the shared catalogue: organizations propose, platform
 * catalogue reviewers decide. Approval goes through ProductCatalogueService,
 * so proposals obey exactly the same rules as direct edits.
 */
class ProductChangeRequestService
{
    public function __construct(
        private readonly ProductCatalogueService $catalogue,
        private readonly AuditLogger $audit,
    ) {}

    public function submit(Organization $organization, User $user, array $payload, ?Product $product = null, ?int $baseVersion = null): ProductChangeRequest
    {
        if (! $organization->isVerified()) {
            throw new BusinessRuleViolation('organization_not_verified', 'Only verified organizations can propose catalogue changes.', 403);
        }

        // Proposers cannot set the lifecycle status of a canonical product.
        unset($payload['status']);
        Validator::make($payload, ProductRules::rules(partial: $product !== null))->validate();

        if ($product !== null && $baseVersion === null) {
            throw new BusinessRuleViolation('base_version_required', 'Updates must state the product version they were based on.');
        }

        $request = ProductChangeRequest::query()->create([
            'organization_id' => $organization->id,
            'product_id' => $product?->id,
            'type' => $product ? 'update' : 'create',
            'payload' => $payload,
            'base_version' => $product ? $baseVersion : null,
            'status' => 'pending',
            'submitted_by' => $user->id,
        ]);

        $this->audit->record('product_change_request.submitted', $request, new: ['type' => $request->type, 'product_id' => $request->product_id]);

        return $request;
    }

    public function approve(ProductChangeRequest $request, User $reviewer, ?string $notes = null): Product
    {
        $this->assertReviewable($request, $reviewer);

        return DB::transaction(function () use ($request, $reviewer, $notes) {
            // Re-validate: reference data may have changed since submission.
            Validator::make($request->payload, ProductRules::rules(partial: $request->type === 'update'))->validate();

            // Approving a proposal publishes the product.
            $product = $request->type === 'create'
                ? $this->catalogue->create($request->payload + ['status' => 'active'], $reviewer, $request->organization_id)
                : $this->catalogue->update($request->product, $request->payload, $request->base_version, $reviewer, $request->organization_id);

            $request->forceFill([
                'status' => 'approved',
                'product_id' => $product->id,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ])->save();

            $this->audit->record('product_change_request.approved', $request, new: ['product_id' => $product->id], organizationId: $request->organization_id);

            return $product;
        });
    }

    public function reject(ProductChangeRequest $request, User $reviewer, string $notes): ProductChangeRequest
    {
        $this->assertReviewable($request, $reviewer);

        $request->forceFill([
            'status' => 'rejected',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ])->save();

        $this->audit->record('product_change_request.rejected', $request, new: ['notes' => $notes], organizationId: $request->organization_id);

        return $request;
    }

    private function assertReviewable(ProductChangeRequest $request, User $reviewer): void
    {
        if ($request->status !== 'pending') {
            throw new BusinessRuleViolation('change_request_not_pending', 'This change request has already been decided.', 409);
        }

        $isMember = OrganizationMembership::query()
            ->where('organization_id', $request->organization_id)
            ->where('user_id', $reviewer->id)
            ->exists();

        if ($isMember) {
            throw new BusinessRuleViolation('review_conflict_of_interest', 'Reviewers cannot decide requests from an organization they belong to.', 403);
        }
    }
}
