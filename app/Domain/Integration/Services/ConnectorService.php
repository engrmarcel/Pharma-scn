<?php

namespace App\Domain\Integration\Services;

use App\Domain\Billing\Services\EntitlementService;
use App\Domain\Integration\Canonical\CanonicalModel;
use App\Domain\Integration\Jobs\ProcessIntegrationMessage;
use App\Domain\Integration\Models\IntegrationConnector;
use App\Domain\Integration\Models\IntegrationFieldMapping;
use App\Domain\Integration\Models\IntegrationMessage;
use App\Domain\Platform\Services\AuditLogger;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The external-system registry: connectors, their keys, credentials and mappings.
 *
 * Secrets are write-only. The inbound key is shown once when generated and
 * stored as a SHA-256 hash; credentials and the signing secret are encrypted
 * at rest and the API only ever says which credential names are set.
 */
class ConnectorService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly EntitlementService $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $data, User $actor): IntegrationConnector
    {
        return $this->entitlements->guard($this->tenant->organization(), 'integration_connectors', function () use ($data, $actor) {
            if (IntegrationConnector::query()->where('code', $data['code'])->exists()) {
                throw new BusinessRuleViolation('connector_code_taken', "A connector with code {$data['code']} already exists.", 409);
            }
            $connector = IntegrationConnector::query()->create($data + ['status' => 'draft', 'created_by' => $actor->id]);
            $this->audit->record('integration.connector.created', $connector, new: $data);

            return $connector;
        });
    }

    public function update(IntegrationConnector $connector, array $data): IntegrationConnector
    {
        $original = $connector->getAttributes();
        $connector->fill($data)->save();
        [$old, $new] = $this->audit->diff($connector, $original);
        if ($new !== []) {
            $this->audit->record('integration.connector.updated', $connector, $old, $new);
        }

        return $connector;
    }

    /** @return string the new key, shown once */
    public function rotateKey(IntegrationConnector $connector): string
    {
        $prefix = strtolower(Str::random(12));
        while (IntegrationConnector::acrossTenants()->where('inbound_key_prefix', $prefix)->exists()) {
            $prefix = strtolower(Str::random(12));
        }
        $key = "ck_{$prefix}_".Str::random(40);
        $first = $connector->inbound_key_hash === null;
        $connector->forceFill(['inbound_key_prefix' => $prefix, 'inbound_key_hash' => hash('sha256', $key), 'inbound_key_rotated_at' => now()])->save();
        $this->audit->record($first ? 'integration.connector.key_issued' : 'integration.connector.key_rotated', $connector, new: ['inbound_key_prefix' => $prefix]);

        return $key;
    }

    /** Replaces the stored credentials. Only the names are audited. */
    public function setCredentials(IntegrationConnector $connector, array $credentials): void
    {
        $connector->forceFill(['credentials' => $credentials ?: null])->save();
        $this->audit->record('integration.connector.credentials_changed', $connector, new: ['credential_names' => array_keys($credentials)]);
    }

    /** @return ?string the new secret, shown once (null when signing is turned off) */
    public function setSigning(IntegrationConnector $connector, bool $enabled): ?string
    {
        $secret = $enabled ? Str::random(48) : null;
        $connector->forceFill(['signing_secret' => $secret])->save();
        $this->audit->record($enabled ? 'integration.connector.signing_secret_issued' : 'integration.connector.signing_disabled', $connector);

        return $secret;
    }

    public function setStatus(IntegrationConnector $connector, string $status): IntegrationConnector
    {
        if ($status === 'active' && in_array($connector->inbound_transport, ['rest_push', 'sap_idoc'], true) && $connector->inbound_key_hash === null) {
            throw new BusinessRuleViolation('connector_key_missing', 'Issue an inbound key before activating a connector that pushes data.', 422);
        }
        $before = $connector->status;
        $connector->forceFill(['status' => $status])->save();
        $this->audit->record('integration.connector.status_changed', $connector, ['status' => $before], ['status' => $status]);

        if ($status === 'active' && $before !== 'active') {
            // Resume: process what arrived while the connector was paused.
            IntegrationMessage::query()->where('connector_id', $connector->id)->where('status', 'received')->whereNull('next_attempt_at')
                ->orderBy('id')->pluck('id')->each(fn ($id) => ProcessIntegrationMessage::dispatch($id));
        }

        return $connector;
    }

    /**
     * Replaces the mappings of one entity. Targets must be canonical fields; an empty list
     * removes the mappings (records are then expected in canonical form).
     *
     * @param  list<array{target_field: string, source_path?: ?string, transform?: ?string, options?: ?array, default_value?: ?string}>  $mappings
     */
    public function replaceMappings(IntegrationConnector $connector, string $entity, array $mappings): void
    {
        $allowed = CanonicalModel::topLevelFields($entity);
        foreach ($mappings as $i => $m) {
            if (! in_array($m['target_field'], $allowed, true)) {
                throw new BusinessRuleViolation('mapping_target_unknown', "'{$m['target_field']}' is not a field of the canonical {$entity} model.", 422,
                    ['index' => $i, 'fields' => $allowed]);
            }
            if (($m['source_path'] ?? null) === null && ($m['default_value'] ?? null) === null) {
                throw new BusinessRuleViolation('mapping_source_missing', "Mapping for '{$m['target_field']}' needs a source_path or a default_value.", 422, ['index' => $i]);
            }
            if (($m['transform'] ?? null) === 'map' && empty($m['options']['values'])) {
                throw new BusinessRuleViolation('mapping_values_missing', "The map transform for '{$m['target_field']}' needs options.values.", 422, ['index' => $i]);
            }
        }

        DB::transaction(function () use ($connector, $entity, $mappings) {
            $before = IntegrationFieldMapping::query()->where('connector_id', $connector->id)->where('entity_type', $entity)->get(['target_field', 'source_path', 'transform'])->toArray();
            IntegrationFieldMapping::query()->where('connector_id', $connector->id)->where('entity_type', $entity)->delete();
            foreach ($mappings as $m) {
                IntegrationFieldMapping::query()->create(['connector_id' => $connector->id, 'entity_type' => $entity, 'target_field' => $m['target_field'],
                    'source_path' => $m['source_path'] ?? null, 'transform' => $m['transform'] ?? null, 'options' => $m['options'] ?? null,
                    'default_value' => $m['default_value'] ?? null]);
            }
            $this->audit->record('integration.mappings.replaced', $connector, ['mappings' => $before],
                ['entity_type' => $entity, 'mappings' => array_map(fn ($m) => array_intersect_key($m, array_flip(['target_field', 'source_path', 'transform'])), $mappings)]);
        });
    }
}
