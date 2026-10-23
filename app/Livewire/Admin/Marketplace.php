<?php

namespace App\Livewire\Admin;

use App\Domain\Organizations\Models\Organization;
use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Marketplace oversight: disputes escalated to the platform (add notes) and fees (waive with a reason). */
#[Title('Marketplace oversight')]
class Marketplace extends WebPage
{
    public const ROUTE = 'admin.marketplace';

    public string $tab = 'disputes';

    public string $disputeStatus = 'escalated';

    public ?string $openId = null;

    public string $note = '';

    public ?array $waiving = null;

    protected array $filters = ['tab', 'disputeStatus'];

    public function addNote(): void
    {
        if ($this->submit('note', "admin/marketplace/disputes/{$this->openId}/notes", ['body' => $this->note], 'Note added; both parties can see it.') !== null) {
            $this->note = '';
        }
    }

    public function waive(): void
    {
        if ($this->submit('waiving', "admin/marketplace/fees/{$this->waiving['id']}/waive", ['reason' => $this->waiving['reason']], 'Fee waived.') !== null) {
            $this->waiving = null;
        }
    }

    public function render()
    {
        $disputes = $this->tab === 'disputes' ? $this->api()->get('admin/marketplace/disputes', ['status' => $this->disputeStatus, 'page' => $this->page]) : [];
        // Fees come back as {data: paginator, meta: {by_supplier}}.
        $fees = $this->tab === 'fees' ? $this->api()->get('admin/marketplace/fees', ['page' => $this->page]) : [];
        $feeRows = $fees['data']['data'] ?? [];
        $orgIds = collect($disputes['data'] ?? [])->flatMap(fn ($d) => [$d['buyer_organization_id'], $d['supplier_organization_id']])
            ->merge(collect($feeRows)->pluck('supplier_organization_id'))->merge(collect($fees['meta']['by_supplier'] ?? [])->pluck('supplier_organization_id'));

        return view('livewire.admin.marketplace', [
            'disputes' => $disputes['data'] ?? [],
            'meta' => $disputes,
            'fees' => $feeRows,
            'feeMeta' => $fees['data'] ?? [],
            'bySupplier' => $fees['meta']['by_supplier'] ?? [],
            'orgs' => Organization::query()->whereIn('id', $orgIds->filter()->unique())->pluck('name', 'id'),
            'detail' => $this->openId ? collect($disputes['data'] ?? [])->firstWhere('id', $this->openId) : null,
        ]);
    }
}
