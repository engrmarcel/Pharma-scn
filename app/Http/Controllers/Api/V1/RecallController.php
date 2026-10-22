<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\Services\DocumentNumberer;
use App\Domain\Platform\Services\AuditLogger;
use App\Domain\Traceability\Models\Recall;
use App\Domain\Traceability\Models\RecallNotice;
use App\Domain\Traceability\Models\RegulatoryIncident;
use App\Domain\Traceability\Services\RecallService;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Recalls (issuer and platform side), recall notices (receiving side) and the
 * regulatory incident log. Tenant routes and /admin routes share these actions.
 */
class RecallController extends Controller
{
    public function __construct(private readonly TenantContext $tenant, private readonly RecallService $recalls) {}

    // ---- Recalls ------------------------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['draft', 'active', 'closed', 'cancelled'])]])['status'] ?? null;

        return response()->json(['data' => $this->recalls->visible()->when($status, fn ($q, $v) => $q->where('status', $v))->latest()->limit(200)->get()
            ->map(fn (Recall $r) => $this->recall($r))]);
    }

    public function show(string $id): JsonResponse
    {
        $recall = $this->recalls->visible()->findOrFail($id);
        $notice = $this->tenant->id() ? RecallNotice::query()->where('recall_id', $recall->id)->first() : null;

        return response()->json(['data' => $this->recall($recall) + ['your_notice' => $notice ? $this->notice($notice) : null]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'ulid', 'exists:products,id'],
            'batch_numbers' => ['required', 'array', 'min:1', 'max:200'],
            'batch_numbers.*' => ['string', 'max:64'],
            'title' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:5000'],
            'classification' => ['required', Rule::in(Recall::CLASSIFICATIONS)],
            'depth' => ['required', Rule::in(Recall::DEPTHS)],
            'authority' => ['nullable', 'string', 'max:32'],
            'regulatory_reference' => ['nullable', 'string', 'max:100'],
            'quarantine_instructions' => ['required', 'string', 'max:5000'],
        ]);

        return response()->json(['data' => $this->recall($this->recalls->create($data, $request->user()))], 201);
    }

    public function action(Request $request, string $id, string $action): JsonResponse
    {
        $recall = $this->recalls->visible()->findOrFail($id);
        $result = match ($action) {
            'activate' => $this->recalls->activate($recall, $request->user()),
            'close' => $this->recalls->close($recall, $request->user(), ...array_values($request->validate([
                'notes' => ['required', 'string', 'max:5000'], 'override_reason' => ['nullable', 'string', 'max:2000']]) + ['override_reason' => null])),
            'cancel' => $this->recalls->cancel($recall, $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason']),
        };

        return response()->json(['data' => $this->recall($result)]);
    }

    /** Issuer and platform staff only. */
    public function exposure(string $id): JsonResponse
    {
        $recall = $this->recalls->visible()->findOrFail($id);
        if ($this->tenant->id() !== null && $recall->issuer_organization_id !== $this->tenant->id()) {
            throw new BusinessRuleViolation('recall_not_issuer', 'The exposure report is available to the issuer and platform compliance.', 403);
        }

        return response()->json(['data' => $this->recalls->exposure($recall)]);
    }

    // ---- Notices (receiving organizations) ---------------------------------------------------------

    public function notices(Request $request): JsonResponse
    {
        Gate::authorize('recalls.respond');
        $status = $request->validate(['status' => ['nullable', Rule::in(['pending', 'acknowledged', 'completed'])]])['status'] ?? null;

        return response()->json(['data' => RecallNotice::query()->with('recall')->when($status, fn ($q, $v) => $q->where('status', $v))
            ->latest('notified_at')->get()->map(fn ($n) => $this->notice($n) + ['recall' => $this->recall($n->recall)])]);
    }

    public function showNotice(string $id): JsonResponse
    {
        Gate::authorize('recalls.respond');
        $n = RecallNotice::query()->with('recall')->findOrFail($id);

        return response()->json(['data' => $this->notice($n) + ['recall' => $this->recall($n->recall), 'recovery' => $this->recalls->recovery($n),
            'your_customers' => $this->recalls->downstream($n)]]);
    }

    private function notice(RecallNotice $notice): array
    {
        return $notice->only(['id', 'recall_id', 'organization_id', 'status', 'acknowledgement_notes', 'completion_notes']) + [
            'notified_at' => $notice->notified_at->toIso8601String(), 'held_at_notice' => (string) $notice->held_at_notice,
            'quarantined_at_notice' => (string) $notice->quarantined_at_notice, 'acknowledged_at' => $notice->acknowledged_at?->toIso8601String(),
            'completed_at' => $notice->completed_at?->toIso8601String(),
        ];
    }

    public function noticeAction(Request $request, string $id, string $action): JsonResponse
    {
        Gate::authorize('recalls.respond');
        $notice = RecallNotice::query()->with('recall')->findOrFail($id);
        $notes = $request->validate(['notes' => [$action === 'complete' ? 'nullable' : 'nullable', 'string', 'max:5000']])['notes'] ?? null;
        $notice = $action === 'acknowledge' ? $this->recalls->acknowledge($notice, $request->user(), $notes) : $this->recalls->complete($notice, $request->user(), (string) $notes);

        return response()->json(['data' => $this->notice($notice) + ['recovery' => $this->recalls->recovery($notice)]]);
    }

    // ---- Regulatory incidents ------------------------------------------------------------------------

    public function incidents(Request $request): JsonResponse
    {
        Gate::authorize('regulatory.incidents.manage');
        $f = $request->validate(['status' => ['nullable', Rule::in(['open', 'reported', 'closed'])], 'overdue' => ['nullable', 'boolean']]);

        return response()->json(['data' => RegulatoryIncident::query()
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['overdue'] ?? false, fn ($q) => $q->where('status', 'open')->where('report_due_at', '<', now()))
            ->orderBy('report_due_at')->limit(500)->get()->map(fn ($i) => $this->incident($i))]);
    }

    public function storeIncident(Request $request, DocumentNumberer $numbers, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('regulatory.incidents.manage');
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(RegulatoryIncident::DEADLINE_HOURS))],
            'product_id' => ['nullable', 'ulid', 'exists:products,id'],
            'batch_number' => ['nullable', 'string', 'max:64'],
            'serials' => ['nullable', 'array', 'max:1000'],
            'serials.*' => ['string', 'max:20'],
            'quantity' => ['nullable', 'decimal:0,4', 'gt:0'],
            'description' => ['required', 'string', 'max:5000'],
            'detected_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);
        $detected = Carbon::parse($data['detected_at'] ?? now());
        // Derived values first: with + the left-hand keys win.
        $incident = RegulatoryIncident::query()->create(['number' => $numbers->next('RIN'), 'detected_at' => $detected,
            'batch_number' => isset($data['batch_number']) ? strtoupper($data['batch_number']) : null,
            'report_due_at' => $detected->copy()->addHours(RegulatoryIncident::DEADLINE_HOURS[$data['type']]), 'created_by' => $request->user()->id] + $data);
        $audit->record('regulatory_incident.recorded', $incident, new: ['type' => $incident->type, 'report_due_at' => $incident->report_due_at->toIso8601String()]);

        return response()->json(['data' => $this->incident($incident)], 201);
    }

    public function incidentAction(Request $request, string $id, string $action, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('regulatory.incidents.manage');
        $incident = RegulatoryIncident::query()->findOrFail($id);
        if ($action === 'report') {
            if ($incident->status !== 'open') {
                throw new BusinessRuleViolation('incident_not_open', 'Only open incidents are reported.', 409);
            }
            $data = $request->validate(['authority_reference' => ['required', 'string', 'max:100'], 'reported_at' => ['nullable', 'date', 'before_or_equal:now']]);
            $incident->forceFill(['status' => 'reported', 'authority_reference' => $data['authority_reference'],
                'reported_at' => $data['reported_at'] ?? now(), 'reported_by' => $request->user()->id])->save();
        } else {
            if ($incident->status === 'closed') {
                throw new BusinessRuleViolation('incident_closed', 'The incident is already closed.', 409);
            }
            $incident->forceFill(['status' => 'closed', 'closing_notes' => $request->validate(['notes' => ['required', 'string', 'max:5000']])['notes']])->save();
        }
        $audit->record("regulatory_incident.{$incident->status}", $incident, new: ['authority_reference' => $incident->authority_reference]);

        return response()->json(['data' => $this->incident($incident)]);
    }

    // ---- Presentation -------------------------------------------------------------------------------

    private function recall(Recall $r): array
    {
        return $r->only(['id', 'number', 'issuer_organization_id', 'product_id', 'title', 'reason', 'classification', 'depth', 'authority', 'regulatory_reference',
            'quarantine_instructions', 'status', 'closure_notes', 'closure_override_reason', 'cancellation_reason'])
            + ['batch_numbers' => $r->batchNumbers(), 'activated_at' => $r->activated_at?->toIso8601String(), 'closed_at' => $r->closed_at?->toIso8601String()];
    }

    private function incident(RegulatoryIncident $i): array
    {
        return $i->only(['id', 'number', 'type', 'product_id', 'batch_number', 'serials', 'description', 'status', 'authority', 'authority_reference', 'closing_notes'])
            + ['quantity' => $i->quantity === null ? null : (string) $i->quantity, 'detected_at' => $i->detected_at->toIso8601String(),
                'report_due_at' => $i->report_due_at->toIso8601String(), 'reported_at' => $i->reported_at?->toIso8601String(),
                'overdue' => $i->isOverdue(), 'reported_late' => $i->reported_at !== null && $i->reported_at->gt($i->report_due_at)];
    }
}
