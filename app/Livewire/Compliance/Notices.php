<?php

namespace App\Livewire\Compliance;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Recall notices received: what the organization held, where it went, acknowledge and complete. */
#[Title('Recall notices')]
class Notices extends WebPage
{
    public const ROUTE = 'recalls.notices';

    #[Url]
    public string $status = '';

    #[Url(as: 'open')]
    public ?string $openId = null;

    public string $notes = '';

    protected array $filters = ['status'];

    public function act(string $action): void
    {
        if ($this->submit('notice', "recall-notices/{$this->openId}/{$action}", ['notes' => $this->notes ?: null], $action === 'acknowledge' ? 'Notice acknowledged.' : 'Recall actions completed.') !== null) {
            $this->notes = '';
        }
    }

    public function render()
    {
        $rows = $this->api()->get('recall-notices', ['status' => $this->status])['data'] ?? [];
        $detail = $this->openId ? ($this->read("recall-notices/{$this->openId}")['data'] ?? null) : null;

        return view('livewire.compliance.notices', [
            'rows' => $rows,
            'detail' => $detail,
            'names' => $this->productNames(collect($rows)->pluck('recall.product_id')->merge([$detail['recall']['product_id'] ?? null])),
        ]);
    }
}
