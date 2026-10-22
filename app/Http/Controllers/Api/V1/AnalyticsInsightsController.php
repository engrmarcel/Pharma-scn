<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\Models\Recommendation;
use App\Domain\Analytics\Services\ForecastService;
use App\Domain\Analytics\Services\RecommendationEngine;
use App\Domain\Analytics\Services\RecommendationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Forecasts and recommendations (spec §19.2). Everything here is labelled as a
 * prediction, recommendation or anomaly; acting on it is a person's decision.
 */
class AnalyticsInsightsController extends Controller
{
    public function __construct(private readonly RecommendationService $recommendations) {}

    public function forecast(Request $request, ForecastService $forecasts): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'ulid'],
            'warehouse_id' => ['nullable', 'ulid'],
            'horizon_weeks' => ['nullable', 'integer', 'between:1,26'],
        ]);

        return response()->json(['data' => $forecasts->forecast($data['product_id'], $data['warehouse_id'] ?? null, (int) ($data['horizon_weeks'] ?? 8))]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'accepted', 'dismissed', 'superseded'])],
            'type' => ['nullable', Rule::in(Recommendation::TYPES)],
        ]);
        $rows = $this->recommendations->visible()
            ->where('status', $filters['status'] ?? 'open')
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")->orderByDesc('generated_at')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (Recommendation $r) => $this->present($r))]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->present($this->recommendations->visible()->findOrFail($id))]);
    }

    /** Run the generator now for this organization (it also runs nightly). */
    public function generate(RecommendationEngine $engine): JsonResponse
    {
        Gate::authorize('analytics.view');

        return response()->json(['data' => $engine->generate()]);
    }

    public function decide(Request $request, string $id, string $decision): JsonResponse
    {
        $rec = $this->recommendations->visible()->findOrFail($id);
        $result = $decision === 'accept'
            ? $this->recommendations->accept($rec, $request->user(), $request->validate([
                'notes' => ['nullable', 'string', 'max:2000'],
                'warehouse_id' => ['nullable', 'ulid'],
                'quantity' => ['nullable', 'decimal:0,4', 'gt:0'],
                'needed_by' => ['nullable', 'date', 'after_or_equal:today'],
            ]))
            : $this->recommendations->dismiss($rec, $request->user(), $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason']);

        return response()->json(['data' => $this->present($result)]);
    }

    private function present(Recommendation $r): array
    {
        return [
            'id' => $r->id, 'type' => $r->type, 'kind' => $r->kind, 'severity' => $r->severity, 'status' => $r->status,
            'label' => 'Machine-generated '.$r->kind.'. Review before acting; it does not change anything by itself.',
            'title' => $r->title, 'rationale' => $r->rationale, 'figures' => $r->figures, 'assumptions' => $r->assumptions,
            'product_id' => $r->product_id, 'warehouse_id' => $r->warehouse_id, 'subject_type' => $r->subject_type, 'subject_id' => $r->subject_id,
            'generator' => $r->generator, 'generated_at' => $r->generated_at->toIso8601String(),
            'decision' => $r->decided_at ? ['by' => $r->decided_by, 'at' => $r->decided_at->toIso8601String(), 'notes' => $r->decision_notes,
                'action_type' => $r->action_type, 'action_id' => $r->action_id] : null,
        ];
    }
}
