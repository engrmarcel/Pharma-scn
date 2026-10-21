<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integration\Canonical\CanonicalModel;
use App\Domain\Integration\Models\IntegrationConnector;
use App\Domain\Integration\Models\IntegrationMessage;
use App\Domain\Integration\Models\IntegrationRun;
use App\Domain\Integration\Services\IntegrationInbox;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints for external systems, authenticated by connector key (see AuthenticateConnector).
 */
class IntegrationInboundController extends Controller
{
    /** Connectivity check for the source system's integration team. */
    public function ping(Request $request): JsonResponse
    {
        $connector = $this->connector($request);

        return response()->json(['data' => [
            'connector' => ['id' => $connector->id, 'code' => $connector->code, 'name' => $connector->name, 'status' => $connector->status],
            'organization' => ['id' => $connector->organization->id, 'name' => $connector->organization->name],
            'canonical_model_version' => CanonicalModel::VERSION,
            'ingestible_entities' => CanonicalModel::ingestible(),
            'max_records_per_request' => config('integrations.max_records_per_request'),
            'server_time' => now()->toIso8601String(),
        ]]);
    }

    /**
     * Stores the records and acknowledges each one: accepted (stored; processing is asynchronous),
     * duplicate (already received; its current status is returned) or rejected (not stored).
     */
    public function store(Request $request, IntegrationInbox $inbox): JsonResponse
    {
        $data = $request->validate([
            'messages' => ['required', 'array', 'list', 'min:1', 'max:'.config('integrations.max_records_per_request')],
        ]);
        $result = $inbox->receive($this->connector($request), $data['messages'], 'api');

        return response()->json([
            'run' => $result['run']->summary(),
            'data' => $result['acknowledgements'],
        ], 202);
    }

    /** The business outcome of a message, by the source system's own message_id. */
    public function message(Request $request, string $messageId): JsonResponse
    {
        $message = IntegrationMessage::query()->where('connector_id', $this->connector($request)->id)->where('message_id', $messageId)->firstOrFail();

        return response()->json(['data' => $message->acknowledgement()]);
    }

    public function run(Request $request, string $runId): JsonResponse
    {
        $run = IntegrationRun::query()->where('connector_id', $this->connector($request)->id)->findOrFail($runId);

        return response()->json(['data' => $run->summary()]);
    }

    private function connector(Request $request): IntegrationConnector
    {
        return $request->attributes->get('connector');
    }
}
