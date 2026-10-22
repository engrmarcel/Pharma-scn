<?php

namespace App\Livewire\Compliance;

use App\Domain\Platform\Support\CountryProfile;
use App\Livewire\Concerns\PicksProducts;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Regulatory incidents (theft, loss, suspected falsified packs …) with the country's reporting deadline. */
#[Title('Regulatory incidents')]
class Incidents extends WebPage
{
    use PicksProducts;

    public const ROUTE = 'recalls.incidents';

    public string $status = '';

    public bool $overdue = false;

    public ?array $form = null;

    public ?array $acting = null;

    protected array $filters = ['status', 'overdue'];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = ['type' => 'suspected_falsified', 'product_id' => '', 'product_name' => null, 'batch_number' => '', 'quantity' => '', 'description' => '', 'detected_at' => now()->format('Y-m-d\TH:i')];
    }

    protected function productPicked(string $target, array $product): void
    {
        $this->form['product_id'] = $product['product_id'];
        $this->form['product_name'] = $product['name'];
    }

    public function save(): void
    {
        if ($this->submit('form', 'regulatory-incidents', array_filter(collect($this->form)->except('product_name')->all(), fn ($v) => $v !== ''), 'Incident recorded.') !== null) {
            $this->form = null;
        }
    }

    public function start(string $id, string $action): void
    {
        $this->resetErrorBag();
        $this->acting = ['id' => $id, 'action' => $action, 'authority_reference' => '', 'notes' => ''];
    }

    public function apply(): void
    {
        $a = $this->acting;
        $body = $a['action'] === 'report' ? ['authority_reference' => $a['authority_reference']] : ['notes' => $a['notes']];
        if ($this->submit('acting', "regulatory-incidents/{$a['id']}/{$a['action']}", $body, $a['action'] === 'report' ? 'Marked as reported to the regulator.' : 'Incident closed.') !== null) {
            $this->acting = null;
        }
    }

    public function render()
    {
        $rows = $this->api()->get('regulatory-incidents', ['status' => $this->status, 'overdue' => $this->overdue ? 1 : null])['data'] ?? [];

        return view('livewire.compliance.incidents', [
            'rows' => $rows,
            'types' => CountryProfile::INCIDENT_TYPES,
            'names' => $this->productNames(array_column($rows, 'product_id')),
        ]);
    }
}
