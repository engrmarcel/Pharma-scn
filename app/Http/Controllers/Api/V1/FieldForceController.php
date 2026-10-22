<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\FieldForce\Models\FieldFollowUp;
use App\Domain\FieldForce\Models\FieldOrder;
use App\Domain\FieldForce\Models\FieldVisit;
use App\Domain\FieldForce\Models\Territory;
use App\Domain\FieldForce\Models\TerritoryMember;
use App\Domain\FieldForce\Services\FieldForceReports;
use App\Domain\FieldForce\Services\FieldForceScope;
use App\Domain\FieldForce\Services\FieldOrderService;
use App\Domain\FieldForce\Services\FollowUpService;
use App\Domain\FieldForce\Services\MobileCatalogue;
use App\Domain\FieldForce\Services\TerritoryService;
use App\Domain\FieldForce\Services\VisitService;
use App\Domain\FieldForce\Support\FieldForceRules;
use App\Domain\Parties\Models\Customer;
use App\Domain\Parties\Models\PartyContact;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Field force / light CRM API for the mobile app and supervisors (spec §12).
 * Records created from a device carry a client_reference; replaying the same
 * request returns the stored record (200) instead of creating a duplicate.
 */
class FieldForceController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly FieldForceScope $scope,
    ) {}

    // ---- App bootstrap -----------------------------------------------------------

    public function me(Request $request): JsonResponse
    {
        abort_unless($this->isFieldUser(), 403);
        $abilities = ['customers.view', 'customers.view_assigned', 'field_force.visits.capture', 'field_force.orders.capture', 'field_force.competitor.record',
            'field_force.team.view', 'field_force.view_all', 'field_force.reports.view', 'field_force.territories.manage'];

        return response()->json(['data' => [
            'user' => $request->user()->only(['id', 'name', 'email']),
            'organization' => $this->tenant->organization()->only(['id', 'name', 'country_code']),
            'permissions' => array_values(array_filter($abilities, fn ($a) => Gate::allows($a))),
            'territories' => Territory::query()->whereIn('id', $this->scope->territoryIds())->get(['id', 'code', 'name', 'warehouse_id'])
                ->map(fn ($t) => $t->toArray() + ['role' => TerritoryMember::query()->where('territory_id', $t->id)->where('user_id', $request->user()->id)->pluck('role')->all()]),
            'settings' => [
                'verification_radius_m' => config('field_force.verification_radius_m'),
                'max_accuracy_m' => config('field_force.max_accuracy_m'),
                'max_offline_age_days' => config('field_force.max_offline_age_days'),
                'location_notice' => 'Your location is read only when you check in or check out of a visit. It is never tracked in the background.',
            ],
            'server_time' => now()->toIso8601String(),
        ]]);
    }

    // ---- Territories and assignments ---------------------------------------------

    public function territories(): JsonResponse
    {
        abort_unless($this->isFieldUser(), 403);
        $query = Territory::query()->with('members.user:id,name')->orderBy('code');
        if (! Gate::allows('field_force.territories.manage') && ! Gate::allows('field_force.view_all')) {
            $query->whereIn('id', $this->scope->territoryIds());
        }

        return response()->json(['data' => $query->get()->map(fn (Territory $t) => $this->presentTerritory($t))]);
    }

    public function storeTerritory(Request $request, TerritoryService $service): JsonResponse
    {
        Gate::authorize('field_force.territories.manage');
        $data = $request->validate($this->territoryRules() + [
            'code' => ['required', 'string', 'max:32', 'alpha_dash', Rule::unique('territories')->where('organization_id', $this->tenant->id())],
            'name' => ['required', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->presentTerritory($service->save(null, $data)->load('members.user:id,name'))], 201);
    }

    public function updateTerritory(Request $request, string $id, TerritoryService $service): JsonResponse
    {
        Gate::authorize('field_force.territories.manage');
        $territory = Territory::query()->findOrFail($id);
        $data = $request->validate($this->territoryRules() + [
            'code' => ['sometimes', 'string', 'max:32', 'alpha_dash', Rule::unique('territories')->where('organization_id', $this->tenant->id())->ignore($territory->id)],
            'name' => ['sometimes', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->presentTerritory($service->save($territory, $data)->load('members.user:id,name'))]);
    }

    public function setTerritoryMembers(Request $request, string $id, TerritoryService $service): JsonResponse
    {
        Gate::authorize('field_force.territories.manage');
        $territory = Territory::query()->findOrFail($id);
        $data = $request->validate([
            'members' => ['present', 'array', 'max:500'],
            'members.*.user_id' => ['required', 'integer'],
            'members.*.role' => ['required', Rule::in(TerritoryMember::ROLES)],
        ]);

        return response()->json(['data' => $this->presentTerritory($service->setMembers($territory, $data['members'])->load('members.user:id,name'))]);
    }

    public function assignCustomers(Request $request, TerritoryService $service): JsonResponse
    {
        Gate::authorize('field_force.territories.manage');
        $data = $request->validate([
            'customer_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'customer_ids.*' => ['required', 'ulid'],
            'territory_id' => ['present', 'nullable', 'ulid', Rule::exists('territories', 'id')->where('organization_id', $this->tenant->id())],
            'assigned_representative_id' => ['sometimes', 'nullable', 'integer'],
        ]);
        $count = $service->assignCustomers($data['territory_id'] ? Territory::query()->findOrFail($data['territory_id']) : null, $data['customer_ids'],
            $data['assigned_representative_id'] ?? null, array_key_exists('assigned_representative_id', $data));

        return response()->json(['data' => ['customers_updated' => $count]]);
    }

    // ---- Customer directory --------------------------------------------------------

    public function customers(Request $request): JsonResponse
    {
        abort_unless($this->scope->canSeeCustomers(), 403);
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'territory_id' => ['nullable', 'ulid'], 'updated_since' => ['nullable', 'date']]);

        $page = $this->scope->scopeCustomers(Customer::query(), 'id')
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('name', 'like', '%'.addcslashes($v, '%_').'%')->orWhere('code', 'like', addcslashes($v, '%_').'%')))
            ->when($f['territory_id'] ?? null, fn ($q, $v) => $q->where('territory_id', $v))
            ->when($f['updated_since'] ?? null, fn ($q, $v) => $q->where('updated_at', '>=', $v))
            ->addSelect(['last_visit_at' => FieldVisit::query()->whereColumn('customer_id', 'customers.id')->selectRaw('MAX(check_in_at)')])
            ->withCount(['contacts'])
            ->orderBy('name')
            ->paginate(100);

        return response()->json($page->through(fn (Customer $c) => $this->presentCustomer($c)));
    }

    public function customer(string $id): JsonResponse
    {
        $customer = $this->scope->visibleCustomer($id);
        $visits = $this->scope->scopeActivity(FieldVisit::query()->where('customer_id', $customer->id))->latest('check_in_at')->limit(20)
            ->get(['id', 'number', 'representative_id', 'visit_type', 'status', 'check_in_at', 'check_out_at', 'check_in_verification', 'outcome']);
        $orders = $this->scope->scopeActivity(FieldOrder::query()->where('customer_id', $customer->id))->latest('captured_at')->limit(20)
            ->get(['id', 'number', 'representative_id', 'status', 'captured_at', 'sales_order_id']);

        return response()->json(['data' => $this->presentCustomer($customer) + [
            'contacts' => PartyContact::query()->where('party_type', 'customer')->where('party_id', $customer->id)->orderByDesc('is_primary')->get(['id', 'name', 'job_title', 'email', 'phone', 'is_primary']),
            'location_history' => FieldVisit::query()->where('customer_id', $customer->id)->whereNotNull('check_in_verification')
                ->latest('check_in_at')->limit(10)->get(['id', 'check_in_at', 'check_in_verification', 'check_in_distance_m']),
            'recent_visits' => $visits,
            'recent_orders' => $orders,
            'open_follow_ups' => $this->scope->scopeActivity(FieldFollowUp::query()->where('customer_id', $customer->id)->where('status', 'open'), 'assigned_to')
                ->orderBy('due_on')->get(['id', 'title', 'due_on', 'assigned_to']),
        ]]);
    }

    public function catalogue(Request $request, MobileCatalogue $catalogue): JsonResponse
    {
        Gate::authorize('field_force.orders.capture');
        $data = $request->validate([
            'customer_id' => ['required', 'ulid'],
            'warehouse_id' => ['nullable', 'ulid', Rule::exists('warehouses', 'id')->where('organization_id', $this->tenant->id())],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($catalogue->forCustomer($this->scope->visibleCustomer($data['customer_id']), $data['warehouse_id'] ?? null, $data['q'] ?? null, (int) ($data['page'] ?? 1)));
    }

    // ---- Visits ---------------------------------------------------------------------

    public function visits(Request $request): JsonResponse
    {
        abort_unless($this->isFieldUser(), 403);
        $f = $request->validate($this->listFilters() + ['status' => ['nullable', Rule::in(['in_progress', 'completed', 'cancelled'])]]);

        $page = $this->scope->scopeActivity(FieldVisit::query())
            ->when($f['representative_id'] ?? null, fn ($q, $v) => $q->where('representative_id', $v))
            ->when($f['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('check_in_at', '>=', $v.' 00:00:00'))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('check_in_at', '<=', $v.' 23:59:59'))
            ->latest('check_in_at')->paginate(50);

        return response()->json($page);
    }

    public function visit(string $id): JsonResponse
    {
        return response()->json(['data' => $this->findVisit($id)->load('activities')]);
    }

    public function checkIn(Request $request, VisitService $visits): JsonResponse
    {
        Gate::authorize('field_force.visits.capture');
        $data = $request->validate(FieldForceRules::checkIn());

        $result = $visits->checkIn($data);

        return response()->json(['data' => $result['visit']->load('activities'), 'meta' => ['replayed' => $result['replayed']]], $result['replayed'] ? 200 : 201);
    }

    public function updateVisit(Request $request, string $id, VisitService $visits): JsonResponse
    {
        Gate::authorize('field_force.visits.capture');
        $data = $request->validate(FieldForceRules::visitDetails($this->tenant->id()));

        return response()->json(['data' => $visits->updateDetails($this->findVisit($id), $data)]);
    }

    public function addActivities(Request $request, string $id, VisitService $visits): JsonResponse
    {
        Gate::authorize('field_force.visits.capture');
        $data = $request->validate(FieldForceRules::activities($this->tenant->id(), true));

        return response()->json(['data' => $visits->addActivities($this->findVisit($id), $data['activities'])], 201);
    }

    public function checkOut(Request $request, string $id, VisitService $visits): JsonResponse
    {
        Gate::authorize('field_force.visits.capture');
        $data = $request->validate(FieldForceRules::checkOut($this->tenant->id()));

        return response()->json(['data' => $visits->checkOut($this->findVisit($id), $data)]);
    }

    public function cancelVisit(Request $request, string $id, VisitService $visits): JsonResponse
    {
        Gate::authorize('field_force.visits.capture');
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return response()->json(['data' => $visits->cancel($this->findVisit($id), $data['reason'])]);
    }

    // ---- Mobile orders ------------------------------------------------------------

    public function orders(Request $request, FieldOrderService $orders): JsonResponse
    {
        abort_unless($this->isFieldUser(), 403);
        $f = $request->validate($this->listFilters() + ['status' => ['nullable', Rule::in(['draft', 'submitted', 'accepted', 'rejected', 'cancelled'])]]);

        $page = $this->scope->scopeActivity(FieldOrder::query()->with('lines'))
            ->when($f['representative_id'] ?? null, fn ($q, $v) => $q->where('representative_id', $v))
            ->when($f['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('captured_at', '>=', $v.' 00:00:00'))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('captured_at', '<=', $v.' 23:59:59'))
            ->latest('captured_at')->paginate(50);

        return response()->json($page->through(fn (FieldOrder $o) => $orders->present($o)));
    }

    public function order(string $id, FieldOrderService $orders): JsonResponse
    {
        return response()->json(['data' => $orders->present($this->findOrder($id))]);
    }

    public function storeOrder(Request $request, FieldOrderService $orders): JsonResponse
    {
        Gate::authorize('field_force.orders.capture');
        $data = $request->validate(FieldForceRules::orderCapture($this->tenant->id()));
        $result = $orders->capture($data);

        return response()->json(['data' => $orders->present($result['order']), 'meta' => ['replayed' => $result['replayed']]], $result['replayed'] ? 200 : 201);
    }

    public function updateOrder(Request $request, string $id, FieldOrderService $orders): JsonResponse
    {
        Gate::authorize('field_force.orders.capture');

        return response()->json(['data' => $orders->present($orders->update($this->findOrder($id), $request->validate(FieldForceRules::order($this->tenant->id(), false))))]);
    }

    public function submitOrder(string $id, FieldOrderService $orders): JsonResponse
    {
        Gate::authorize('field_force.orders.capture');

        return response()->json(['data' => $orders->present($orders->submit($this->findOrder($id)))]);
    }

    public function cancelOrder(Request $request, string $id, FieldOrderService $orders): JsonResponse
    {
        Gate::authorize('field_force.orders.capture');
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return response()->json(['data' => $orders->present($orders->cancel($this->findOrder($id), $data['reason']))]);
    }

    // ---- Follow-ups -----------------------------------------------------------------

    public function followUps(Request $request): JsonResponse
    {
        abort_unless($this->isFieldUser(), 403);
        $f = $request->validate(['status' => ['nullable', Rule::in(['open', 'done', 'cancelled'])], 'customer_id' => ['nullable', 'ulid'],
            'assigned_to' => ['nullable', 'integer'], 'due_before' => ['nullable', 'date']]);

        return response()->json($this->scope->scopeActivity(FieldFollowUp::query(), 'assigned_to')
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($f['assigned_to'] ?? null, fn ($q, $v) => $q->where('assigned_to', $v))
            ->when($f['due_before'] ?? null, fn ($q, $v) => $q->where('due_on', '<=', $v))
            ->orderBy('due_on')->paginate(100));
    }

    public function storeFollowUp(Request $request, FollowUpService $followUps): JsonResponse
    {
        Gate::authorize('field_force.visits.capture');
        $data = $request->validate([
            'client_reference' => ['nullable', 'string', 'max:64'],
            'customer_id' => ['required', 'ulid'],
            'field_visit_id' => ['nullable', 'ulid', Rule::exists('field_visits', 'id')->where('organization_id', $this->tenant->id())],
            'assigned_to' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'due_on' => ['required', 'date'],
        ]);
        $result = $followUps->create($data);

        return response()->json(['data' => $result['follow_up'], 'meta' => ['replayed' => $result['replayed']]], $result['replayed'] ? 200 : 201);
    }

    public function closeFollowUp(Request $request, string $id, string $action, FollowUpService $followUps): JsonResponse
    {
        Gate::authorize('field_force.visits.capture');
        $data = $request->validate(['outcome' => [$action === 'complete' ? 'required' : 'nullable', 'string', 'max:5000']]);
        $followUp = $this->scope->scopeActivity(FieldFollowUp::query(), 'assigned_to')->findOrFail($id);

        return response()->json(['data' => $followUps->close($followUp, $action === 'complete' ? 'done' : 'cancelled', $data['outcome'] ?? null)]);
    }

    // ---- Reports ----------------------------------------------------------------------

    public function activityReport(Request $request, FieldForceReports $reports): JsonResponse
    {
        Gate::authorize('field_force.reports.view');
        $d = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        return response()->json(['data' => $reports->activity($d['from'] ?? today()->startOfMonth()->toDateString(), $d['to'] ?? today()->toDateString())]);
    }

    // ---- Helpers ------------------------------------------------------------------------

    private function isFieldUser(): bool
    {
        foreach (['field_force.visits.capture', 'field_force.orders.capture', 'field_force.team.view', 'field_force.view_all', 'field_force.reports.view', 'field_force.territories.manage'] as $ability) {
            if (Gate::allows($ability)) {
                return true;
            }
        }

        return false;
    }

    private function findVisit(string $id): FieldVisit
    {
        abort_unless($this->isFieldUser(), 403);

        return $this->scope->scopeActivity(FieldVisit::query())->findOrFail($id);
    }

    private function findOrder(string $id): FieldOrder
    {
        abort_unless($this->isFieldUser(), 403);

        return $this->scope->scopeActivity(FieldOrder::query())->findOrFail($id);
    }

    private function presentTerritory(Territory $t): array
    {
        return $t->only(['id', 'code', 'name', 'parent_id', 'warehouse_id', 'description', 'status']) + [
            'members' => $t->members->map(fn ($m) => ['user_id' => $m->user_id, 'name' => $m->user?->name, 'role' => $m->role])->values(),
            'customer_count' => Customer::query()->where('territory_id', $t->id)->count(),
        ];
    }

    private function presentCustomer(Customer $c): array
    {
        return $c->only(['id', 'code', 'name', 'type', 'status', 'email', 'phone', 'address_line1', 'city', 'state_region', 'country_code',
            'licence_reference', 'latitude', 'longitude', 'territory_id', 'assigned_representative_id', 'currency_code', 'updated_at'])
            + ['last_visit_at' => $c->getAttribute('last_visit_at'), 'contacts_count' => $c->getAttribute('contacts_count')];
    }

    private function territoryRules(): array
    {
        return [
            'parent_id' => ['nullable', 'ulid', Rule::exists('territories', 'id')->where('organization_id', $this->tenant->id())],
            'warehouse_id' => ['nullable', 'ulid', Rule::exists('warehouses', 'id')->where('organization_id', $this->tenant->id())],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    private function listFilters(): array
    {
        return [
            'representative_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'ulid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ];
    }
}
