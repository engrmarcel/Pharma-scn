<?php

namespace App\Livewire\Analytics;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** Ask a question about the organization's data; the answer is written from read-only reports run under the asker's permissions. */
#[Title('Reporting assistant')]
class Assistant extends WebPage
{
    public const ROUTE = 'analytics.assistant';

    public string $question = '';

    /** Questions and answers in this session, newest last. */
    public array $conversation = [];

    public bool $acknowledge = false;

    public function ask(): void
    {
        $q = trim($this->question);
        $r = $this->submit('ask', 'analytics/assistant', ['question' => $q]);
        if ($r !== null) {
            $a = $r['data'];
            $this->conversation[] = ['question' => $q, 'answer' => $a['answer'] ?? '', 'caveats' => $a['caveats'] ?? [], 'label' => $a['label'] ?? null,
                'planner' => $a['planner'] ?? null, 'reports' => array_map(fn ($s) => ($s['report'] ?? $s['tool'] ?? '').(isset($s['status']) ? ' ('.$s['status'].')' : ''), $a['steps'] ?? [])];
            $this->question = '';
        }
    }

    public function setExternal(bool $on): void
    {
        $this->submit('settings', 'analytics/assistant/settings', ['external_processing' => $on, 'acknowledge_external_processing' => $this->acknowledge],
            $on ? 'External language processing turned on.' : 'External language processing turned off.', 'put');
    }

    public function render()
    {
        return view('livewire.analytics.assistant', ['settings' => $this->read('analytics/assistant/settings')['data'] ?? null]);
    }
}
