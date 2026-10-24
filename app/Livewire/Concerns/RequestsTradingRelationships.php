<?php

namespace App\Livewire\Concerns;

/** "Request to trade" with a supplier, shared by Discover, the supplier directory and storefronts. */
trait RequestsTradingRelationships
{
    public ?array $requesting = null;

    public function startRequest(string $supplierId, string $name): void
    {
        $this->resetErrorBag();
        $this->requesting = ['supplier_organization_id' => $supplierId, 'name' => $name, 'message' => ''];
    }

    public function sendRequest(): void
    {
        $r = $this->requesting;
        if ($this->submit('requesting', 'marketplace/relationships', ['supplier_organization_id' => $r['supplier_organization_id'], 'message' => $r['message'] ?: null],
            'Trading request sent. The supplier approves it and sets your payment terms.') !== null) {
            $this->requesting = null;
        }
    }
}
