<?php

namespace App\Livewire\Analytics;

use App\Domain\Analytics\Assistant\AssistantService;
use App\Domain\Analytics\Services\ExecutiveDashboard as Dashboard;
use App\Domain\Analytics\Services\RecommendationService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Web executive dashboard. Uses the same service as GET /analytics/executive, so it
 * shows exactly what the API would for this member: sections outside their
 * permissions or locations are listed as withheld.
 */
#[Layout('layouts.app')]
#[Title('Executive dashboard')]
class ExecutiveDashboard extends Component
{
    public string $from = '';

    public string $to = '';

    public string $question = '';

    /** The last answer from the reporting assistant. */
    public ?array $answer = null;

    public function mount(): void
    {
        Gate::authorize('analytics.view');
        $this->from = business_today()->subDays(29)->toDateString();
        $this->to = business_today()->toDateString();
    }

    public function ask(AssistantService $assistant): void
    {
        $this->validate(['question' => ['required', 'string', 'min:3', 'max:500']]);
        $result = $assistant->ask($this->question, auth()->user());
        $this->answer = ['text' => $result['answer'], 'caveats' => $result['caveats'], 'label' => $result['label'], 'planner' => $result['planner'],
            'reports' => array_map(fn ($s) => $s['report'].' ('.$s['status'].')', $result['steps'])];
    }

    public function render(Dashboard $dashboard, RecommendationService $recommendations)
    {
        Gate::authorize('analytics.view');
        $this->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today']]);

        return view('livewire.analytics.executive-dashboard', [
            'board' => $dashboard->build($this->from, $this->to),
            'recommendations' => $recommendations->visible()->where('status', 'open')
                ->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")->orderByDesc('generated_at')->limit(6)->get(),
        ]);
    }
}
