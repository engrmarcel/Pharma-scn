<?php

namespace App\Livewire\Compliance;

use App\Domain\Traceability\Models\Recall;
use App\Livewire\Concerns\PicksProducts;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * Recalls issued by the organization (or, in the administration panel, by the platform):
 * draft, activate (notices go to every holder), follow exposure, close or cancel.
 */
#[Title('Recalls')]
class Recalls extends WebPage
{
    use PicksProducts;

    public const ROUTE = 'recalls.index';

    /** API path prefix: '' for organizations, 'admin/' for platform staff. */
    protected string $prefix = '';

    #[Url]
    public string $status = '';

    #[Url(as: 'open')]
    public ?string $openId = null;

    public ?array $form = null;

    public ?array $closing = null;

    protected array $filters = ['status'];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = ['product_id' => '', 'product_name' => null, 'batch_numbers' => '', 'title' => '', 'reason' => '', 'classification' => 'class_ii', 'depth' => 'retail',
            'authority' => '', 'regulatory_reference' => '', 'quarantine_instructions' => 'Quarantine all stock of the listed batches immediately and stop selling or dispensing it.'];
    }

    protected function productPicked(string $target, array $product): void
    {
        $this->form['product_id'] = $product['product_id'];
        $this->form['product_name'] = $product['name'];
    }

    public function save(): void
    {
        $data = collect($this->form)->except('product_name')->all();
        $data['batch_numbers'] = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', strtoupper($data['batch_numbers'])))));
        $result = $this->submit('form', $this->prefix.'recalls', array_filter($data, fn ($v) => $v !== ''), 'Recall drafted. Review it, then activate to notify holders.');
        if ($result !== null) {
            $this->form = null;
            $this->openId = $result['data']['id'];
        }
    }

    public function activate(): void
    {
        $this->attempt(fn ($api) => $api->post($this->prefix."recalls/{$this->openId}/activate"), 'Recall active: holders have been notified and stock quarantined.');
    }

    public function startClose(string $action): void
    {
        $this->resetErrorBag();
        $this->closing = ['action' => $action, 'notes' => '', 'override_reason' => '', 'reason' => ''];
    }

    public function finish(): void
    {
        $c = $this->closing;
        $body = $c['action'] === 'close' ? array_filter(['notes' => $c['notes'], 'override_reason' => $c['override_reason']]) : ['reason' => $c['reason']];
        if ($this->submit('closing', $this->prefix."recalls/{$this->openId}/{$c['action']}", $body, $c['action'] === 'close' ? 'Recall closed.' : 'Recall cancelled.') !== null) {
            $this->closing = null;
        }
    }

    public function render()
    {
        $rows = $this->api()->get($this->prefix.'recalls', ['status' => $this->status])['data'] ?? [];
        $detail = $this->openId ? ($this->read($this->prefix."recalls/{$this->openId}")['data'] ?? null) : null;
        $exposure = $detail && $detail['status'] !== 'draft' ? ($this->read($this->prefix."recalls/{$this->openId}/exposure", default: ['data' => null])['data']) : null;

        return view('livewire.compliance.recalls', [
            'rows' => $rows,
            'detail' => $detail,
            'exposure' => $exposure,
            'names' => $this->productNames(array_merge(array_column($rows, 'product_id'), [$detail['product_id'] ?? null])),
            'classifications' => Recall::CLASSIFICATIONS,
            'depths' => Recall::DEPTHS,
            'canInitiate' => $this->can('recalls.initiate', 'recalls.manage'),
        ]);
    }
}
