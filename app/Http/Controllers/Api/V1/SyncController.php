<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Sync\Models\Device;
use App\Domain\Sync\Models\SyncInboxItem;
use App\Domain\Sync\Models\SyncIssue;
use App\Domain\Sync\Services\SyncIssueService;
use App\Domain\Sync\Services\SyncProcessor;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Offline synchronization endpoints (spec §13.3). Only registered devices
 * send batches; reviewers see the inbox, the issues replay raised, and a
 * reconciliation report.
 */
class SyncController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function batch(Request $request, SyncProcessor $processor): JsonResponse
    {
        $device = $this->device($request);
        $data = $request->validate([
            'envelopes' => ['required', 'array', 'min:1', 'max:'.config('sync.max_batch')],
            'envelopes.*.client_txn_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/', 'distinct'],
            'envelopes.*.type' => ['required', 'string', 'max:48'],
            'envelopes.*.schema_version' => ['required', 'integer', 'min:1'],
            'envelopes.*.client_created_at' => ['required', 'date'],
            'envelopes.*.attempt' => ['nullable', 'integer', 'min:0'],
            'envelopes.*.payload' => ['present', 'array'],
        ]);

        return response()->json([
            'data' => $processor->process($device, $request->user(), $data['envelopes']),
            'meta' => ['server_time' => now()->toIso8601String(), 'device_id' => $device->id],
        ]);
    }

    /** The device's own view: what the server holds for it and when it last synced. */
    public function status(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $counts = SyncInboxItem::query()->where('device_id', $device->id)->groupBy('status')->selectRaw('status, COUNT(*) as n')->pluck('n', 'status');

        return response()->json(['data' => [
            'device' => $device->only(['id', 'name', 'role', 'status', 'pos_terminal_id', 'last_sync_at', 'last_seen_at']),
            'inbox' => ['synced' => (int) ($counts['synced'] ?? 0), 'conflict' => (int) ($counts['conflict'] ?? 0), 'rejected' => (int) ($counts['rejected'] ?? 0), 'retry_required' => (int) ($counts['received'] ?? 0)],
            'open_issues' => SyncIssue::query()->where('device_id', $device->id)->where('status', 'open')->count(),
            'server_time' => now()->toIso8601String(),
        ]]);
    }

    public function inbox(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('sync.reconcile') || Gate::allows('devices.manage'), 403);
        $f = $request->validate(['status' => ['nullable', Rule::in(['received', 'synced', 'conflict', 'rejected'])], 'device_id' => ['nullable', 'ulid'], 'type' => ['nullable', 'string'],
            'unresolved' => ['nullable', 'boolean']]);

        return response()->json(SyncInboxItem::query()
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['device_id'] ?? null, fn ($q, $v) => $q->where('device_id', $v))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($request->boolean('unresolved'), fn ($q) => $q->whereIn('status', ['conflict', 'rejected'])->whereNull('resolved_at'))
            ->latest('received_at')->paginate(50));
    }

    /** A reviewer records how a refused transaction was handled (it is never deleted). */
    public function resolveInbox(Request $request, int $id): JsonResponse
    {
        Gate::authorize('sync.reconcile');
        $item = SyncInboxItem::query()->findOrFail($id);
        if (! in_array($item->status, ['conflict', 'rejected'], true)) {
            throw new BusinessRuleViolation('inbox_item_not_refused', 'Only refused transactions are resolved here.', 409);
        }
        $data = $request->validate(['notes' => ['required', 'string', 'max:2000']]);
        $item->forceFill(['resolved_by' => $request->user()->id, 'resolved_at' => now(), 'resolution_notes' => $data['notes']])->save();

        return response()->json(['data' => $item]);
    }

    public function issues(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('sync.reconcile') || Gate::allows('devices.manage'), 403);
        $f = $request->validate(['status' => ['nullable', Rule::in(['open', 'resolved'])], 'type' => ['nullable', Rule::in(SyncIssue::TYPES)], 'device_id' => ['nullable', 'ulid']]);

        return response()->json(SyncIssue::query()
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['device_id'] ?? null, fn ($q, $v) => $q->where('device_id', $v))
            ->orderByRaw("FIELD(severity, 'high', 'medium', 'low')")->latest()->paginate(50));
    }

    public function resolveIssue(Request $request, string $id, SyncIssueService $issues): JsonResponse
    {
        Gate::authorize('sync.reconcile');
        $data = $request->validate([
            'action' => ['required', Rule::in(['acknowledge', 'record_found_stock'])],
            'notes' => ['required', 'string', 'max:2000'],
            'batch_id' => ['nullable', 'ulid'],
            'batch_number' => ['nullable', 'string', 'max:64'],
            'expiry_date' => ['nullable', 'date'],
            'unit_cost' => ['nullable', 'decimal:0,4', 'min:0'],
        ]);

        return response()->json(['data' => $issues->resolve(SyncIssue::query()->findOrFail($id), $request->user(), $data)]);
    }

    /** Per device: transactions received and their outcome, plus issues raised by replay. */
    public function reconciliation(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('sync.reconcile') || Gate::allows('devices.manage'), 403);
        $d = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $range = [($d['from'] ?? today()->subDays(7)->toDateString()).' 00:00:00', ($d['to'] ?? today()->toDateString()).' 23:59:59'];
        $org = $this->tenant->id();

        $inbox = DB::table('sync_inbox')->where('organization_id', $org)->whereBetween('received_at', $range)
            ->groupBy('device_id')
            ->selectRaw("device_id, COUNT(*) as received, SUM(status = 'synced') as synced, SUM(status = 'conflict') as conflict,
                SUM(status = 'rejected') as rejected, SUM(status = 'received') as retry_required,
                SUM(status IN ('conflict', 'rejected') AND resolved_at IS NULL) as unresolved, MAX(processed_at) as last_processed_at")
            ->get()->keyBy('device_id');
        $issues = DB::table('sync_issues')->where('organization_id', $org)->whereBetween('created_at', $range)
            ->groupBy('device_id', 'type', 'status')->selectRaw('device_id, type, status, COUNT(*) as n, SUM(quantity) as quantity')->get()->groupBy('device_id');
        $offlineSales = DB::table('pos_sales')->where('organization_id', $org)->where('captured_offline', true)->whereBetween('created_at', $range)
            ->groupBy('device_id', 'status')->selectRaw('device_id, status, COUNT(*) as n, SUM(total) as total')->get()->groupBy('device_id');

        $devices = Device::query()->with('user:id,name')->whereIn('id', $inbox->keys()->merge($issues->keys())->merge($offlineSales->keys())->filter()->unique())->get();

        return response()->json(['data' => [
            'from' => $range[0], 'to' => $range[1],
            'devices' => $devices->map(fn (Device $device) => [
                'device' => $device->only(['id', 'name', 'role', 'status', 'last_sync_at']) + ['user' => $device->user?->name],
                'transactions' => $inbox->get($device->id),
                'issues' => $issues->get($device->id, collect())->values(),
                'offline_pos_sales' => $offlineSales->get($device->id, collect())->values(),
            ])->values(),
        ]]);
    }

    private function device(Request $request): Device
    {
        $device = $request->attributes->get('device');
        if (! $device instanceof Device) {
            throw new BusinessRuleViolation('device_required', 'Register this device first (POST /devices) and use its token.', 403);
        }
        if ($device->organization_id !== $this->tenant->id()) {
            throw new BusinessRuleViolation('device_organization_mismatch', 'This device is registered to another organization.', 403);
        }

        return $device;
    }
}
