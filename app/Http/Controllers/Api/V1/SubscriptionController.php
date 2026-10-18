<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Models\OrganizationSubscription;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Services\EntitlementService;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /** Tenant view of its own plan, entitlements and usage. */
    public function current(TenantContext $tenant): JsonResponse
    {
        Gate::authorize('organization.billing.view');

        return response()->json(['data' => $this->summary($tenant->organization())]);
    }

    public function plans(): JsonResponse
    {
        Gate::authorize('platform.billing.manage');

        return response()->json(['data' => SubscriptionPlan::query()->orderBy('code')->get()
            ->map(fn (SubscriptionPlan $p) => $p->only(['id', 'code', 'name', 'description', 'billing_period', 'price', 'currency_code', 'trial_days', 'is_public', 'is_active']) + ['entitlements' => $p->entitlementMap()])]);
    }

    public function updatePlan(Request $request, SubscriptionPlan $plan, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('platform.billing.manage');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'billing_period' => ['sometimes', Rule::in(['monthly', 'annual', 'custom'])],
            'price' => ['sometimes', 'nullable', 'decimal:0,4', 'min:0'],
            'currency_code' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
            'trial_days' => ['sometimes', 'integer', 'between:0,365'],
            'is_public' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'entitlements' => ['sometimes', 'array'],
            'entitlements.*.enabled' => ['required', 'boolean'],
            'entitlements.*.limit' => ['nullable', 'integer', 'min:0'],
        ]);

        $this->assertKnownKeys(array_keys($data['entitlements'] ?? []));
        $before = $plan->only(array_keys($data)) + ['entitlements' => $plan->entitlementMap()];

        $plan->fill(collect($data)->except('entitlements')->all())->save();
        foreach ($data['entitlements'] ?? [] as $key => $value) {
            $plan->setEntitlement($key, $value['enabled'], $value['limit'] ?? null);
        }
        $audit->record('subscription_plan.updated', $plan, $before, $data, organizationId: false);

        return response()->json(['data' => $plan->only(['id', 'code', 'name', 'price', 'currency_code']) + ['entitlements' => $plan->entitlementMap()]]);
    }

    public function showForOrganization(Organization $organization): JsonResponse
    {
        Gate::authorize('platform.billing.manage');

        return response()->json(['data' => $this->summary($organization) + [
            'history' => OrganizationSubscription::query()->with('plan')->where('organization_id', $organization->id)->latest('starts_at')->orderByDesc('id')->get()
                ->map(fn ($s) => ['plan' => $s->plan->code, 'status' => $s->status, 'starts_at' => $s->starts_at->toIso8601String(), 'ended_at' => $s->ended_at?->toIso8601String()]),
        ]]);
    }

    public function changeForOrganization(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('platform.billing.manage');

        $data = $request->validate([
            'plan_code' => ['sometimes', 'string', Rule::exists('subscription_plans', 'code')->where('is_active', true)],
            'status' => ['sometimes', Rule::in(['trialing', 'active', 'past_due', 'grace', 'suspended', 'cancelled', 'expired'])],
        ]);
        abort_if($data === [], 422, 'Provide plan_code and/or status.');

        if (isset($data['plan_code'])) {
            $this->entitlements->subscribe($organization, SubscriptionPlan::query()->where('code', $data['plan_code'])->firstOrFail(), $request->user(), $data['status'] ?? null);
        } else {
            $this->entitlements->setStatus($organization, $data['status'], $request->user());
        }

        return response()->json(['data' => $this->summary($organization)]);
    }

    public function overrideForOrganization(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('platform.billing.manage');

        $data = $request->validate([
            'key' => ['required', 'string'],
            'enabled' => ['required', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $this->assertKnownKeys([$data['key']]);

        $this->entitlements->setOverride($organization, $data['key'], $data['enabled'], $data['limit'] ?? null, $data['reason'], $request->user());

        return response()->json(['data' => $this->summary($organization)]);
    }

    private function summary(Organization $organization): array
    {
        $subscription = $this->entitlements->currentSubscription($organization);

        return [
            'plan' => $subscription?->plan?->only(['code', 'name', 'billing_period', 'price', 'currency_code']),
            'status' => $subscription?->status,
            'operational' => (bool) $subscription?->isOperational(),
            'trial_ends_at' => $subscription?->trial_ends_at?->toIso8601String(),
            'entitlements' => $this->entitlements->entitlements($organization),
            'usage' => $this->entitlements->usage($organization),
        ];
    }

    private function assertKnownKeys(array $keys): void
    {
        $unknown = array_diff($keys, array_keys(config('entitlements.keys')));
        abort_if($unknown !== [], 422, 'Unknown entitlement keys: '.implode(', ', $unknown));
    }
}
