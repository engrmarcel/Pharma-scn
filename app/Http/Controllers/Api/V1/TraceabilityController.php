<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Traceability\Models\TraceEvent;
use App\Domain\Traceability\Models\TraceIdentifier;
use App\Domain\Traceability\Services\EpcisExporter;
use App\Domain\Traceability\Services\IdentifierService;
use App\Domain\Traceability\Services\ScanService;
use App\Domain\Traceability\Services\TraceChain;
use App\Domain\Traceability\Services\TraceService;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Traceability (spec §16): lot history, corrections, identifiers and scanning.
 * A shared platform service: every organization records events through its
 * stock movements; these endpoints read and amend what the caller may see.
 */
class TraceabilityController extends Controller
{
    public function __construct(private readonly TenantContext $tenant, private readonly TraceService $trace) {}

    // ---- Lot history and events ------------------------------------------------------------

    public function lot(Request $request): JsonResponse
    {
        $this->authorizeView();
        $d = $request->validate(['product_id' => ['required', 'ulid'], 'batch_number' => ['required', 'string', 'max:64']]);

        return response()->json(['data' => $this->trace->lot($d['product_id'], $d['batch_number'])]);
    }

    public function events(Request $request): JsonResponse
    {
        $this->authorizeView();
        $f = $request->validate(['event_type' => ['nullable', Rule::in(TraceEvent::TYPES)], 'product_id' => ['nullable', 'ulid'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $page = $this->trace->visible()
            ->when($f['event_type'] ?? null, fn ($q, $v) => $q->where('event_type', $v))
            ->when($f['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('event_time', '>=', $v.' 00:00:00'))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('event_time', '<=', $v.' 23:59:59'))
            ->orderByDesc('event_time')->orderByDesc('id')->paginate(100);

        return response()->json(['data' => $this->trace->present(collect($page->items())),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function event(string $id): JsonResponse
    {
        $this->authorizeView();

        return response()->json(['data' => $this->trace->present(collect([$this->trace->visible()->findOrFail($id)]))[0]]);
    }

    public function correct(Request $request, string $id): JsonResponse
    {
        Gate::authorize('traceability.manage');
        $event = $this->trace->visible()->findOrFail($id);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'changes' => ['required', 'array', 'min:1'],
            'changes.quantity' => ['sometimes', 'decimal:0,4', 'min:0'],
            'changes.batch_number' => ['sometimes', 'string', 'max:64'],
            'changes.serial' => ['sometimes', 'nullable', 'string', 'max:64'],
            'changes.event_time' => ['sometimes', 'date'],
            'changes.source_location' => ['sometimes', 'nullable', 'string', 'max:100'],
            'changes.destination_location' => ['sometimes', 'nullable', 'string', 'max:100'],
            'changes.counterparty_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $correction = $this->trace->correct($event, $data['changes'], $data['reason']);

        return response()->json(['data' => $this->trace->present(collect([$correction]))[0]], 201);
    }

    public function reverse(Request $request, string $id): JsonResponse
    {
        Gate::authorize('traceability.manage');
        $reversal = $this->trace->reverse($this->trace->visible()->findOrFail($id), $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason']);

        return response()->json(['data' => $this->trace->present(collect([$reversal]))[0]], 201);
    }

    /** Recomputes the hash chain of the caller's organization (or, for platform staff, of the given organization). */
    public function integrity(TraceChain $chain, ?string $organizationId = null): JsonResponse
    {
        if ($organizationId !== null) {
            Gate::authorize('traceability.view_all');
        } else {
            $this->authorizeView();
        }

        return response()->json(['data' => ['organization_id' => $organizationId ?? $this->tenant->id()] + $chain->verify($organizationId ?? $this->tenant->id())]);
    }

    public function verify(Request $request, string $id): JsonResponse
    {
        $event = $this->trace->visible()->findOrFail($id);
        $d = $request->validate([
            'level' => ['required', Rule::in(['independently_verified', 'disputed'])],
            'method' => ['required', Rule::in(['document_review', 'physical_inspection', 'regulator_confirmation'])],
            'evidence' => ['required', 'string', 'max:5000'],
        ]);
        $verification = $this->trace->verify($event, $d['level'], $d['method'], $d['evidence'], $request->user());

        return response()->json(['data' => $verification->only(['id', 'trace_event_id', 'level', 'method', 'evidence', 'created_at'])], 201);
    }

    /** The organization's own events as a GS1 EPCIS 2.0 document (regulatory sharing, partner exchange). */
    public function exportEpcis(Request $request, EpcisExporter $exporter): JsonResponse
    {
        Gate::authorize('traceability.view');
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'product_id' => ['nullable', 'ulid'], 'batch_number' => ['nullable', 'string', 'max:64']]);

        return response()->json($exporter->export($this->tenant->organization(), $f['from'] ?? null, $f['to'] ?? null, $f['product_id'] ?? null, $f['batch_number'] ?? null))
            ->header('Content-Type', 'application/ld+json');
    }

    // ---- Identifiers ------------------------------------------------------------------------

    public function identifiers(Request $request): JsonResponse
    {
        $this->authorizeView();
        $f = $request->validate(['kind' => ['nullable', Rule::in(TraceIdentifier::KINDS)], 'product_id' => ['nullable', 'ulid'], 'batch_number' => ['nullable', 'string', 'max:64'],
            'parent_id' => ['nullable', 'ulid']]);
        $page = TraceIdentifier::query()
            ->when($f['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when($f['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->when($f['batch_number'] ?? null, fn ($q, $v) => $q->where('batch_number', strtoupper($v)))
            ->when($f['parent_id'] ?? null, fn ($q, $v) => $q->where('parent_id', $v))
            ->orderByDesc('created_at')->paginate(200);

        return response()->json(['data' => collect($page->items())->map(fn ($i) => $this->identifier($i)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function issueIdentifier(Request $request, IdentifierService $identifiers): JsonResponse
    {
        Gate::authorize('traceability.manage');
        $data = $request->validate([
            'kind' => ['required', Rule::in(['batch', 'product', 'sscc'])],
            'product_id' => ['required_unless:kind,sscc', 'nullable', 'ulid'],
            'batch_number' => ['required_if:kind,batch', 'nullable', 'string', 'max:64'],
            'serial' => ['required_if:kind,sscc', 'nullable', 'string', 'max:18'],
        ]);

        $identifier = $identifiers->issue($data, $request->user());
        $restricted = in_array($this->tenant->organization()->country_code, config('traceability.single_2d_code_countries'), true) && $identifier->kind !== 'sscc';

        return response()->json(['data' => $this->identifier($identifier), 'meta' => array_filter(['regulatory_notice' => $restricted
            ? 'Do not print this QR code on the product pack: in this country a pack may carry only one two-dimensional code for identification and verification (the GS1 DataMatrix). Use it on documents, shelf or bin labels; scanning the pack\'s DataMatrix gives the same check.'
            : null])], 201);
    }

    public function commissionSerials(Request $request, IdentifierService $identifiers): JsonResponse
    {
        Gate::authorize('traceability.manage');
        $data = $request->validate([
            'product_id' => ['required', 'ulid'],
            'batch_number' => ['required', 'string', 'max:64'],
            'serials' => ['required', 'array', 'min:1'],
            'serials.*' => ['string', 'max:20'],
            'parent_id' => ['nullable', 'ulid'],
        ]);
        $created = $identifiers->commissionSerials($data['product_id'], $data['batch_number'], $data['serials'], $data['parent_id'] ?? null, $request->user());

        return response()->json(['data' => array_map(fn ($i) => $this->identifier($i), $created)], 201);
    }

    public function aggregate(Request $request, string $id, IdentifierService $identifiers): JsonResponse
    {
        Gate::authorize('traceability.manage');
        $ids = $request->validate(['child_ids' => ['required', 'array', 'min:1', 'max:5000'], 'child_ids.*' => ['ulid']])['child_ids'];

        return response()->json(['data' => ['aggregated' => $identifiers->aggregate(TraceIdentifier::query()->findOrFail($id), $ids)]]);
    }

    public function identifierStatus(Request $request, string $id, string $action, IdentifierService $identifiers): JsonResponse
    {
        Gate::authorize('traceability.manage');
        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];
        $status = ['revoke' => 'revoked', 'decommission' => 'decommissioned'][$action];

        return response()->json(['data' => $this->identifier($identifiers->changeStatus(TraceIdentifier::query()->findOrFail($id), $status, $reason))]);
    }

    public function qr(string $id, IdentifierService $identifiers): Response
    {
        $this->authorizeView();

        return response($identifiers->qrSvg(TraceIdentifier::query()->findOrFail($id)), 200, ['Content-Type' => 'image/svg+xml']);
    }

    // ---- Scanning -----------------------------------------------------------------------------

    /** Public: anyone can check a pack. No organization names, prices or patient data in the answer. */
    public function publicScan(Request $request, ScanService $scans): JsonResponse
    {
        $code = $request->validate(['code' => ['required', 'string', 'max:512']])['code'];
        $result = $scans->scan($code, $request->ip());
        unset($result['identifier']);

        return response()->json(['data' => $result]);
    }

    /** For a member: the same answer plus the caller's own stock of the lot. */
    public function scan(Request $request, ScanService $scans): JsonResponse
    {
        abort_unless(Gate::any(['inventory.view', 'pos.sell', 'traceability.view']), 403);
        $code = $request->validate(['code' => ['required', 'string', 'max:512']])['code'];
        $result = $scans->scan($code, $request->ip());
        $identifier = $result['identifier'] ?? null;
        unset($result['identifier']);

        if (isset($result['product'], $result['batch_number'])) {
            $productId = $result['product_id'];
            $result['your_stock'] = DB::table('inventory_balances as b')->join('batches as t', 't.id', '=', 'b.batch_id')->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
                ->where('b.organization_id', $this->tenant->id())->where('t.product_id', $productId)->where('t.batch_number', $result['batch_number'])
                ->where('b.quantity', '!=', 0)->get(['w.code as warehouse_code', 'b.stock_state', 'b.quantity'])->all();
        }
        $result['identifier'] = $identifier ? ['id' => $identifier->id, 'kind' => $identifier->kind, 'status' => $identifier->status,
            'issued_by_you' => $identifier->organization_id === $this->tenant->id()] : null;

        return response()->json(['data' => $result]);
    }

    public function dispense(Request $request, ScanService $scans, TraceChain $chain): JsonResponse
    {
        abort_unless(Gate::any(['pos.sell', 'pos.dispense.prescription', 'traceability.manage']), 403);
        $code = $request->validate(['code' => ['required', 'string', 'max:512']])['code'];
        $identifier = $scans->scan($code, $request->ip())['identifier'] ?? null;
        if (! $identifier instanceof TraceIdentifier) {
            throw new BusinessRuleViolation('identifier_not_found', 'This code is not a serial registered on the platform.', 404);
        }

        return response()->json(['data' => ['id' => $identifier->id, 'serial' => $identifier->serial, 'status' => $scans->dispense($identifier, $chain)->status]]);
    }

    private function identifier(TraceIdentifier $i): array
    {
        return $i->only(['id', 'kind', 'token', 'product_id', 'gtin', 'batch_number', 'serial', 'parent_id', 'status', 'status_reason'])
            + ['expiry_date' => $i->expiry_date?->toDateString(), 'url' => $i->url(), 'gs1' => $i->gtin
                ? '(01)'.$i->gtin.($i->expiry_date ? '(17)'.$i->expiry_date->format('ymd') : '').($i->batch_number ? '(10)'.$i->batch_number : '').($i->kind === 'serial' ? '(21)'.$i->serial : '')
                : null];
    }

    private function authorizeView(): void
    {
        Gate::authorize($this->tenant->id() === null ? 'traceability.view_all' : 'traceability.view');
    }
}
