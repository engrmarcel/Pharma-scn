<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Parties\Models\Party;
use App\Domain\Parties\Models\PartyContact;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Shared CRUD for suppliers and customers. Both models are tenant-scoped,
 * so ids from other organizations resolve to 404.
 */
abstract class PartyController extends Controller
{
    public function __construct(
        protected readonly TenantContext $tenant,
        protected readonly AuditLogger $audit,
    ) {}

    /** @return class-string<Party> */
    abstract protected function model(): string;

    /** suppliers | customers */
    abstract protected function permissionPrefix(): string;

    abstract protected function typeRules(): array;

    abstract protected function extraRules(): array;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView();

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'on_hold', 'inactive'])],
            'type' => ['nullable', 'string', 'max:32'],
        ]);
        $model = $this->model();

        $page = $this->visible($model::query())
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.addcslashes($v, '%_').'%')
                ->orWhere('code', 'like', addcslashes($v, '%_').'%')))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->orderBy('name')
            ->paginate(50);

        return response()->json($page->through(fn (Party $p) => $this->present($p)));
    }

    public function show(string $id): JsonResponse
    {
        $this->authorizeView();
        $model = $this->model();
        $party = $this->visible($model::query())->findOrFail($id);

        return response()->json(['data' => $this->present($party) + [
            'contacts' => $party->contacts()->orderByDesc('is_primary')->orderBy('name')->get()
                ->map(fn (PartyContact $c) => $c->only(['id', 'name', 'job_title', 'email', 'phone', 'is_primary'])),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize($this->permissionPrefix().'.manage');

        $data = $request->validate($this->rules() + [
            'code' => ['required', 'string', 'max:32', 'alpha_dash', Rule::unique($this->permissionPrefix())->where('organization_id', $this->tenant->id())],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', ...$this->typeRules()],
        ]);
        $data['currency_code'] ??= $this->tenant->organization()->country->currency_code;
        $this->assertNotSelfLink($data);

        $model = $this->model();
        $party = $model::query()->create($data);
        $this->audit->record(rtrim($this->permissionPrefix(), 's').'.created', $party, new: $data);

        return response()->json(['data' => $this->present($party->fresh())], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        Gate::authorize($this->permissionPrefix().'.manage');
        $party = $this->find($id);

        $data = $request->validate($this->rules() + [
            'code' => ['sometimes', 'string', 'max:32', 'alpha_dash', Rule::unique($this->permissionPrefix())->where('organization_id', $this->tenant->id())->ignore($party->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', ...$this->typeRules()],
        ]);
        $this->assertNotSelfLink($data);

        $original = $party->getAttributes();
        $party->fill($data)->save();
        [$old, $new] = $this->audit->diff($party, $original);
        if ($new !== []) {
            $this->audit->record(rtrim($this->permissionPrefix(), 's').'.updated', $party, $old, $new);
        }

        return response()->json(['data' => $this->present($party)]);
    }

    public function storeContact(Request $request, string $id): JsonResponse
    {
        Gate::authorize($this->permissionPrefix().'.manage');
        $party = $this->find($id);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        $contact = DB::transaction(function () use ($party, $data) {
            if ($data['is_primary'] ?? false) {
                $party->contacts()->update(['is_primary' => false]);
            }

            return PartyContact::query()->create($data + ['party_type' => $party->partyType(), 'party_id' => $party->id]);
        });
        $this->audit->record('party_contact.created', $party, new: ['contact_id' => $contact->id, 'name' => $contact->name]);

        return response()->json(['data' => $contact->only(['id', 'name', 'job_title', 'email', 'phone', 'is_primary'])], 201);
    }

    public function destroyContact(string $id, string $contactId): JsonResponse
    {
        Gate::authorize($this->permissionPrefix().'.manage');
        $party = $this->find($id);
        $contact = $party->contacts()->findOrFail($contactId);

        $contact->delete();
        $this->audit->record('party_contact.deleted', $party, old: ['contact_id' => $contactId, 'name' => $contact->name]);

        return response()->json(null, 204);
    }

    protected function authorizeView(): void
    {
        Gate::authorize($this->permissionPrefix().'.view');
    }

    /** Hook for narrower visibility (customers: territory assignments). */
    protected function visible($query)
    {
        return $query;
    }

    protected function present(Party $party): array
    {
        return collect($party->attributesToArray())->except(['organization_id'])->all();
    }

    private function find(string $id): Party
    {
        $model = $this->model();

        return $model::query()->findOrFail($id);
    }

    private function rules(): array
    {
        $money = ['nullable', 'decimal:0,4', 'min:0', 'max:999999999999999'];

        return [
            'linked_organization_id' => ['nullable', 'ulid', 'exists:organizations,id'],
            'tax_identification_number' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state_region' => ['nullable', 'string', 'max:100'],
            'country_code' => ['nullable', 'string', 'size:2', 'alpha'],
            'currency_code' => ['sometimes', 'string', 'size:3', 'alpha'],
            'payment_terms_days' => ['sometimes', 'integer', 'between:0,365'],
            'credit_limit' => $money,
            'status' => ['sometimes', Rule::in(['active', 'on_hold', 'inactive'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ] + $this->extraRules();
    }

    private function assertNotSelfLink(array $data): void
    {
        abort_if(($data['linked_organization_id'] ?? null) === $this->tenant->id(), 422, 'An organization cannot be its own trading party.');
    }
}
