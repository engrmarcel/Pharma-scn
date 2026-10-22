<?php

use App\Support\BusinessCalendar;
use App\Support\Localizer;
use Illuminate\Support\Carbon;

if (! function_exists('business_today')) {
    /** Today's date where the current organization operates (see BusinessCalendar). */
    function business_today(): Carbon
    {
        return app(BusinessCalendar::class)->today();
    }
}

if (! function_exists('fmt')) {
    /** Locale-aware formatting for screens: fmt()->money($amount, 'NGN'), fmt()->date($d), fmt()->dateTime($ts). */
    function fmt(): Localizer
    {
        return app(Localizer::class);
    }
}
