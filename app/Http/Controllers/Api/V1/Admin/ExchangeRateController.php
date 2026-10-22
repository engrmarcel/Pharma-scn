<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Exchange rates for presenting platform-wide totals in one currency (spec §20 country-aware
 * reports). Recorded by platform staff with their source; never used in tenant books.
 */
class ExchangeRateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('platform.billing.manage');

        return response()->json(['data' => DB::table('exchange_rates')->orderByDesc('effective_date')->orderBy('base_currency')->limit(500)
            ->get(['base_currency', 'quote_currency', 'rate', 'effective_date', 'source'])]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('platform.billing.manage');
        $currencies = array_keys(config('currencies'));
        $data = $request->validate([
            'base_currency' => ['required', Rule::in($currencies)],
            'quote_currency' => ['required', Rule::in($currencies), 'different:base_currency'],
            'rate' => ['required', 'decimal:0,8', 'gt:0'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'source' => ['required', 'string', 'max:255'],
        ]);
        if (DB::table('exchange_rates')->where('base_currency', $data['base_currency'])->where('quote_currency', $data['quote_currency'])
            ->where('effective_date', $data['effective_date'])->exists()) {
            throw new BusinessRuleViolation('exchange_rate_exists', 'A rate for this pair and date is already recorded.', 409);
        }
        DB::table('exchange_rates')->insert($data + ['recorded_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $audit->record('exchange_rate.recorded', null, new: $data, organizationId: false);

        return response()->json(['data' => $data], 201);
    }
}
