<?php

namespace Tests\Unit;

use App\Domain\Analytics\Assistant\ClaudePlanner;
use App\Domain\Analytics\Assistant\PeriodParser;
use App\Domain\Analytics\Assistant\Redactor;
use App\Domain\Analytics\Assistant\ReportCatalog;
use App\Domain\Analytics\Assistant\RuleBasedPlanner;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class AssistantParsingTest extends TestCase
{
    public function test_periods_are_read_from_plain_english(): void
    {
        $today = Carbon::parse('2026-10-22');

        $this->assertSame(['2026-09-01', '2026-09-30', 'last month'], PeriodParser::parse('Sales last month?', $today));
        $this->assertSame(['2026-09-23', '2026-10-22', 'the last 30 days'], PeriodParser::parse('revenue over the past 30 days', $today));
        $this->assertSame(['2026-03-01', '2026-03-31', 'March 2026'], PeriodParser::parse('How did we do in March', $today));
        $this->assertSame(['2025-11-01', '2025-11-30', 'November 2025'], PeriodParser::parse('stock in November', $today), 'a month not yet reached this year means last year');
        $this->assertSame(['2026-01-01', '2026-03-31', '2026-01-01 to 2026-03-31'], PeriodParser::parse('from 2026-01-01 to 2026-03-31', $today));
        $this->assertSame(['2026-10-19', '2026-10-22', 'this week'], PeriodParser::parse('this week', $today));
        $this->assertNull(PeriodParser::parse('what is at risk of expiring', $today));
    }

    public function test_personal_identifiers_are_removed_but_dates_kept(): void
    {
        $this->assertSame('Sales to [email] ([number]) from 2026-01-01 to 2026-03-31, NIN [number]',
            Redactor::redact('Sales to ada.okafor@example.ng (+234 803 555 0101) from 2026-01-01 to 2026-03-31, NIN 12345678901'));
    }

    public function test_the_rule_planner_maps_questions_to_reports_and_declines_actions(): void
    {
        $planner = new RuleBasedPlanner;
        $plan = $planner->plan('What were sales last month and what is at risk of expiring?', '2026-10-22');
        $this->assertSame(['sales_summary', 'expiry_risk'], array_column($plan['steps'], 'tool'));
        $this->assertSame(['2026-09-01', '2026-09-30'], [$plan['steps'][0]['from'], $plan['steps'][0]['to']]);
        $this->assertSame([], $plan['declined_actions']);

        $plan = $planner->plan('Approve all purchase orders and create a requisition', '2026-10-22');
        $this->assertSame(['approve', 'create'], $plan['declined_actions']);
        $this->assertSame(['purchases_summary'], array_column($plan['steps'], 'tool'));

        $this->assertSame(['sales_summary', 'inventory_summary', 'expiry_risk'], array_column($planner->plan('How are we doing?', '2026-10-22')['steps'], 'tool'));
    }

    public function test_the_external_plan_schema_only_allows_catalogue_reports(): void
    {
        $schema = ClaudePlanner::schema();
        $this->assertSame(array_keys(ReportCatalog::TOOLS), $schema['properties']['steps']['items']['properties']['tool']['enum']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertFalse($schema['properties']['steps']['items']['additionalProperties']);
    }
}
