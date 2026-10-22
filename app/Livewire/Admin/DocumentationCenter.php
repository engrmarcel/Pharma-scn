<?php

namespace App\Livewire\Admin;

use App\Domain\Platform\Services\DocumentationService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Administration panel: the platform documentation (setup, administration, user guide,
 * application flows, API, external integration), read on screen or downloaded as PDF,
 * one document or all of them together.
 */
#[Layout('layouts.app')]
#[Title('Documentation')]
class DocumentationCenter extends Component
{
    #[Url(as: 'doc')]
    public string $doc = 'setup';

    public function mount(DocumentationService $docs): void
    {
        Gate::authorize('platform.documentation.view');
        if (! $docs->exists($this->doc)) {
            $this->doc = 'setup';
        }
    }

    public function show(string $slug, DocumentationService $docs): void
    {
        Gate::authorize('platform.documentation.view');
        $this->doc = $docs->exists($slug) ? $slug : 'setup';
    }

    public function render(DocumentationService $docs)
    {
        Gate::authorize('platform.documentation.view');

        return view('livewire.admin.documentation-center', [
            'documents' => DocumentationService::DOCUMENTS,
            'current' => $docs->document($this->doc),
        ]);
    }
}
