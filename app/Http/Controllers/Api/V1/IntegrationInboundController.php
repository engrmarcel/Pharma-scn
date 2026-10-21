<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integration\Canonical\CanonicalModel;
use App\Domain\Integration\Files\FileImporter;
use App\Domain\Integration\Files\FileParser;
use App\Domain\Integration\Models\IntegrationConnector;
use App\Domain\Integration\Models\IntegrationMessage;
use App\Domain\Integration\Models\IntegrationRun;
use App\Domain\Integration\Sap\SapIdocAdapter;
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

    /**
     * A whole file pushed by the source system (raw body). Query: entity_type, format?, file_name?.
     * The same file content is imported once; a copy returns the first run.
     */
    public function file(Request $request, FileImporter $importer): JsonResponse
    {
        $q = $request->validate([
            'entity_type' => ['required', 'string', 'max:32'],
            'format' => ['nullable', 'in:'.implode(',', FileParser::FORMATS)],
            'file_name' => ['nullable', 'string', 'max:200', 'regex:/^[\w.\- ]+$/'],
        ]);
        $result = $importer->import($this->connector($request), $request->getContent(), $q['file_name'] ?? 'upload-'.now()->format('Ymd-His'),
            $q['entity_type'], $q['format'] ?? null, 'file');

        return response()->json(['run' => $result['run']->summary(), 'duplicate_file' => $result['duplicate_file'], 'data' => $result['acknowledgements']], 202);
    }

    /** SAP IDoc XML (one or more <IDOC>), as sent by the customer's SAP middleware. */
    public function idoc(Request $request, SapIdocAdapter $adapter, IntegrationInbox $inbox): JsonResponse
    {
        $connector = $this->connector($request);
        $parsed = $adapter->parse($connector, $request->getContent());
        $result = $inbox->receive($connector, $parsed['envelopes'], 'idoc');
        if ($parsed['rejected'] !== []) {
            $result['run']->increment('rejected_count', count($parsed['rejected']));
        }

        return response()->json(['run' => $result['run']->fresh()->summary(), 'data' => [...$result['acknowledgements'], ...$parsed['rejected']]], 202);
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
