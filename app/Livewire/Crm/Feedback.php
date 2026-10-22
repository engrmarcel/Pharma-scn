<?php

namespace App\Livewire\Crm;

use App\Domain\Crm\Models\CustomerFeedback;
use App\Livewire\Concerns\PicksProducts;
use App\Livewire\WebPage;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;

/** Customer feedback: complaints, quality issues, praise; review and resolve; possible adverse events are flagged. */
#[Title('Customer feedback')]
class Feedback extends WebPage
{
    use PicksProducts;

    public const ROUTE = 'crm.feedback';

    public string $status = 'open';

    public string $category = '';

    public ?array $form = null;

    public ?array $progress = null;

    public ?string $notice = null;

    protected array $filters = ['status', 'category'];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = ['customer_id' => '', 'product_id' => '', 'product_name' => null, 'category' => 'complaint', 'rating' => '', 'description' => '', 'possible_adverse_event' => false];
    }

    protected function productPicked(string $target, array $product): void
    {
        $this->form['product_id'] = $product['product_id'];
        $this->form['product_name'] = $product['name'];
    }

    public function save(): void
    {
        $data = array_filter(collect($this->form)->except('product_name')->all(), fn ($v) => $v !== '' && $v !== null);
        $data['possible_adverse_event'] = (bool) $this->form['possible_adverse_event'];
        $result = $this->submit('form', 'crm/feedback', $data + ['client_reference' => 'web-'.Str::ulid()], 'Feedback recorded.');
        if ($result !== null) {
            $this->notice = $result['meta']['pharmacovigilance_notice'] ?? null;
            $this->form = null;
        }
    }

    public function start(string $id, string $action): void
    {
        $this->resetErrorBag();
        $this->progress = ['id' => $id, 'action' => $action, 'resolution' => '', 'pharmacovigilance_reference' => ''];
    }

    public function apply(): void
    {
        $p = $this->progress;
        if ($this->submit('progress', "crm/feedback/{$p['id']}/{$p['action']}", array_filter(['resolution' => $p['resolution'], 'pharmacovigilance_reference' => $p['pharmacovigilance_reference']]),
            $p['action'] === 'resolve' ? 'Feedback resolved.' : 'Feedback moved to review.') !== null) {
            $this->progress = null;
        }
    }

    public function render()
    {
        $result = $this->api()->get('crm/feedback', ['status' => $this->status, 'category' => $this->category, 'page' => $this->page]);
        $rows = $result['data'] ?? [];

        return view('livewire.crm.feedback', [
            'rows' => $rows,
            'meta' => $result,
            'customers' => collect($this->read('field-force/customers')['data'] ?? [])->pluck('name', 'id'),
            'names' => $this->productNames(array_column($rows, 'product_id')),
            'categories' => CustomerFeedback::CATEGORIES,
            'canCapture' => $this->can('field_force.visits.capture'),
            'canManage' => $this->can('crm.feedback.manage'),
        ]);
    }
}
