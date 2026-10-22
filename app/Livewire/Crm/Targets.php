<?php

namespace App\Livewire\Crm;

use App\Domain\Crm\Models\SalesTarget;
use App\Livewire\Concerns\PicksProducts;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Targets for representatives or territories, with achievement against the time elapsed. */
#[Title('Targets')]
class Targets extends WebPage
{
    use PicksProducts;

    public const ROUTE = 'crm.targets';

    public ?array $form = null;

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = ['scope_type' => 'representative', 'representative_id' => '', 'territory_id' => '', 'metric' => 'invoiced_revenue', 'product_id' => '', 'product_name' => null,
            'period_start' => business_today()->startOfMonth()->toDateString(), 'period_end' => business_today()->endOfMonth()->toDateString(), 'target_value' => '', 'notes' => ''];
    }

    protected function productPicked(string $target, array $product): void
    {
        $this->form['product_id'] = $product['product_id'];
        $this->form['product_name'] = $product['name'];
    }

    public function save(): void
    {
        $data = array_filter(collect($this->form)->except('product_name')->all(), fn ($v) => $v !== '');
        if (isset($data['representative_id'])) {
            $data['representative_id'] = (int) $data['representative_id'];
        }
        if ($this->submit('form', 'crm/targets', $data, 'Target set.') !== null) {
            $this->form = null;
        }
    }

    public function remove(string $id): void
    {
        $this->attempt(fn ($api) => $api->delete("crm/targets/{$id}"), 'Target removed.');
    }

    public function render()
    {
        $rows = $this->api()->get('crm/targets')['data'] ?? [];

        return view('livewire.crm.targets', [
            'rows' => $rows,
            'people' => collect($this->read('members')['data'] ?? [])->mapWithKeys(fn ($m) => [$m['user']['id'] => $m['user']['name']]),
            'territories' => collect($this->read('field-force/territories')['data'] ?? [])->pluck('name', 'id'),
            'names' => $this->productNames(array_column($rows, 'product_id')),
            'metrics' => SalesTarget::METRICS,
            'currency' => $this->currency(),
            'canManage' => $this->can('crm.targets.manage'),
        ]);
    }
}
