<?php

namespace App\Livewire\Organization;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** The organization's audit trail (and, for platform staff, the platform's): who did what, when. */
#[Title('Audit log')]
class AuditTrail extends WebPage
{
    public const ROUTE = 'organization.audit';

    /** API listing to read. */
    protected string $endpoint = 'audit-logs';

    #[Url]
    public string $action = '';

    public string $subjectId = '';

    public ?array $entry = null;

    protected array $filters = ['action', 'subjectId'];

    public function show(array $row): void
    {
        $this->entry = $row;
    }

    public function render()
    {
        $result = $this->api()->get($this->endpoint, ['action' => trim($this->action), 'subject_id' => trim($this->subjectId), 'page' => $this->page]);

        return view('livewire.organization.audit-trail', ['rows' => $result['data'] ?? [], 'meta' => $result['meta'] ?? []]);
    }
}
