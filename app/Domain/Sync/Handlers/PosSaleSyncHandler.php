<?php

namespace App\Domain\Sync\Handlers;

use App\Domain\Pos\Models\PosSale;
use App\Domain\Pos\Models\PosShift;
use App\Domain\Pos\Services\PosSaleService;
use App\Domain\Sync\Models\Device;
use App\Domain\Sync\Models\SyncInboxItem;
use App\Domain\Sync\Services\SyncIssueService;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Offline POS sales (envelope type pos.sale). The sale is recorded through
 * PosSaleService::recordOffline; everything replay could not settle (stock
 * shortfalls, price variances, blocked batches, late sales) becomes a sync
 * issue for a supervisor and is echoed to the device as a warning.
 */
class PosSaleSyncHandler
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PosSaleService $sales,
        private readonly SyncIssueService $issues,
    ) {}

    public function handle(SyncInboxItem $item): array
    {
        Gate::authorize('pos.sell');
        $device = Device::query()->findOrFail($item->device_id);
        if ($device->role !== 'pos') {
            throw new BusinessRuleViolation('device_role_mismatch', 'Only devices registered as POS terminals can send sales.', 403);
        }

        $org = $this->tenant->id();
        $data = Validator::make($item->payload, [
            'client_reference' => ['required', 'string', 'max:64'],
            'shift_id' => ['required', 'ulid'],
            'customer_id' => ['nullable', 'ulid', Rule::exists('customers', 'id')->where('organization_id', $org)],
            'completed_at' => ['required', 'date'],
            'device_total' => ['nullable', 'decimal:0,4'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'ulid', Rule::exists('organization_products', 'product_id')->where('organization_id', $org)],
            'lines.*.package_level_code' => ['nullable', 'string', 'max:32'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.unit_price' => ['required', 'decimal:0,4', 'min:0'],
            'lines.*.discount_percent' => ['nullable', 'decimal:0,4', 'between:0,100'],
            'lines.*.batch_id' => ['nullable', 'ulid'],
            'lines.*.prescription' => ['nullable', 'array:reference,prescriber_name,prescribed_on'],
            'payments' => ['required', 'array', 'min:1', 'max:10'],
            'payments.*.method' => ['required', Rule::in(['cash', ...PosSaleService::ELECTRONIC])],
            'payments.*.amount' => ['required', 'decimal:0,4', 'gt:0'],
            'payments.*.tendered' => ['nullable', 'decimal:0,4', 'gt:0'],
            'payments.*.approval_code' => ['nullable', 'string', 'max:64', 'required_unless:payments.*.method,cash'],
        ])->validate();

        $shift = PosShift::query()->find($data['shift_id']) ?? throw new BusinessRuleViolation('shift_not_found', 'The shift for this sale was not found.', 404);
        if ($shift->pos_terminal_id !== $device->pos_terminal_id) {
            throw new BusinessRuleViolation('terminal_mismatch', "This device is not registered to the shift's terminal.", 422);
        }

        $result = $this->sales->recordOffline($shift, Auth::user(), $data['client_reference'], $data, $device->id);
        $sale = $result['sale'];

        foreach ($result['issues'] as $issue) {
            $this->issues->raise($issue['type'], $issue['severity'], [
                'device_id' => $device->id,
                'sync_inbox_id' => $item->id,
                'subject_type' => $issue['subject_type'] ?? 'pos_sale',
                'subject_id' => $issue['subject_id'] ?? $sale->id,
                'warehouse_id' => $issue['warehouse_id'] ?? $sale->warehouse_id,
                'product_id' => $issue['product_id'] ?? null,
                'quantity' => $issue['quantity'] ?? null,
                'details' => ($issue['details'] ?? []) + ['sale' => $sale->number] + (isset($issue['line']) ? ['line' => $issue['line']] : []),
            ]);
            $item->pendingWarnings[] = ['type' => $issue['type'], 'severity' => $issue['severity']];
        }
        if (isset($data['device_total']) && bccomp((string) $data['device_total'], (string) $sale->total, 4) !== 0) {
            $item->pendingWarnings[] = ['type' => 'total_differs', 'severity' => 'low', 'device_total' => (string) $data['device_total'], 'server_total' => (string) $sale->total];
        }

        return $this->summary($sale);
    }

    private function summary(PosSale $sale): array
    {
        $shiftStatus = PosShift::query()->whereKey($sale->pos_shift_id)->value('status');

        return $sale->only(['id', 'number', 'status', 'total', 'change_due', 'sales_invoice_id']) + [
            'payments' => $sale->payments->map(fn ($p) => $p->only(['method', 'amount', 'status', 'recorded_offline']) + ['state' => $p->presentationState($shiftStatus)])->values(),
            'unallocated_lines' => $sale->lines->filter(fn ($l) => bccomp((string) $l->unallocated_base_quantity, '0', 4) > 0)->count(),
        ];
    }
}
