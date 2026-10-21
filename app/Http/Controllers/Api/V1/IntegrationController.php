<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integration\Canonical\CanonicalModel;
use App\Domain\Integration\Canonical\MappingTemplates;
use App\Domain\Integration\Files\ExportService;
use App\Domain\Integration\Files\FileImporter;
use App\Domain\Integration\Files\FileParser;
use App\Domain\Integration\Files\SftpTransfer;
use App\Domain\Integration\Models\ExternalReference;
use App\Domain\Integration\Models\IntegrationConnector;
use App\Domain\Integration\Models\IntegrationFieldMapping;
use App\Domain\Integration\Models\IntegrationMessage;
use App\Domain\Integration\Models\IntegrationRun;
use App\Domain\Integration\Services\ConnectorService;
use App\Domain\Integration\Services\FieldMapper;
use App\Domain\Integration\Services\IntegrationMonitor;
use App\Domain\Integration\Services\MessageReviewService;
use App\Domain\Integration\Services\PayloadRedactor;
use App\Domain\Integration\Services\ReferenceResolver;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The organization's side of the integration gateway: the external-system
 * registry, mappings, failed-message review, health and reconciliation.
 */
class IntegrationController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ConnectorService $connectors,
        private readonly PayloadRedactor $redactor,
    ) {}

    public function canonicalModel(): JsonResponse
    {
        Gate::authorize('integrations.view');

        return response()->json(['data' => CanonicalModel::describe()]);
    }

    // ---- Connectors -----------------------------------------------------------------------

    public function connectors(): JsonResponse
    {
        Gate::authorize('integrations.view');

        return response()->json(['data' => IntegrationConnector::query()->orderBy('name')->get()->map(fn ($c) => $this->connectorData($c))]);
    }

    public function connector(string $id): JsonResponse
    {
        Gate::authorize('integrations.view');

        return response()->json(['data' => $this->connectorData(IntegrationConnector::query()->findOrFail($id))]);
    }

    public function storeConnector(Request $request): JsonResponse
    {
        Gate::authorize('integrations.manage');
        $data = $request->validate($this->connectorRules() + [
            'code' => ['required', 'string', 'max:32', 'alpha_dash'],
            'name' => ['required', 'string', 'max:100'],
            'system_type' => ['required', Rule::in(array_keys(config('integrations.system_types')))],
            'inbound_transport' => ['required', Rule::in(config('integrations.inbound_transports'))],
        ]);

        return response()->json(['data' => $this->connectorData($this->connectors->create($data, $request->user()))], 201);
    }

    public function updateConnector(Request $request, string $id): JsonResponse
    {
        Gate::authorize('integrations.manage');
        $connector = IntegrationConnector::query()->findOrFail($id);
        $data = $request->validate($this->connectorRules() + [
            'name' => ['sometimes', 'string', 'max:100'],
            'system_type' => ['sometimes', Rule::in(array_keys(config('integrations.system_types')))],
            'inbound_transport' => ['sometimes', Rule::in(config('integrations.inbound_transports'))],
        ]);

        return response()->json(['data' => $this->connectorData($this->connectors->update($connector, $data))]);
    }

    /** Issues or rotates the inbound key. The key is returned once and cannot be read again. */
    public function rotateKey(string $id): JsonResponse
    {
        Gate::authorize('integrations.manage');
        $connector = IntegrationConnector::query()->findOrFail($id);
        $key = $this->connectors->rotateKey($connector);

        return response()->json(['data' => ['key' => $key, 'prefix' => $connector->inbound_key_prefix,
            'notice' => 'Store this key in the source system now. It is shown only once; the previous key no longer works.']], 201);
    }

    public function setCredentials(Request $request, string $id): JsonResponse
    {
        Gate::authorize('integrations.manage');
        $connector = IntegrationConnector::query()->findOrFail($id);
        $data = $request->validate([
            'credentials' => ['present', 'array', 'max:20'],
            'credentials.*' => ['nullable', 'string', 'max:8192'],
        ]);
        foreach (array_keys($data['credentials']) as $name) {
            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)) {
                throw new BusinessRuleViolation('credential_name_invalid', 'Credential names are lower-case letters, digits and underscores.', 422);
            }
        }
        $this->connectors->setCredentials($connector, array_filter($data['credentials'], fn ($v) => $v !== null && $v !== ''));

        return response()->json(['data' => $this->connectorData($connector->fresh())]);
    }

    /** Turns request signing on (a new secret, returned once) or off. */
    public function setSigning(Request $request, string $id): JsonResponse
    {
        Gate::authorize('integrations.manage');
        $connector = IntegrationConnector::query()->findOrFail($id);
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $secret = $this->connectors->setSigning($connector, $enabled);

        return response()->json(['data' => array_filter(['signing_enabled' => $enabled, 'signing_secret' => $secret,
            'notice' => $secret ? 'Store this secret in the source system now. It is shown only once.' : null])]);
    }

    public function connectorStatus(string $id, string $action): JsonResponse
    {
        Gate::authorize('integrations.manage');
        $connector = IntegrationConnector::query()->findOrFail($id);
        $status = ['activate' => 'active', 'pause' => 'paused', 'disable' => 'disabled'][$action];

        return response()->json(['data' => $this->connectorData($this->connectors->setStatus($connector, $status))]);
    }

    // ---- Mappings -------------------------------------------------------------------------

    public function mappings(string $id): JsonResponse
    {
        Gate::authorize('integrations.view');
        $connector = IntegrationConnector::query()->findOrFail($id);

        return response()->json(['data' => IntegrationFieldMapping::query()->where('connector_id', $connector->id)->orderBy('entity_type')->orderBy('id')->get()
            ->groupBy('entity_type')->map(fn ($rows) => $rows->map(fn ($m) => $m->only(['target_field', 'source_path', 'transform', 'options', 'default_value']))->values())]);
    }

    public function replaceMappings(Request $request, string $id, string $entity): JsonResponse
    {
        Gate::authorize('integrations.manage');
        $connector = IntegrationConnector::query()->findOrFail($id);
        CanonicalModel::fields($entity); // 422 for an unknown entity
        $data = $request->validate([
            'mappings' => ['present', 'array', 'max:100'],
            'mappings.*.target_field' => ['required', 'string', 'max:64', 'distinct'],
            'mappings.*.source_path' => ['nullable', 'string', 'max:191'],
            'mappings.*.transform' => ['nullable', Rule::in(FieldMapper::TRANSFORMS)],
            'mappings.*.options' => ['nullable', 'array'],
            // Every option needs its own rule: validated() drops nested keys that have none.
            'mappings.*.options.format' => ['nullable', 'string', 'max:32'],
            'mappings.*.options.decimal_separator' => ['nullable', 'string', 'size:1'],
            'mappings.*.options.thousands_separator' => ['nullable', 'string', 'size:1'],
            'mappings.*.options.values' => ['nullable', 'array', 'max:500'],
            'mappings.*.options.values.*' => ['nullable', 'string', 'max:255'],
            'mappings.*.options.fallback' => ['nullable', 'string', 'max:255'],
            'mappings.*.default_value' => ['nullable', 'string', 'max:255'],
        ]);
        $this->connectors->replaceMappings($connector, $entity, $data['mappings']);

        return $this->mappings($id);
    }

    /** Maps and validates sample records without storing anything. */
    public function previewMappings(Request $request, string $id, string $entity, FieldMapper $mapper): JsonResponse
    {
        Gate::authorize('integrations.view');
        $connector = IntegrationConnector::query()->findOrFail($id);
        CanonicalModel::fields($entity);
        $records = $request->validate(['records' => ['required', 'array', 'list', 'min:1', 'max:50'], 'records.*' => ['array']])['records'];

        return response()->json(['data' => array_map(fn ($record) => $this->redact($mapper->preview($connector, $entity, $record)), $records)]);
    }

    public function mappingTemplates(): JsonResponse
    {
        Gate::authorize('integrations.view');

        return response()->json(['data' => MappingTemplates::describe()]);
    }

    /** Copies a template's mappings to the connector (replacing that entity's mappings). */
    public function applyTemplate(Request $request, string $id, string $entity): JsonResponse
    {
        Gate::authorize('integrations.manage');
        $connector = IntegrationConnector::query()->findOrFail($id);
        $template = MappingTemplates::get($request->validate(['template' => ['required', 'string', 'max:64']])['template']);
        if ($template['entity'] !== $entity) {
            throw new BusinessRuleViolation('mapping_template_entity_mismatch', "This template maps {$template['entity']} records, not {$entity}.", 422);
        }
        $this->connectors->replaceMappings($connector, $entity, $template['mappings']);

        return $this->mappings($id);
    }

    // ---- Files, SFTP and exports ---------------------------------------------------------------

    /** Manual upload of a CSV, JSON or XML file (multipart: file, entity_type, format?). */
    public function uploadFile(Request $request, string $id, FileImporter $importer): JsonResponse
    {
        Gate::authorize('integrations.manage');
        $connector = IntegrationConnector::query()->findOrFail($id);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.(int) (config('integrations.files.max_bytes') / 1024)],
            'entity_type' => ['required', 'string', 'max:32'],
            'format' => ['nullable', Rule::in(FileParser::FORMATS)],
        ]);
        $file = $data['file'];
        $result = $importer->import($connector, (string) file_get_contents($file->getRealPath()), $file->getClientOriginalName(), $data['entity_type'],
            $data['format'] ?? null, 'file', $request->user());

        return response()->json(['run' => $result['run']->summary(), 'duplicate_file' => $result['duplicate_file'], 'data' => $result['acknowledgements']], 202);
    }

    /** Runs the SFTP exchange now (it also runs on schedule every five minutes). */
    public function pollSftp(string $id, SftpTransfer $transfer): JsonResponse
    {
        Gate::authorize('integrations.manage');
        $connector = IntegrationConnector::query()->findOrFail($id);
        if ($connector->inbound_transport !== 'sftp' || $connector->status !== 'active') {
            throw new BusinessRuleViolation('connector_not_sftp', 'Only active SFTP connectors can be polled.', 409);
        }

        return response()->json(['data' => $transfer->poll($connector)]);
    }

    /** CSV download of an outbound dataset, with the connector's own product keys. */
    public function export(Request $request, string $id, string $dataset, ExportService $exports)
    {
        Gate::authorize('integrations.manage');
        $file = $exports->export(IntegrationConnector::query()->findOrFail($id), $dataset, 'download', $request->user());

        return response($file['content'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$file['file_name'].'"',
            'X-Integration-Run-Id' => $file['run']->id,
        ]);
    }

    // ---- Messages -------------------------------------------------------------------------

    public function messages(Request $request): JsonResponse
    {
        Gate::authorize('integrations.view');
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['received', 'processing', 'processed', 'quarantined', 'failed', 'ignored'])],
            'connector_id' => ['nullable', 'ulid'],
            'entity_type' => ['nullable', 'string', 'max:32'],
            'run_id' => ['nullable', 'ulid'],
            'message_id' => ['nullable', 'string', 'max:128'],
        ]);

        $page = IntegrationMessage::query()
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['connector_id'] ?? null, fn ($q, $v) => $q->where('connector_id', $v))
            ->when($f['entity_type'] ?? null, fn ($q, $v) => $q->where('entity_type', $v))
            ->when($f['run_id'] ?? null, fn ($q, $v) => $q->where('run_id', $v))
            ->when($f['message_id'] ?? null, fn ($q, $v) => $q->where('message_id', $v))
            ->orderByDesc('id')->paginate(50);

        return response()->json(['data' => collect($page->items())->map(fn ($m) => $this->messageData($m)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function message(string $id): JsonResponse
    {
        Gate::authorize('integrations.view');

        return response()->json(['data' => $this->messageData(IntegrationMessage::query()->findOrFail($id), detail: true)]);
    }

    public function retryMessage(Request $request, string $id, MessageReviewService $review): JsonResponse
    {
        Gate::authorize('integrations.manage');

        return response()->json(['data' => $this->messageData($review->retry(IntegrationMessage::query()->findOrFail($id), $request->user())->fresh(), detail: true)]);
    }

    public function ignoreMessage(Request $request, string $id, MessageReviewService $review): JsonResponse
    {
        Gate::authorize('integrations.manage');
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        return response()->json(['data' => $this->messageData($review->ignore(IntegrationMessage::query()->findOrFail($id), $request->user(), $reason), detail: true)]);
    }

    public function mapMessage(Request $request, string $id, MessageReviewService $review): JsonResponse
    {
        Gate::authorize('integrations.manage');
        $data = $request->validate([
            'product_id' => ['nullable', 'ulid', 'required_without:warehouse_id'],
            'warehouse_id' => ['nullable', 'ulid'],
        ]);

        return response()->json(['data' => $this->messageData($review->map(IntegrationMessage::query()->findOrFail($id), $request->user(), array_filter($data))->fresh(), detail: true)]);
    }

    public function runs(Request $request): JsonResponse
    {
        Gate::authorize('integrations.view');
        $connectorId = $request->validate(['connector_id' => ['nullable', 'ulid']])['connector_id'] ?? null;

        return response()->json(['data' => IntegrationRun::query()->when($connectorId, fn ($q, $v) => $q->where('connector_id', $v))
            ->orderByDesc('started_at')->limit(100)->get()->map(fn ($r) => $r->summary())]);
    }

    public function run(string $id): JsonResponse
    {
        Gate::authorize('integrations.view');

        return response()->json(['data' => IntegrationRun::query()->findOrFail($id)->summary()]);
    }

    // ---- External references ----------------------------------------------------------------

    public function references(Request $request): JsonResponse
    {
        Gate::authorize('integrations.view');
        $f = $request->validate(['connector_id' => ['nullable', 'ulid'], 'entity_type' => ['nullable', Rule::in(['product', 'warehouse'])], 'external_id' => ['nullable', 'string', 'max:128']]);

        $page = ExternalReference::query()
            ->when($f['connector_id'] ?? null, fn ($q, $v) => $q->where('connector_id', $v))
            ->when($f['entity_type'] ?? null, fn ($q, $v) => $q->where('entity_type', $v))
            ->when($f['external_id'] ?? null, fn ($q, $v) => $q->where('external_id', $v))
            ->orderBy('entity_type')->orderBy('external_id')->paginate(100);

        return response()->json(['data' => collect($page->items())->map(fn ($r) => $r->only(['id', 'connector_id', 'entity_type', 'external_id', 'internal_type', 'internal_id', 'source', 'created_at'])),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function storeReference(Request $request, ReferenceResolver $resolver, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('integrations.manage');
        $data = $request->validate([
            'connector_id' => ['required', 'ulid'],
            'entity_type' => ['required', Rule::in(['product', 'warehouse'])],
            'external_id' => ['required', 'string', 'max:128'],
            'internal_id' => ['required', 'ulid'],
        ]);
        $connector = IntegrationConnector::query()->findOrFail($data['connector_id']);
        $exists = $data['entity_type'] === 'product'
            ? Rule::exists('products', 'id')->where('status', 'active')
            : Rule::exists('warehouses', 'id')->where('organization_id', $this->tenant->id());
        $request->validate(['internal_id' => [$exists]]);

        $ref = $resolver->remember($connector, $data['entity_type'], $data['external_id'], $data['entity_type'], $data['internal_id'], 'manual', $request->user()->id);
        $audit->record('integration.reference.mapped', $ref, new: $data);

        return response()->json(['data' => $ref->only(['id', 'connector_id', 'entity_type', 'external_id', 'internal_type', 'internal_id', 'source'])], 201);
    }

    public function destroyReference(string $id, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('integrations.manage');
        $ref = ExternalReference::query()->findOrFail($id);
        $audit->record('integration.reference.removed', $ref, $ref->only(['connector_id', 'entity_type', 'external_id', 'internal_id']));
        $ref->delete();

        return response()->json(null, 204);
    }

    // ---- Monitoring -------------------------------------------------------------------------

    public function health(IntegrationMonitor $monitor): JsonResponse
    {
        Gate::authorize('integrations.view');

        return response()->json(['data' => $monitor->health()]);
    }

    public function inventoryReconciliation(string $id, IntegrationMonitor $monitor): JsonResponse
    {
        Gate::authorize('integrations.view');

        return response()->json(['data' => $monitor->inventoryReconciliation(IntegrationConnector::query()->findOrFail($id))]);
    }

    public function referenceReconciliation(string $id, IntegrationMonitor $monitor): JsonResponse
    {
        Gate::authorize('integrations.view');

        return response()->json(['data' => $monitor->referenceReconciliation(IntegrationConnector::query()->findOrFail($id))]);
    }

    // ---- Presentation -----------------------------------------------------------------------

    private function connectorRules(): array
    {
        return [
            'system_version' => ['nullable', 'string', 'max:64'],
            'deployment_model' => ['nullable', Rule::in(['cloud', 'on_premise', 'hybrid'])],
            'interface_notes' => ['nullable', 'string', 'max:5000'],
            'settings' => ['nullable', 'array'],
            'rate_limit_per_minute' => ['sometimes', 'integer', 'between:1,6000'],
        ];
    }

    private function connectorData(IntegrationConnector $c): array
    {
        return [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'system_type' => $c->system_type,
            'system_type_label' => config('integrations.system_types')[$c->system_type] ?? $c->system_type,
            'system_version' => $c->system_version,
            'deployment_model' => $c->deployment_model,
            'inbound_transport' => $c->inbound_transport,
            'interface_notes' => $c->interface_notes,
            'status' => $c->status,
            'settings' => $c->settings ?? (object) [],
            'rate_limit_per_minute' => $c->rate_limit_per_minute,
            'inbound_key' => ['issued' => $c->inbound_key_hash !== null, 'prefix' => $c->inbound_key_prefix, 'rotated_at' => $c->inbound_key_rotated_at?->toIso8601String()],
            'signing_enabled' => $c->signing_secret !== null,
            'credentials_set' => array_keys($c->credentials ?? []),
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'last_processed_at' => $c->last_processed_at?->toIso8601String(),
            'last_failure_at' => $c->last_failure_at?->toIso8601String(),
            'last_error' => $c->last_error,
            'last_transfer_at' => $c->last_transfer_at?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }

    private function messageData(IntegrationMessage $m, bool $detail = false): array
    {
        $data = [
            'id' => $m->id,
            'message_id' => $m->message_id,
            'connector_id' => $m->connector_id,
            'run_id' => $m->run_id,
            'entity_type' => $m->entity_type,
            'status' => $m->status,
            'attempts' => $m->attempts,
            'error' => $m->error_code ? ['code' => $m->error_code, 'message' => $m->error_message] : null,
            'received_at' => $m->received_at?->toIso8601String(),
            'processed_at' => $m->processed_at?->toIso8601String(),
            'next_attempt_at' => $m->next_attempt_at?->toIso8601String(),
        ];
        if (! $detail) {
            return $data;
        }
        if ($data['error'] !== null && $m->error_details) {
            $data['error']['details'] = $this->redactor->redact($m->error_details);
        }

        return $data + [
            'payload' => $this->redactor->redact($m->payload ?? []),
            'canonical' => $m->canonical === null ? null : $this->redactor->redact($m->canonical),
            'result' => $m->result,
            'resolution' => $m->resolved_at ? ['by' => $m->resolved_by, 'at' => $m->resolved_at->toIso8601String(), 'notes' => $m->resolution_notes] : null,
        ];
    }

    private function redact(array $preview): array
    {
        $preview['canonical'] = $preview['canonical'] === null ? null : $this->redactor->redact($preview['canonical']);

        return $preview;
    }
}
