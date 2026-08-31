<?php

/*
|--------------------------------------------------------------------------
| Execution workspace
|--------------------------------------------------------------------------
|
| Thresholds for the next-session execution view. Every one of these is a
| starting point chosen because it matches what the interface already treated
| as meaningful, not a statistically validated edge. They are configuration
| precisely so a backtest can be run against different values and the answer
| can change them.
|
| Nothing here predicts anything. A candidate is a setup that met a set of
| stated rules on the last completed session; the rules are the product.
|
*/

return [

    /*
    | Score at or above which a candidate may be labelled READY. 75 is where
    | the existing watchlist UI already drew its "strong" band, so adopting it
    | changes nothing about what the user sees today -- it just gives the
    | number a name and somewhere to be edited.
    */
    'min_score' => (float) env('EXECUTION_MIN_SCORE', 75.0),

    /*
    | Minimum risk/reward, measured at the planned entry trigger rather than
    | at the signal close. A setup that clears 2.0 from Friday's close and
    | 1.4 from the price you would actually pay is not a 2.0 setup.
    */
    'min_rr' => (float) env('EXECUTION_MIN_RR', 2.0),

    /*
    | How far the next session's open may gap beyond the planned trigger
    | before the entry is called off, as a fraction of the trigger. Null
    | disables the guard, which is the default: no value has been validated
    | here, and inventing one would dress a guess as a finding. The R/R
    | recalculation is the real protection -- a gap that ruins the reward
    | fails `min_rr` on its own.
    */
    'max_entry_gap_pct' => env('EXECUTION_MAX_ENTRY_GAP_PCT') === null
        ? null
        : (float) env('EXECUTION_MAX_ENTRY_GAP_PCT'),

    /*
    | Data freshness. A candidate whose signal is not the latest completed
    | session is STALE rather than READY: an execution plan is only as
    | meaningful as the session behind it.
    */
    'freshness' => [
        // Sessions the broker window may trail the signal by and still be
        // treated as describing it. Broker summaries are collected the same
        // evening as the bars, so more than a couple of days means the import
        // has been failing.
        'max_broker_lag_days' => (int) env('EXECUTION_MAX_BROKER_LAG_DAYS', 5),
    ],

    /*
    | Rows returned by default. Everything evaluated is persisted; this only
    | bounds the page.
    */
    'default_limit' => (int) env('EXECUTION_DEFAULT_LIMIT', 50),

    /*
    | Shown wherever candidates are. Not decoration: the whole surface is
    | decision support, and saying so is part of the product.
    */
    'disclaimer' => 'Research and decision support only. These are rule-based observations about the last completed session, not advice, predictions, or an instruction to trade.',
];
