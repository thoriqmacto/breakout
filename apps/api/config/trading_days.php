<?php

/*
|--------------------------------------------------------------------------
| Trading-day import
|--------------------------------------------------------------------------
|
| The IHSG session calendar and its closing values, imported from Yahoo via
| the Python bridge.
|
*/

return [

    /*
    | Calendar days of extra history requested from the provider before the
    | start of the range being persisted.
    |
    | The provider and the ledger are asked different questions. We persist a
    | logical range -- "the sessions between these two dates" -- but Yahoo is
    | asked for a download window, and a window that begins exactly on the
    | session you need has been observed to come back without it. That is how
    | a repair silently does nothing: the row stays NULL because the date was
    | never in the response to begin with.
    |
    | Seven calendar days clears a weekend plus an ordinary market holiday.
    | Records outside the requested range are still discarded before any
    | write, so widening this changes what is fetched and never what is
    | stored.
    */
    'fetch_buffer_days' => (int) env('TRADING_DAYS_FETCH_BUFFER_DAYS', 7),

    /*
    | Longest list of dates any command prints before falling back to a count.
    | A month of missing closes is a number, not a wall of text.
    */
    'max_reported_dates' => (int) env('TRADING_DAYS_MAX_REPORTED_DATES', 20),

];
