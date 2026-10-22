<?php

namespace App\Livewire\Integration;

use App\Domain\Integration\Files\ExportService;
use App\Domain\Integration\Files\FileParser;
use App\Domain\Integration\Services\FieldMapper;
use App\Livewire\WebPage;
use App\Support\Web\ApiError;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithFileUploads;

/**
 * Integration connectors: registration, inbound key and request signing (secrets shown once),
 * credentials, status, field mappings with templates and preview, file uploads, SFTP polling,
 * exports and reconciliation.
 */
#[Title('Integration connectors')]
class Connectors extends WebPage
{
    use WithFileUploads;

    public const ROUTE = 'integration.connectors';

    #[Url(as: 'open')]
    public ?string $openId = null;

    public string $tab = 'overview';

    public ?array $form = null;

    /** A secret just issued, shown once: [label, value, notice]. */
    public ?array $secret = null;

    public array $credentials = [['name' => '', 'value' => '']];

    public string $entity = 'product';

    public ?array $mappingRows = null;

    public string $template = '';

    public string $sample = '';

    public ?array $preview = null;

    public $file = null;

    public string $fileEntity = 'inventory_snapshot';

    public string $fileFormat = '';

    public ?array $fileResult = null;

    public function open(string $id): void
    {
        $this->openId = $id;
        $this->tab = 'overview';
        $this->secret = null;
        $this->mappingRows = null;
        $this->preview = null;
        $this->fileResult = null;
    }

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = ['id' => null, 'code' => '', 'name' => '', 'system_type' => 'sap_s4hana', 'system_version' => '', 'deployment_model' => '', 'inbound_transport' => 'rest_push',
            'interface_notes' => '', 'rate_limit_per_minute' => '600'];
    }

    public function edit(array $c): void
    {
        $this->resetErrorBag();
        $this->form = ['id' => $c['id']] + array_map(fn ($v) => $v === null ? '' : (string) $v, array_intersect_key($c, array_flip(['code', 'name', 'system_type', 'system_version', 'deployment_model', 'inbound_transport', 'interface_notes', 'rate_limit_per_minute'])));
    }

    public function save(): void
    {
        $f = $this->form;
        $data = array_filter(collect($f)->except(['id'])->all(), fn ($v) => $v !== '');
        if (isset($data['rate_limit_per_minute'])) {
            $data['rate_limit_per_minute'] = (int) $data['rate_limit_per_minute'];
        }
        $result = $f['id'] ? $this->submit('form', "integration/connectors/{$f['id']}", collect($data)->except('code')->all(), 'Connector updated.', 'patch')
            : $this->submit('form', 'integration/connectors', $data, 'Connector registered. Issue an inbound key next.');
        if ($result !== null) {
            $this->form = null;
            $this->open($result['data']['id']);
        }
    }

    public function setStatus(string $action): void
    {
        $this->attempt(fn ($api) => $api->post("integration/connectors/{$this->openId}/{$action}"), 'Connector '.['activate' => 'activated', 'pause' => 'paused', 'disable' => 'disabled'][$action].'.');
    }

    public function rotateKey(): void
    {
        $r = $this->attempt(fn ($api) => $api->post("integration/connectors/{$this->openId}/key"));
        if ($r !== null) {
            $this->secret = ['label' => 'Inbound API key', 'value' => $r['data']['key'], 'notice' => $r['data']['notice']];
        }
    }

    public function setSigning(bool $enabled): void
    {
        $r = $this->attempt(fn ($api) => $api->post("integration/connectors/{$this->openId}/signing", ['enabled' => $enabled]), $enabled ? null : 'Request signing turned off.');
        if ($r !== null && ($r['data']['signing_secret'] ?? null)) {
            $this->secret = ['label' => 'Request signing secret', 'value' => $r['data']['signing_secret'], 'notice' => $r['data']['notice']];
        }
    }

    public function addCredential(): void
    {
        $this->credentials[] = ['name' => '', 'value' => ''];
    }

    public function saveCredentials(): void
    {
        $creds = collect($this->credentials)->filter(fn ($c) => $c['name'] !== '')->mapWithKeys(fn ($c) => [$c['name'] => $c['value'] === '' ? null : $c['value']])->all();
        if ($this->submit('credentials', "integration/connectors/{$this->openId}/credentials", ['credentials' => $creds], 'Credentials saved (stored encrypted).', 'put') !== null) {
            $this->credentials = [['name' => '', 'value' => '']];
        }
    }

    public function loadMappings(): void
    {
        $all = $this->read("integration/connectors/{$this->openId}/mappings")['data'] ?? [];
        $this->mappingRows = array_map(fn ($m) => ['target_field' => $m['target_field'], 'source_path' => $m['source_path'] ?? '', 'transform' => $m['transform'] ?? '', 'default_value' => $m['default_value'] ?? ''],
            $all[$this->entity] ?? []);
        if ($this->mappingRows === []) {
            $this->mappingRows[] = ['target_field' => '', 'source_path' => '', 'transform' => '', 'default_value' => ''];
        }
        $this->preview = null;
    }

    public function updatedEntity(): void
    {
        $this->loadMappings();
    }

    public function addMapping(): void
    {
        $this->mappingRows[] = ['target_field' => '', 'source_path' => '', 'transform' => '', 'default_value' => ''];
    }

    public function removeMapping(int $i): void
    {
        unset($this->mappingRows[$i]);
        $this->mappingRows = array_values($this->mappingRows);
    }

    public function saveMappings(): void
    {
        $rows = array_values(array_map(fn ($r) => array_filter($r, fn ($v) => $v !== ''), array_filter($this->mappingRows, fn ($r) => $r['target_field'] !== '')));
        $this->submit('mappings', "integration/connectors/{$this->openId}/mappings/{$this->entity}", ['mappings' => $rows], 'Mappings saved.', 'put');
    }

    public function applyTemplate(): void
    {
        if ($this->submit('mappings', "integration/connectors/{$this->openId}/mappings/{$this->entity}/template", ['template' => $this->template], 'Template applied.') !== null) {
            $this->loadMappings();
        }
    }

    public function runPreview(): void
    {
        $records = json_decode($this->sample, true);
        if (! is_array($records)) {
            $this->addError('sample', 'Paste one record as a JSON object, or several as a JSON array.');

            return;
        }
        $records = array_is_list($records) ? $records : [$records];
        $this->preview = $this->submit('preview', "integration/connectors/{$this->openId}/mappings/{$this->entity}/preview", ['records' => $records])['data'] ?? null;
    }

    public function uploadFile(): void
    {
        $this->validate(['file' => ['required', 'file']]);
        $r = $this->attempt(fn ($api) => $api->upload("integration/connectors/{$this->openId}/files", array_filter(['entity_type' => $this->fileEntity, 'format' => $this->fileFormat]), ['file' => $this->file], 'upload'));
        if ($r !== null) {
            $this->fileResult = $r;
            $this->file = null;
            $this->notify($r['duplicate_file'] ? 'This file was already received; nothing new was processed.' : 'File received and queued for processing.');
        }
    }

    public function pollSftp(): void
    {
        $r = $this->attempt(fn ($api) => $api->post("integration/connectors/{$this->openId}/sftp/poll"), 'SFTP folder checked.');
        $this->fileResult = $r ? ['sftp' => $r['data']] : null;
    }

    public function export(string $dataset)
    {
        try {
            $csv = $this->api()->download("integration/connectors/{$this->openId}/exports/{$dataset}");
        } catch (ApiError $e) {
            $this->notify($e->getMessage(), 'error');

            return null;
        }

        return response()->streamDownload(fn () => print ($csv), "{$dataset}-".business_today()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    public function render()
    {
        $connectors = $this->api()->get('integration/connectors')['data'] ?? [];
        $detail = $this->openId ? collect($connectors)->firstWhere('id', $this->openId) : null;
        if ($detail && $this->tab === 'mappings' && $this->mappingRows === null) {
            $this->loadMappings();
        }
        $canonical = $detail && in_array($this->tab, ['mappings', 'files'], true) ? ($this->read('integration/canonical-model')['data']['entities'] ?? []) : [];

        return view('livewire.integration.connectors', [
            'connectors' => $connectors,
            'detail' => $detail,
            'canonical' => $canonical,
            'templates' => $detail && $this->tab === 'mappings' ? collect($this->read('integration/mapping-templates')['data'] ?? [])->where('entity', $this->entity)->values() : collect(),
            'reconciliation' => $detail && $this->tab === 'reconciliation' ? [
                'inventory' => $this->read("integration/connectors/{$this->openId}/reconciliation/inventory", default: ['data' => null])['data'],
                'references' => $this->read("integration/connectors/{$this->openId}/reconciliation/references", default: ['data' => null])['data'],
            ] : null,
            'systemTypes' => config('integrations.system_types'),
            'transports' => config('integrations.inbound_transports'),
            'transforms' => FieldMapper::TRANSFORMS,
            'formats' => FileParser::FORMATS,
            'datasets' => ExportService::DATASETS,
            'canManage' => $this->can('integrations.manage'),
        ]);
    }
}
