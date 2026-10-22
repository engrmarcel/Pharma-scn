<?php

namespace App\Domain\Analytics\Assistant;

/**
 * Turns a question into a plan of read-only reports. A planner never runs anything;
 * AssistantService validates the plan against ReportCatalog and executes it.
 */
interface QuestionPlanner
{
    /**
     * @return array{steps: list<array{tool: string, from: ?string, to: ?string, product_query: ?string}>, declined_actions: list<string>}
     */
    public function plan(string $question, string $today): array;

    /** Recorded with every answer, e.g. "rules-v1" or "anthropic:claude-opus-5-5". */
    public function name(): string;
}
