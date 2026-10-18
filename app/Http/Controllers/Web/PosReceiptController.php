<?php

namespace App\Http\Controllers\Web;

use App\Domain\Pos\Models\PosSale;
use App\Domain\Pos\Services\ReceiptPresenter;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PosReceiptController extends Controller
{
    /** Printable 72mm receipt. Cashiers print their own sales; supervisors any. */
    public function __invoke(string $sale, ReceiptPresenter $receipts): View
    {
        Gate::authorize('pos.sell');
        $model = PosSale::query()->with('lines.allocations', 'payments')->findOrFail($sale);
        abort_unless($model->cashier_id === auth()->id() || Gate::allows('pos.sale.cancel'), 403);
        abort_unless($model->status === 'completed', 404);

        return view('pos.receipt', ['r' => $receipts->present($model)]);
    }
}
