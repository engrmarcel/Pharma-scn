<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\Assistant\AssistantService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** The reporting assistant (spec §19.2): questions in, read-only report answers out. */
class AnalyticsAssistantController extends Controller
{
    public function __construct(private readonly AssistantService $assistant) {}

    public function ask(Request $request): JsonResponse
    {
        $question = $request->validate(['question' => ['required', 'string', 'min:3', 'max:500']])['question'];

        return response()->json(['data' => $this->assistant->ask($question, $request->user())]);
    }

    public function settings(): JsonResponse
    {
        Gate::authorize('analytics.view');

        return response()->json(['data' => $this->assistant->settings()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['external_processing' => ['required', 'boolean'], 'acknowledge_external_processing' => ['boolean']]);

        return response()->json(['data' => $this->assistant->updateSettings($data['external_processing'], (bool) ($data['acknowledge_external_processing'] ?? false), $request->user())]);
    }
}
