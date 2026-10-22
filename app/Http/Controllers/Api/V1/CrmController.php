<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Crm\Models\CrmInteraction;
use App\Domain\Crm\Models\CustomerFeedback;
use App\Domain\Crm\Models\CustomerSegment;
use App\Domain\Crm\Models\CustomerSegmentMember;
use App\Domain\Crm\Models\DetailingMessage;
use App\Domain\Crm\Models\DetailingRecord;
use App\Domain\Crm\Services\ContactService;
use App\Domain\Crm\Services\CustomerTimeline;
use App\Domain\Crm\Services\DetailingService;
use App\Domain\Crm\Services\InteractionService;
use App\Domain\Crm\Services\SegmentService;
use App\Domain\Crm\Support\CrmRules;
use App\Domain\FieldForce\Models\FieldVisit;
use App\Domain\FieldForce\Services\FieldForceScope;
use App\Domain\Parties\Models\Customer;
use App\Domain\Parties\Models\PartyContact;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * CRM (spec §17): accounts and their professional contacts, segmentation,
 * interaction history, feedback and product detailing. Customers are always
 * reached through FieldForceScope, and activity through its activity scope.
 */
class CrmController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly FieldForceScope $scope,
    ) {}

    // ---- Accounts --------------------------------------------------------------------------

    /** Customers with their CRM profile and visit due dates (filters: q, segment_id, tier, territory_id, due). */
    public function customers(Request $request): JsonResponse
    {
        abort_unless($this->scope->canSeeCustomers(), 403);
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'segment_id' => ['nullable', 'ulid'], 'tier' => ['nullable', Rule::in(['A', 'B', 'C'])],
            'territory_id' => ['nullable', 'ulid'], 'due' => ['nullable', 'boolean']]);

        $lastVisit = FieldVisit::query()->whereColumn('customer_id', 'customers.id')->where('status', 'completed')->selectRaw('MAX(check_in_at)');
        $page = $this->scope->scopeCustomers(Customer::query(), 'id')
            ->addSelect(['customers.*', 'last_completed_visit_at' => $lastVisit])
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('name', 'like', '%'.addcslashes($v, '%_').'%')->orWhere('code', 'like', addcslashes($v, '%_').'%')))
            ->when($f['segment_id'] ?? null, fn ($q, $v) => $q->whereIn('id', CustomerSegmentMember::query()->where('segment_id', $v)->select('customer_id')))
            ->when($f['tier'] ?? null, fn ($q, $v) => $q->where('tier', $v))
            ->when($f['territory_id'] ?? null, fn ($q, $v) => $q->where('territory_id', $v))
            ->when($f['due'] ?? false, fn ($q) => $q->whereNotNull('visit_frequency_days')->where(fn ($w) => $w
                ->whereNotExists(fn ($s) => $s->from('field_visits')->whereColumn('customer_id', 'customers.id')->where('status', 'completed'))
                ->orWhereRaw('DATE_ADD((SELECT MAX(v.check_in_at) FROM field_visits v WHERE v.customer_id = customers.id AND v.status = ?), INTERVAL customers.visit_frequency_days DAY) <= ?',
                    ['completed', now()->toDateTimeString()])))
            ->orderBy('name')->paginate(100);

        return response()->json($page->through(fn (Customer $c) => $this->account($c)));
    }

    public function updateProfile(Request $request, string $id, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('crm.accounts.manage');
        $customer = $this->scope->visibleCustomer($id);
        $data = $request->validate(['tier' => ['sometimes', 'nullable', Rule::in(['A', 'B', 'C'])], 'visit_frequency_days' => ['sometimes', 'nullable', 'integer', 'between:1,365'],
            'potential_value' => ['sometimes', 'nullable', 'decimal:0,4', 'min:0', 'max:999999999999999']]);

        $original = $customer->getAttributes();
        $customer->forceFill($data)->save();
        [$old, $new] = $audit->diff($customer, $original);
        if ($new !== []) {
            $audit->record('customer.crm_profile_changed', $customer, $old, $new);
        }

        return response()->json(['data' => $this->account($customer->fresh())]);
    }

    public function timeline(Request $request, string $id, CustomerTimeline $timeline): JsonResponse
    {
        $customer = $this->scope->visibleCustomer($id);
        $f = $request->validate(['types' => ['nullable', 'array'], 'types.*' => [Rule::in(CustomerTimeline::TYPES)], 'before' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'between:1,200']]);

        return response()->json(['data' => $timeline->for($customer, $f['types'] ?? [], $f['before'] ?? null, (int) ($f['limit'] ?? 50))]);
    }

    // ---- Contacts ----------------------------------------------------------------------------

    public function contacts(string $id): JsonResponse
    {
        $customer = $this->scope->visibleCustomer($id);

        return response()->json(['data' => PartyContact::query()->where('party_type', 'customer')->where('party_id', $customer->id)
            ->orderByDesc('is_primary')->orderBy('name')->get()->map(fn ($c) => $this->contact($c))]);
    }

    public function storeContact(Request $request, string $id, ContactService $contacts): JsonResponse
    {
        $this->authorizeContactEditing();
        $customer = $this->scope->visibleCustomer($id);
        $data = $request->validate($this->contactRules(true) + [
            'contact_consent' => ['nullable', Rule::in(['granted', 'withdrawn', 'unknown'])],
            'consent_source' => ['nullable', 'required_if:contact_consent,granted,withdrawn', 'string', 'max:100'],
        ]);

        return response()->json(['data' => $this->contact($contacts->create($customer, $data))], 201);
    }

    public function updateContact(Request $request, string $id, ContactService $contacts): JsonResponse
    {
        $this->authorizeContactEditing();

        return response()->json(['data' => $this->contact($contacts->update($this->findContact($id), $request->validate($this->contactRules(false))))]);
    }

    public function contactConsent(Request $request, string $id, ContactService $contacts): JsonResponse
    {
        $this->authorizeContactEditing();
        $data = $request->validate(['contact_consent' => ['required', Rule::in(['granted', 'withdrawn'])], 'source' => ['required', 'string', 'max:100']]);

        return response()->json(['data' => $this->contact($contacts->setConsent($this->findContact($id), $data['contact_consent'], $data['source']))]);
    }

    public function anonymizeContact(Request $request, string $id, ContactService $contacts): JsonResponse
    {
        Gate::authorize('crm.accounts.manage');
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return response()->json(['data' => $this->contact($contacts->anonymize($this->findContact($id), $reason))]);
    }

    // ---- Segments ------------------------------------------------------------------------------

    public function segments(): JsonResponse
    {
        abort_unless($this->scope->canSeeCustomers(), 403);
        $counts = CustomerSegmentMember::query()->toBase()->groupBy('segment_id')->selectRaw('segment_id, count(*) as n')->pluck('n', 'segment_id');

        return response()->json(['data' => CustomerSegment::query()->orderBy('name')->get()->map(fn ($s) => $this->segment($s, (int) ($counts[$s->id] ?? 0)))]);
    }

    public function storeSegment(Request $request, SegmentService $segments): JsonResponse
    {
        Gate::authorize('crm.segments.manage');
        $data = $request->validate($this->segmentRules() + [
            'code' => ['required', 'string', 'max:32', 'alpha_dash'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(CustomerSegment::TYPES)],
        ]);

        return response()->json(['data' => $this->segment($segment = $segments->create($data, $request->user()), $this->memberCount($segment))], 201);
    }

    public function updateSegment(Request $request, string $id, SegmentService $segments): JsonResponse
    {
        Gate::authorize('crm.segments.manage');
        $segment = $segments->update(CustomerSegment::query()->findOrFail($id), $request->validate($this->segmentRules() + [
            'name' => ['sometimes', 'string', 'max:255'], 'status' => ['sometimes', Rule::in(['active', 'archived'])]]));

        return response()->json(['data' => $this->segment($segment, $this->memberCount($segment))]);
    }

    public function refreshSegment(string $id, SegmentService $segments): JsonResponse
    {
        Gate::authorize('crm.segments.manage');
        $segment = CustomerSegment::query()->findOrFail($id);
        $count = $segments->refresh($segment);

        return response()->json(['data' => $this->segment($segment->fresh(), $count)]);
    }

    /** Members the viewer may see (a restricted member sees only their own customers in the segment). */
    public function segmentMembers(string $id): JsonResponse
    {
        abort_unless($this->scope->canSeeCustomers(), 403);
        $segment = CustomerSegment::query()->findOrFail($id);
        $page = $this->scope->scopeCustomers(Customer::query(), 'id')
            ->whereIn('id', CustomerSegmentMember::query()->where('segment_id', $segment->id)->select('customer_id'))
            ->orderBy('name')->paginate(100);

        return response()->json($page->through(fn (Customer $c) => $this->account($c)));
    }

    public function changeSegmentMembers(Request $request, string $id, SegmentService $segments): JsonResponse
    {
        Gate::authorize('crm.segments.manage');
        $data = $request->validate(['add' => ['present', 'array', 'max:1000'], 'add.*' => ['ulid'], 'remove' => ['present', 'array', 'max:1000'], 'remove.*' => ['ulid']]);
        $segment = CustomerSegment::query()->findOrFail($id);
        $segments->changeMembers($segment, $data['add'], $data['remove']);

        return response()->json(['data' => $this->segment($segment, $this->memberCount($segment))]);
    }

    // ---- Interactions and feedback --------------------------------------------------------------

    public function interactions(Request $request): JsonResponse
    {
        abort_unless($this->scope->canSeeCustomers(), 403);
        $f = $request->validate($this->listFilters() + ['channel' => ['nullable', Rule::in(CrmInteraction::CHANNELS)]]);

        return response()->json($this->filtered($this->scope->scopeActivity(CrmInteraction::query()), $f, 'occurred_at', 'representative_id')
            ->when($f['channel'] ?? null, fn ($q, $v) => $q->where('channel', $v))->latest('occurred_at')->paginate(50));
    }

    public function storeInteraction(Request $request, InteractionService $service): JsonResponse
    {
        Gate::authorize('field_force.visits.capture');
        $data = $request->validate(CrmRules::interaction());
        $result = $service->log($data);

        return response()->json(['data' => $result['interaction'], 'meta' => ['replayed' => $result['replayed']]], $result['replayed'] ? 200 : 201);
    }

    public function feedback(Request $request): JsonResponse
    {
        abort_unless($this->scope->canSeeCustomers(), 403);
        $f = $request->validate($this->listFilters() + ['status' => ['nullable', Rule::in(['open', 'in_review', 'resolved'])],
            'category' => ['nullable', Rule::in(CustomerFeedback::CATEGORIES)], 'possible_adverse_event' => ['nullable', 'boolean']]);

        // Managers of feedback see it for every customer they can see; others see what they recorded or their team recorded.
        $query = Gate::allows('crm.feedback.manage')
            ? $this->scope->scopeCustomers(CustomerFeedback::query())
            : $this->scope->scopeActivity(CustomerFeedback::query(), 'recorded_by');

        return response()->json($this->filtered($query, $f, 'created_at', 'recorded_by')
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['category'] ?? null, fn ($q, $v) => $q->where('category', $v))
            ->when(isset($f['possible_adverse_event']), fn ($q) => $q->where('possible_adverse_event', (bool) $f['possible_adverse_event']))
            ->latest()->paginate(50));
    }

    public function storeFeedback(Request $request, InteractionService $service): JsonResponse
    {
        Gate::authorize('field_force.visits.capture');
        $data = $request->validate(CrmRules::feedback($this->tenant->id()));
        $result = $service->recordFeedback($data);
        $feedback = $result['feedback'];

        return response()->json(['data' => $feedback, 'meta' => array_filter(['replayed' => $result['replayed'],
            'pharmacovigilance_notice' => $feedback->possible_adverse_event
                ? CrmRules::PHARMACOVIGILANCE_NOTICE
                : null])], $result['replayed'] ? 200 : 201);
    }

    public function progressFeedback(Request $request, string $id, string $action, InteractionService $service): JsonResponse
    {
        $feedback = $this->scope->scopeCustomers(CustomerFeedback::query())->findOrFail($id);
        $data = $request->validate(['resolution' => [$action === 'resolve' ? 'required' : 'nullable', 'string', 'max:5000'],
            'pharmacovigilance_reference' => ['nullable', 'string', 'max:64']]);

        return response()->json(['data' => $service->progressFeedback($feedback, $action === 'resolve' ? 'resolved' : 'in_review',
            $data['resolution'] ?? null, $data['pharmacovigilance_reference'] ?? null)]);
    }

    // ---- Detailing ---------------------------------------------------------------------------------

    /** Approved, current messages for field users; all messages for detailing managers. */
    public function detailingMessages(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('crm.detailing.manage') || Gate::allows('field_force.visits.capture'), 403);
        $f = $request->validate(['product_id' => ['nullable', 'ulid'], 'status' => ['nullable', Rule::in(['draft', 'approved', 'retired'])]]);

        $query = DetailingMessage::query()->when($f['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v));
        if (! Gate::allows('crm.detailing.manage')) {
            $query->where('status', 'approved')->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', business_today()->toDateString()));
        } elseif ($f['status'] ?? null) {
            $query->where('status', $f['status']);
        }

        return response()->json(['data' => $query->orderBy('product_id')->orderBy('title')->get()]);
    }

    public function storeDetailingMessage(Request $request, DetailingService $service): JsonResponse
    {
        Gate::authorize('crm.detailing.manage');
        $data = $request->validate($this->messageRules() + ['product_id' => ['required', 'ulid'], 'title' => ['required', 'string', 'max:255'],
            'key_message' => ['required', 'string', 'max:5000']]);

        return response()->json(['data' => $service->createMessage($data, $request->user())], 201);
    }

    public function updateDetailingMessage(Request $request, string $id, DetailingService $service): JsonResponse
    {
        Gate::authorize('crm.detailing.manage');

        return response()->json(['data' => $service->updateMessage(DetailingMessage::query()->findOrFail($id), $request->validate($this->messageRules()
            + ['title' => ['sometimes', 'string', 'max:255'], 'key_message' => ['sometimes', 'string', 'max:5000']]))]);
    }

    public function detailingMessageAction(Request $request, string $id, string $action, DetailingService $service): JsonResponse
    {
        Gate::authorize('crm.detailing.manage');
        $message = DetailingMessage::query()->findOrFail($id);

        return response()->json(['data' => $action === 'approve' ? $service->approve($message, $request->user()) : $service->retire($message)]);
    }

    public function recordDetailing(Request $request, string $visitId, DetailingService $service): JsonResponse
    {
        Gate::authorize('field_force.visits.capture');
        $visit = $this->scope->scopeActivity(FieldVisit::query())->findOrFail($visitId);
        $data = $request->validate(CrmRules::detailing());
        $result = $service->record($visit, $data);

        return response()->json(['data' => $result['record'], 'meta' => ['replayed' => $result['replayed']]], $result['replayed'] ? 200 : 201);
    }

    public function detailingRecords(Request $request): JsonResponse
    {
        abort_unless($this->scope->canSeeCustomers(), 403);
        $f = $request->validate($this->listFilters() + ['product_id' => ['nullable', 'ulid']]);

        return response()->json($this->filtered($this->scope->scopeActivity(DetailingRecord::query()), $f, 'recorded_at', 'representative_id')
            ->when($f['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))->latest('recorded_at')->paginate(50));
    }

    // ---- Helpers ------------------------------------------------------------------------------------

    private function authorizeContactEditing(): void
    {
        abort_unless(Gate::allows('crm.accounts.manage') || Gate::allows('customers.manage') || Gate::allows('field_force.visits.capture'), 403);
    }

    private function findContact(string $id): PartyContact
    {
        $contact = PartyContact::query()->where('party_type', 'customer')->findOrFail($id);
        $this->scope->visibleCustomer($contact->party_id); // 404 when the customer is not visible

        return $contact;
    }

    private function memberCount(CustomerSegment $segment): int
    {
        return CustomerSegmentMember::query()->where('segment_id', $segment->id)->count();
    }

    private function filtered($query, array $f, string $dateColumn, string $userColumn)
    {
        return $query
            ->when($f['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($f['representative_id'] ?? null, fn ($q, $v) => $q->where($userColumn, $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where($dateColumn, '>=', $v.' 00:00:00'))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where($dateColumn, '<=', $v.' 23:59:59'));
    }

    private function listFilters(): array
    {
        return ['customer_id' => ['nullable', 'ulid'], 'representative_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']];
    }

    private function account(Customer $c): array
    {
        $last = $c->getAttribute('last_completed_visit_at');
        // Never visited: due now. Otherwise the last completed visit plus the call frequency.
        $due = $c->visit_frequency_days ? ($last ? now()->parse($last)->startOfDay()->addDays($c->visit_frequency_days) : business_today()) : null;

        return $c->only(['id', 'code', 'name', 'type', 'status', 'city', 'territory_id', 'assigned_representative_id', 'tier', 'visit_frequency_days', 'potential_value'])
            + ['last_completed_visit_at' => $last ? now()->parse($last)->toIso8601String() : null, 'next_visit_due' => $due?->toDateString(),
                'visit_overdue' => $due !== null && $due->lte(business_today())];
    }

    private function contact(PartyContact $c): array
    {
        return $c->only(['id', 'party_id', 'name', 'job_title', 'professional_type', 'specialty', 'professional_licence', 'email', 'phone',
            'preferred_contact_method', 'is_primary', 'contact_consent'])
            + ['consent_recorded_at' => $c->consent_recorded_at?->toIso8601String(), 'consent_source' => $c->consent_source,
                'anonymized' => $c->anonymized_at !== null];
    }

    private function segment(CustomerSegment $s, int $members): array
    {
        return $s->only(['id', 'code', 'name', 'description', 'type', 'criteria', 'status']) + ['members' => $members,
            'last_refreshed_at' => $s->last_refreshed_at?->toIso8601String()];
    }

    private function contactRules(bool $create): array
    {
        return [
            'name' => [$create ? 'required' : 'sometimes', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'professional_type' => ['nullable', Rule::in(ContactService::PROFESSIONAL_TYPES)],
            'specialty' => ['nullable', 'string', 'max:100'],
            'professional_licence' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'preferred_contact_method' => ['nullable', Rule::in(['visit', 'phone', 'email', 'none'])],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    private function segmentRules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:2000'],
            'criteria' => ['nullable', 'array'],
            'criteria.customer_types' => ['sometimes', 'array'],
            'criteria.customer_types.*' => [Rule::in(Customer::TYPES)],
            'criteria.territory_ids' => ['sometimes', 'array'],
            'criteria.territory_ids.*' => ['ulid'],
            'criteria.tiers' => ['sometimes', 'array'],
            'criteria.tiers.*' => [Rule::in(['A', 'B', 'C'])],
            'criteria.cities' => ['sometimes', 'array'],
            'criteria.cities.*' => ['string', 'max:100'],
            'criteria.not_visited_days' => ['sometimes', 'integer', 'between:1,3650'],
            'criteria.min_revenue_12m' => ['sometimes', 'numeric', 'min:0'],
            'criteria.max_revenue_12m' => ['sometimes', 'numeric', 'min:0'],
        ];
    }

    private function messageRules(): array
    {
        return ['approval_reference' => ['nullable', 'string', 'max:64'], 'valid_until' => ['nullable', 'date', 'after_or_equal:today']];
    }
}
