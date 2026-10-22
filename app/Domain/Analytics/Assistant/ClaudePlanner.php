<?php

namespace App\Domain\Analytics\Assistant;

use Anthropic\Client;
use RuntimeException;

/**
 * Plans a question with Claude through the official Anthropic SDK, using structured output so
 * the plan is a JSON object restricted to the report catalogue.
 *
 * What is sent: the redacted question, today's date and the catalogue descriptions. What is
 * never sent: report data, product lists, customer or patient records. The plan is validated
 * again by AssistantService; on a refusal or any error the built-in planner answers instead.
 */
class ClaudePlanner implements ExternalPlanner
{
    public function __construct(private readonly ?Client $client = null) {}

    public function name(): string
    {
        return 'anthropic:'.config('analytics.assistant.anthropic.model');
    }

    public function plan(string $question, string $today): array
    {
        $client = $this->client ?? new Client(apiKey: config('analytics.assistant.anthropic.api_key'));
        $catalogue = collect(ReportCatalog::TOOLS)->map(fn ($d, $t) => "- {$t}: {$d}")->implode("\n");

        $message = $client->messages->create(
            model: config('analytics.assistant.anthropic.model'),
            maxTokens: 8000,
            system: <<<SYS
                You plan read-only business reports for a pharmaceutical distribution and pharmacy platform.
                Choose the reports from this catalogue that answer the user's question (at most five):
                {$catalogue}

                For each report give the period as ISO dates (from, to), or null for both to use the
                current month to date. Today is {$today}. For demand_forecast, put the product name or
                SKU the user mentioned in product_query; otherwise product_query is null.
                The reports cannot change anything. If the question asks to approve, create, order, pay,
                adjust, cancel or otherwise change something, list that request in declined_actions and
                still choose the reports that inform it.
                SYS,
            messages: [['role' => 'user', 'content' => $question]],
            outputConfig: ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => self::schema()]],
            requestOptions: ['timeout' => (float) config('analytics.assistant.anthropic.timeout_seconds'), 'maxRetries' => 2],
        );

        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('The model declined to plan this question.');
        }
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $plan = json_decode($block->text, true);
                if (is_array($plan) && isset($plan['steps']) && is_array($plan['steps'])) {
                    return ['steps' => array_values(array_filter($plan['steps'], 'is_array')),
                        'declined_actions' => array_values(array_filter($plan['declined_actions'] ?? [], 'is_string'))];
                }
            }
        }

        throw new RuntimeException("No plan in the model's response (stop reason {$message->stopReason}).");
    }

    public static function schema(): array
    {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'properties' => [
                'steps' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['tool' => ['type' => 'string', 'enum' => array_keys(ReportCatalog::TOOLS)], 'from' => $nullableString, 'to' => $nullableString, 'product_query' => $nullableString],
                    'required' => ['tool', 'from', 'to', 'product_query'],
                    'additionalProperties' => false,
                ]],
                'declined_actions' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['steps', 'declined_actions'],
            'additionalProperties' => false,
        ];
    }
}
