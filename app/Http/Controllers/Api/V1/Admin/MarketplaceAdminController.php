<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Marketplace\Models\MarketplaceDispute;
use App\Domain\Marketplace\Models\MarketplaceFee;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Platform oversight of the marketplace: the fee ledger (accrued, waived,
 * per supplier) and escalated disputes. The platform comments on disputes
 * but never posts to either organization's books.
 */
class MarketplaceAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function fees(Request $request): JsonResponse
    {
        Gate::authorize('platform.marketplace.manage');
        $d = $request->validate(['supplier_organization_id' => ['nullable', 'ulid'], 'status' => ['nullable', 'in:accrued,waived'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $query = MarketplaceFee::query()
            ->when($d['supplier_organization_id'] ?? null, fn ($q, $v) => $q->where('supplier_organization_id', $v))
            ->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($d['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v.' 00:00:00'))
            ->when($d['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'));

        return response()->json([
            'data' => (clone $query)->latest()->paginate(100),
            'meta' => ['by_supplier' => (clone $query)->groupBy('supplier_organization_id', 'currency_code', 'status')
                ->selectRaw('supplier_organization_id, currency_code, status, COUNT(*) as fees, SUM(amount) as amount, SUM(basis_amount) as basis')->get()],
        ]);
    }

    public function waive(Request $request, string $id): JsonResponse
    {
        Gate::authorize('platform.marketplace.manage');
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];

        return response()->json(['data' => DB::transaction(function () use ($id, $reason, $request) {
            $fee = MarketplaceFee::query()->lockForUpdate()->findOrFail($id);
            if ($fee->status !== 'accrued') {
                throw new BusinessRuleViolation('fee_not_accrued', 'Only accrued fees can be waived.', 409);
            }
            $fee->forceFill(['status' => 'waived', 'waived_by' => $request->user()->id, 'waived_at' => now(), 'waive_reason' => $reason])->save();
            $this->audit->record('marketplace_fee.waived', null, ['status' => 'accrued'], ['fee_id' => $fee->id, 'amount' => (string) $fee->amount, 'reason' => $reason], organizationId: false);

            return $fee;
        })]);
    }

    public function disputes(Request $request): JsonResponse
    {
        Gate::authorize('platform.marketplace.manage');

        return response()->json(MarketplaceDispute::query()->with('messages')->where('status', $request->query('status', 'escalated'))->latest('escalated_at')->paginate(50));
    }

    public function note(Request $request, string $id): JsonResponse
    {
        Gate::authorize('platform.marketplace.manage');
        $dispute = MarketplaceDispute::query()->findOrFail($id);
        $dispute->messages()->create(['author_side' => 'platform', 'user_id' => $request->user()->id, 'body' => $request->validate(['body' => ['required', 'string', 'max:5000']])['body']]);

        return response()->json(['data' => $dispute->load('messages')]);
    }
}
