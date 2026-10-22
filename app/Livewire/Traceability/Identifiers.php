<?php

namespace App\Livewire\Traceability;

use App\Livewire\Concerns\PicksProducts;
use App\Livewire\WebPage;
use App\Support\Web\ApiError;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Identifiers: batch and product QR codes, serials, SSCC logistic units and aggregation; revoke or decommission. */
#[Title('Trace identifiers')]
class Identifiers extends WebPage
{
    use PicksProducts;

    public const ROUTE = 'trace.identifiers';

    #[Url]
    public string $kind = '';

    public string $batchNumber = '';

    /** Issue or commission form: mode (issue | serials), fields. */
    public ?array $form = null;

    public ?string $openId = null;

    public string $reason = '';

    /** Ids ticked in the list, for aggregation. */
    public array $selected = [];

    public string $parentId = '';

    public ?string $notice = null;

    protected array $filters = ['kind', 'batchNumber'];

    public function create(string $mode): void
    {
        $this->resetErrorBag();
        $this->form = ['mode' => $mode, 'kind' => 'batch', 'product_id' => '', 'product_name' => null, 'batch_number' => '', 'serial' => '', 'serials' => '', 'parent_id' => ''];
    }

    protected function productPicked(string $target, array $product): void
    {
        $this->form['product_id'] = $product['product_id'];
        $this->form['product_name'] = $product['name'];
    }

    public function save(): void
    {
        $f = $this->form;
        if ($f['mode'] === 'serials') {
            $serials = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $f['serials']))));
            $result = $this->submit('form', 'trace/serials', array_filter(['product_id' => $f['product_id'], 'batch_number' => $f['batch_number'], 'serials' => $serials, 'parent_id' => $f['parent_id']]),
                count($serials).' serials commissioned.');
        } else {
            $result = $this->submit('form', 'trace/identifiers', array_filter(['kind' => $f['kind'], 'product_id' => $f['product_id'], 'batch_number' => $f['batch_number'], 'serial' => $f['serial']]), 'Identifier issued.');
            $this->notice = $result['meta']['regulatory_notice'] ?? null;
        }
        if ($result !== null) {
            $this->form = null;
        }
    }

    public function aggregate(): void
    {
        $children = array_keys(array_filter($this->selected));
        if ($this->submit('aggregate', "trace/identifiers/{$this->parentId}/aggregate", ['child_ids' => $children], count($children).' identifiers packed into the logistic unit.') !== null) {
            $this->selected = [];
        }
    }

    public function changeStatus(string $action): void
    {
        if ($this->submit('status', "trace/identifiers/{$this->openId}/{$action}", ['reason' => $this->reason], $action === 'revoke' ? 'Identifier revoked.' : 'Identifier decommissioned.') !== null) {
            $this->reason = '';
        }
    }

    public function render()
    {
        $result = $this->api()->get('trace/identifiers', ['kind' => $this->kind, 'batch_number' => trim($this->batchNumber), 'page' => $this->page]);
        $rows = $result['data'] ?? [];
        $detail = $this->openId ? collect($rows)->firstWhere('id', $this->openId) : null;
        $qr = null;
        if ($detail) {
            try {
                $qr = 'data:image/svg+xml;base64,'.base64_encode($this->api()->download("trace/identifiers/{$detail['id']}/qr"));
            } catch (ApiError) {
            }
        }

        return view('livewire.traceability.identifiers', [
            'rows' => $rows,
            'meta' => $result['meta'] ?? [],
            'detail' => $detail,
            'qr' => $qr,
            'names' => $this->productNames(array_column($rows, 'product_id')),
            'units' => collect($rows)->where('kind', 'sscc')->where('status', 'active')->pluck('serial', 'id'),
            'canManage' => $this->can('traceability.manage'),
        ]);
    }
}
