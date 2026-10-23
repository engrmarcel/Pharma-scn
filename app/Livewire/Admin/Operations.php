<?php

namespace App\Livewire\Admin;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Queue and integration health, review backlogs, and failed jobs that can be retried. */
#[Title('Operations')]
class Operations extends WebPage
{
    public const ROUTE = 'admin.operations';

    public function retry(string $uuid): void
    {
        $this->attempt(fn () => $this->api()->post("admin/operations/failed-jobs/{$uuid}/retry"), 'Job sent back to the queue.');
    }

    public function render()
    {
        return view('livewire.admin.operations', [
            'status' => $this->read('admin/operations/status', [], ['data' => null])['data'],
            'failed' => $this->read('admin/operations/failed-jobs', [], ['data' => []])['data'],
        ]);
    }
}
