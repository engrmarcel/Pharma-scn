<?php

namespace App\Domain\Analytics\Assistant;

use App\Domain\Platform\Services\AuditLogger;
use App\Models\User;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The reporting assistant (spec §19.2 natural-language business reporting).
 *
 * question → plan (local rules, or an external model when the platform is configured for it
 * AND the organization has consented) → plan checked against ReportCatalog (read-only reports
 * only, at most five) → reports run as the asking user, under their permissions and locations
 * → answer written locally from the results, labelled as a generated explanation.
 *
 * Only the redacted question can leave the platform; report data never does.
 */
class AssistantService
{
    public const MAX_STEPS = 5;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ReportCatalog $catalog,
        private readonly RuleBasedPlanner $rules,
        private readonly AnswerComposer $composer,
        private readonly AuditLogger $audit,
    ) {}

    public function ask(string $question, User $user): array
    {
        Gate::authorize('analytics.view');
        $started = hrtime(true);
        $today = business_today();
        $redacted = Redactor::redact($question);

        [$planner, $plan, $notice] = $this->plan($question, $redacted, $today->toDateString());
        $steps = [];
        $rejected = [];
        foreach (array_slice($plan['steps'], 0, self::MAX_STEPS) as $step) {
            if (! array_key_exists($step['tool'] ?? '', ReportCatalog::TOOLS)) {
                $rejected[] = (string) ($step['tool'] ?? '');

                continue;
            }
            [$from, $to, $label] = $this->period($step, $question, $today);
            $result = $this->catalog->run(['tool' => $step['tool'], 'from' => $from, 'to' => $to, 'product_query' => $step['product_query'] ?? null]);
            $steps[] = ['tool' => $step['tool'], 'from' => $from, 'to' => $to, 'period_label' => $label, 'result' => $result];
        }

        $answer = $this->composer->compose($steps, $plan['declined_actions'] ?? []);
        $duration = (int) ((hrtime(true) - $started) / 1e6);
        DB::table('analytics_assistant_queries')->insert(['organization_id' => $this->tenant->id(), 'user_id' => $user->id, 'question' => mb_substr($redacted, 0, 1000),
            'planner' => $planner, 'tools' => json_encode(array_column($steps, 'tool')), 'statuses' => json_encode(array_map(fn ($s) => $s['result']['status'], $steps)),
            'rejected_tools' => $rejected ? json_encode($rejected) : null, 'duration_ms' => $duration, 'created_at' => now()]);

        return [
            'kind' => 'generated_explanation',
            'label' => 'Generated from your reports. Check the figures in the dashboards before acting; the assistant cannot change anything.',
            'answer' => $answer['text'],
            'caveats' => array_values(array_filter([$notice, ...$answer['caveats']])),
            'planner' => $planner,
            'steps' => array_map(fn ($s) => ['report' => $s['tool'], 'from' => $s['from'], 'to' => $s['to'], 'status' => $s['result']['status'],
                'data' => $s['result']['data'] ?? null, 'message' => $s['result']['message'] ?? null], $steps),
            'declined_actions' => $plan['declined_actions'] ?? [],
            'rejected_reports' => $rejected,
        ];
    }

    /** @return array{external_processing: bool, available: bool, consented_by: ?int, consented_at: ?string, provider: ?string} */
    public function settings(): array
    {
        $s = data_get($this->tenant->organization()->settings, 'analytics.assistant', []);

        return ['external_processing' => (bool) ($s['external_processing'] ?? false), 'available' => $this->externalAvailable(),
            'provider' => $this->externalAvailable() ? config('analytics.assistant.provider') : null,
            'consented_by' => $s['consented_by'] ?? null, 'consented_at' => $s['consented_at'] ?? null,
            'what_is_sent' => 'Only the question, with e-mail addresses, phone numbers and long numbers removed. Report data is never sent.'];
    }

    public function updateSettings(bool $external, bool $acknowledged, User $actor): array
    {
        Gate::authorize('organization.manage');
        if ($external && ! $acknowledged) {
            throw new BusinessRuleViolation('acknowledgement_required', 'Confirm that questions may be sent to the external AI provider (with personal identifiers removed) under your agreement with it.', 422);
        }
        if ($external && ! $this->externalAvailable()) {
            throw new BusinessRuleViolation('external_ai_unavailable', 'External AI processing is not configured on this platform.', 409);
        }
        $org = $this->tenant->organization();
        $settings = $org->settings ?? [];
        $old = data_get($settings, 'analytics.assistant.external_processing', false);
        data_set($settings, 'analytics.assistant', ['external_processing' => $external, 'consented_by' => $external ? $actor->id : null,
            'consented_at' => $external ? now()->toIso8601String() : null]);
        $org->forceFill(['settings' => $settings])->save();
        $this->audit->record('analytics.assistant_settings_changed', $org, ['external_processing' => $old], ['external_processing' => $external]);

        return $this->settings();
    }

    private function externalAvailable(): bool
    {
        return config('analytics.assistant.provider') === 'anthropic' && filled(config('analytics.assistant.anthropic.api_key'));
    }

    /** @return array{0: string, 1: array, 2: ?string} planner name, plan, notice */
    private function plan(string $question, string $redacted, string $today): array
    {
        $consented = (bool) data_get($this->tenant->organization()->settings, 'analytics.assistant.external_processing', false);
        if ($consented && $this->externalAvailable()) {
            $external = app(ExternalPlanner::class);
            try {
                return [$external->name(), $external->plan($redacted, $today), null];
            } catch (Throwable $e) {
                Log::warning('assistant.external_planner_failed', ['error' => $e->getMessage()]);

                return [$this->rules->name(), $this->rules->plan($question, $today), 'The external model was unavailable; the question was answered with the built-in planner.'];
            }
        }

        return [$this->rules->name(), $this->rules->plan($question, $today), null];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function period(array $step, string $question, Carbon $today): array
    {
        $from = $this->date($step['from'] ?? null);
        $to = $this->date($step['to'] ?? null);
        if ($from === null || $to === null) {
            $parsed = PeriodParser::parse($question, $today);
            [$from, $to] = $parsed ? [$parsed[0], $parsed[1]] : [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()];
        }
        if ($to > $today->toDateString()) {
            $to = $today->toDateString();
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > config('analytics.max_period_days')) {
            $from = Carbon::parse($to)->subDays(config('analytics.max_period_days'))->toDateString();
        }

        return [$from, $to, $from === $to ? "on {$from}" : "from {$from} to {$to}"];
    }

    private function date(?string $value): ?string
    {
        return $value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : null;
    }
}
