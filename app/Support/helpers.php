<?php

use App\Support\BusinessCalendar;
use Illuminate\Support\Carbon;

if (! function_exists('business_today')) {
    /** Today's date where the current organization operates (see BusinessCalendar). */
    function business_today(): Carbon
    {
        return app(BusinessCalendar::class)->today();
    }
}
