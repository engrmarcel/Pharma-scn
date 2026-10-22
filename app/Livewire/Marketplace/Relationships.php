<?php

namespace App\Livewire\Marketplace;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Trading relationships: suppliers approve buyers (with payment terms) before they can order; either side can suspend. */
#[Title('Trading partners')]
class Relationships extends WebPage
{
    public const ROUTE = 'marketplace.relationships';

    #[Url]
    public string $as = '';

    public string $status = '';

    public ?array $deciding = null;

    protected array $filters = ['as', 'status'];

    public function start(string $id, string $action, string $name): void
    {
        $this->resetErrorBag();
        $this->deciding = ['id' => $id, 'action' => $action, 'name' => $name, 'notes' => '', 'payment_terms_days' => '30'];
    }

    public function apply(): void
    {
        $d = $this->deciding;
        $result = $d['action'] === 'suspend'
            ? $this->submit('deciding', "marketplace/relationships/{$d['id']}/suspend", ['reason' => $d['notes']], 'Relationship suspended.')
            : $this->submit('deciding', "marketplace/relationships/{$d['id']}/{$d['action']}", array_filter(['notes' => $d['notes'], 'payment_terms_days' => $d['action'] === 'approve' ? (int) $d['payment_terms_days'] : null], fn ($v) => $v !== '' && $v !== null),
                $d['action'] === 'approve' ? 'Buyer approved.' : 'Request rejected.');
        if ($result !== null) {
            $this->deciding = null;
        }
    }

    public function render()
    {
        $result = $this->api()->get('marketplace/relationships', ['as' => $this->as, 'status' => $this->status, 'page' => $this->page]);

        return view('livewire.marketplace.relationships', ['rows' => $result['data'] ?? [], 'meta' => $result, 'canManage' => $this->can('marketplace.relationships.manage')]);
    }
}
