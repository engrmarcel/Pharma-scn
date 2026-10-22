<?php

namespace App\Support;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Local business dates (spec §20 time zone, localized dates).
 *
 * Timestamps stay in UTC everywhere. Business dates (document dates, "today" for expiry,
 * due dates, report periods) are the calendar date where the organization operates: the
 * organization's own time zone setting, else its country's, else the application's.
 *
 * today() returns that local date as a date value (midnight, application time zone), so
 * date arithmetic and date-column comparisons keep working unchanged. dayStart()/dayEnd()
 * give the UTC instants that bound a local day, for filtering timestamp columns.
 *
 * BUSINESS_TIMEZONE forces one zone (the test suite uses UTC for determinism).
 */
class BusinessCalendar
{
    /** @var array<string, string> */
    private array $zones = [];

    public function __construct(private readonly TenantContext $tenant) {}

    public function timezone(): string
    {
        if ($forced = config('platform.business_timezone')) {
            return $forced;
        }
        $org = $this->tenant->organization();
        if ($org === null) {
            return config('app.timezone');
        }

        // Keyed by organization and its last change, so a new time zone setting applies at once.
        return $this->zones[$org->id.'|'.$org->updated_at] ??= data_get($org->settings, 'locale.timezone') ?? $org->country?->timezone ?? config('app.timezone');
    }

    public function today(): Carbon
    {
        return Carbon::parse(now($this->timezone())->toDateString(), config('app.timezone'));
    }

    /** First UTC instant of a local business day. */
    public function dayStart(Carbon|string $date): Carbon
    {
        return Carbon::parse(self::dateString($date), $this->timezone())->startOfDay()->utc();
    }

    /** Last UTC instant of a local business day. */
    public function dayEnd(Carbon|string $date): Carbon
    {
        return Carbon::parse(self::dateString($date), $this->timezone())->endOfDay()->utc();
    }

    /** A UTC timestamp shown in local time. */
    public function local(Carbon|string|null $timestamp): ?Carbon
    {
        return $timestamp === null ? null : Carbon::parse($timestamp, 'UTC')->setTimezone($this->timezone());
    }

    private static function dateString(Carbon|string $date): string
    {
        return $date instanceof Carbon ? $date->toDateString() : Carbon::parse($date)->toDateString();
    }
}
