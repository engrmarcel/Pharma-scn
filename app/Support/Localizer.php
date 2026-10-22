<?php

namespace App\Support;

use App\Domain\Platform\Support\CountryProfile;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use IntlDateFormatter;
use NumberFormatter;

/**
 * Formats values for people (spec §20 localized dates and numbers): money in the currency's
 * decimals with the locale's separators and symbol placement, dates in the country's format,
 * and timestamps in the organization's local time. APIs keep returning raw values (decimal
 * strings, ISO dates); this is for receipts, pages and exports.
 *
 * Locale: the organization's setting (locale.locale), else its country's default locale.
 */
class Localizer
{
    public function __construct(private readonly TenantContext $tenant, private readonly BusinessCalendar $calendar) {}

    public function locale(): string
    {
        $org = $this->tenant->organization();

        return data_get($org?->settings, 'locale.locale') ?? $org?->country?->default_locale ?? config('app.locale');
    }

    public function money(string|float|int|null $amount, string $currency): string
    {
        $f = new NumberFormatter($this->locale(), NumberFormatter::CURRENCY);
        $digits = Money::minorUnits($currency);
        $f->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $digits);
        $f->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $digits);

        return (string) $f->formatCurrency((float) ($amount ?? 0), $currency);
    }

    public function number(string|float|int|null $value, int $decimals = 0): string
    {
        $f = new NumberFormatter($this->locale(), NumberFormatter::DECIMAL);
        $f->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 0);
        $f->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

        return (string) $f->format((float) ($value ?? 0));
    }

    /** A business date in the country's configured format, else the locale's medium format. */
    public function date(Carbon|string|null $date): string
    {
        if ($date === null) {
            return '';
        }
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);
        $format = ($country = $this->tenant->organization()?->country) ? CountryProfile::of($country)->get('formats.date') : null;

        return $format ? $date->format($format)
            : (string) (new IntlDateFormatter($this->locale(), IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC'))->format($date->copy()->utc()->startOfDay());
    }

    /** A UTC timestamp shown as local date and time. */
    public function dateTime(Carbon|string|null $timestamp): string
    {
        $local = $this->calendar->local($timestamp);

        return $local === null ? '' : $this->date($local->toDateString()).' '.$local->format('H:i');
    }
}
