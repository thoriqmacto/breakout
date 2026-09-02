<?php

/*
|--------------------------------------------------------------------------
| Execution strategy profile
|--------------------------------------------------------------------------
|
| Every tunable number the execution strategy uses, in one auditable place.
| Services read a StrategyProfile built from this file rather than calling
| config() at the point of use, so a simulation can be run against a modified
| profile without touching global state -- and so the profile version can be
| persisted alongside every signal outcome it produced.
|
| None of these defaults is a validated edge. They are starting points chosen
| to match what the interface already treated as meaningful, and the parameter
| grid exists precisely so they can be argued with. A profile that has not
| been compared against its neighbours is a guess with a version number.
|
| The lifecycle these parameters describe:
|
|   BROKER ACCUMULATION -> SETUP -> TRIGGER -> POSITION
|     -> +5% PROFIT ACTIVATION -> 2% TRAILING STOP -> EXIT
|
| The +5% level is an activation threshold for trailing, never a target and
| never a promise. What the system can say about it is empirical: how often
| comparable historical setups reached it before their initial stop.
|
*/

return [

    /*
    | Identifies the parameter set. Persisted on every signal outcome and
    | every execution candidate so a stored result can always be traced to
    | the rules that produced it. Bump it whenever a default below changes
    | in a way that would alter historical output.
    */
    'version' => env('STRATEGY_PROFILE_VERSION', 'execution-v2'),

    /*
    | Broker accumulation windows, shortest first. Each is read for a
    | different question rather than averaged into one number:
    |
    |   3D   short-term acceleration
    |   5D   near-term confirmation
    |   10D  primary accumulation regime
    |   20D  background accumulation regime
    |
    | A candidate with persistent 5/10/20 flow is a different animal from one
    | with a single hot 3D spike, and collapsing them hides exactly that.
    */
    'broker_windows' => [3, 5, 10, 20],

    /*
    | Which windows carry the regime classification. The short window is
    | deliberately excluded: one strong session must not be able to declare an
    | accumulation regime on its own.
    */
    'broker_regime_windows' => [5, 10, 20],

    /*
    | Net-flow magnitude, normalised against window turnover, at or above
    | which a window counts as meaningfully positive rather than noise.
    */
    'broker_flow_epsilon' => (float) env('STRATEGY_BROKER_FLOW_EPSILON', 0.005),

    /*
    | top3_net_norm at or above which the buying is concentrated enough in a
    | few hands to call the accumulation deliberate rather than incidental.
    */
    'broker_strong_top3_norm' => (float) env('STRATEGY_BROKER_STRONG_TOP3', 0.05),

    /*
    | Profit lifecycle. Before activation the position is managed by its
    | structural stop; after it, by the trailing rule.
    */
    'trail_activation_gain_pct' => (float) env('STRATEGY_TRAIL_ACTIVATION_PCT', 5.0),
    'trailing_distance_pct' => (float) env('STRATEGY_TRAILING_DISTANCE_PCT', 2.0),
    'minimum_locked_profit_pct' => (float) env('STRATEGY_MIN_LOCKED_PROFIT_PCT', 3.0),

    /*
    | Entry. The plan is a zone above the trigger, not a single price: fills
    | happen where the book allows. Beyond the zone the trade is NO_CHASE --
    | the setup may be fine and the entry still bad.
    */
    'max_entry_extension_atr' => (float) env('STRATEGY_MAX_ENTRY_EXTENSION_ATR', 0.5),

    /*
    | Distance to the breakout level, in ATR, within which a setup is ARMED
    | rather than merely WATCH.
    */
    'armed_distance_atr' => (float) env('STRATEGY_ARMED_DISTANCE_ATR', 1.0),

    /*
    | Initial risk ceiling as a percentage of the entry. A setup needing more
    | room than this is rejected rather than sized down: the stop is where the
    | idea is wrong, and if that is 8% away the idea is too vague to trade.
    */
    'max_initial_risk_pct' => (float) env('STRATEGY_MAX_INITIAL_RISK_PCT', 4.0),

    /*
    | ATR multiple below the breakout level for the volatility stop. The
    | structural stop is the recent swing low; the tighter of the two that is
    | still below the trigger wins.
    */
    'initial_stop_atr_multiple' => (float) env('STRATEGY_INITIAL_STOP_ATR', 1.0),

    /*
    | Breakout confirmation. Not hard gates -- they classify and score. A
    | setup failing one of these is weaker, not disqualified.
    */
    'min_volume_ratio' => (float) env('STRATEGY_MIN_VOLUME_RATIO', 1.3),
    'preferred_volume_ratio' => (float) env('STRATEGY_PREFERRED_VOLUME_RATIO', 1.5),
    'min_close_position' => (float) env('STRATEGY_MIN_CLOSE_POSITION', 0.70),

    /*
    | Execution score v2 weights. They sum to 1.0 and every component is
    | reported alongside its contribution, so a score can always be taken
    | apart into the reasons that made it.
    |
    | Data freshness is deliberately absent. It is a gate, not a component:
    | stale inputs stop a candidate being actionable at all, and also scoring
    | them would let a very strong setup buy its way past staleness with
    | points earned elsewhere.
    */
    'score_weights' => [
        'broker_persistence' => 0.15,
        'broker_strength' => 0.10,
        'broker_acceleration' => 0.05,
        'breakout_confirmation' => 0.20,
        'volume_confirmation' => 0.10,
        'trend_quality' => 0.10,
        'liquidity' => 0.05,
        'risk_quality' => 0.10,
        'historical_outcome' => 0.15,
    ],

    /*
    | Liquidity floor, reused from the watchlist ranker's existing threshold
    | so the two surfaces cannot disagree about what is tradable.
    */
    'min_turnover_value' => (float) env('STRATEGY_MIN_TURNOVER', 5_000_000_000.0),
    'min_active_brokers' => (int) env('STRATEGY_MIN_ACTIVE_BROKERS', 5),

    /*
    | Historical outcome statistics are withheld below this many comparable
    | samples. A hit rate over eleven trades is not a probability, and
    | rendering it to one decimal place makes it look like one.
    */
    'minimum_probability_sample' => (int) env('STRATEGY_MIN_PROBABILITY_SAMPLE', 30),

    /*
    | Forward horizons, in sessions, over which MFE/MAE are measured.
    */
    'outcome_horizons' => [1, 3, 5, 10, 20],

    /*
    | Sessions a simulated trade may stay open before it is closed at the
    | market. Without a cap a position that neither stops out nor activates
    | trailing runs to the end of the data and distorts hold-period medians.
    */
    'max_holding_sessions' => (int) env('STRATEGY_MAX_HOLDING_SESSIONS', 40),

    /*
    | Freshness. Execution candidates want broker data describing the latest
    | completed session; analysis tolerates more lag. The two are separate
    | because they answer different questions.
    */
    'max_broker_lag_days_execution' => (int) env('STRATEGY_MAX_BROKER_LAG_EXECUTION', 1),

    /*
    | Trading costs. Applied to every simulated trade so a reported return is
    | a return and not a price difference.
    */
    'costs' => [
        'buy_fee_pct' => (float) env('STRATEGY_BUY_FEE_PCT', 0.15),
        'sell_fee_pct' => (float) env('STRATEGY_SELL_FEE_PCT', 0.25),
        'slippage_pct' => (float) env('STRATEGY_SLIPPAGE_PCT', 0.10),
        'round_to_tick' => (bool) env('STRATEGY_ROUND_TO_TICK', true),
    ],

    /*
    | Daily bars do not reveal intraday sequence. When one session's range
    | contains both the trailing high and the stop, the data cannot say which
    | came first. "conservative" resolves the ambiguity against the trade.
    | "optimistic" exists only so the cost of the assumption can be measured.
    */
    'intraday_assumption' => env('STRATEGY_INTRADAY_ASSUMPTION', 'conservative'),

    /*
    | The parameter grid. Deliberately small: five activation/trailing pairs
    | is enough to show whether the requested 5/2/3 is a plateau or a spike,
    | and not enough to fit the noise. Every entry is compared on the same
    | signals over the same split.
    */
    'parameter_grid' => [
        ['trail_activation_gain_pct' => 4.0, 'trailing_distance_pct' => 2.0, 'minimum_locked_profit_pct' => 2.0],
        ['trail_activation_gain_pct' => 5.0, 'trailing_distance_pct' => 1.5, 'minimum_locked_profit_pct' => 3.0],
        ['trail_activation_gain_pct' => 5.0, 'trailing_distance_pct' => 2.0, 'minimum_locked_profit_pct' => 3.0],
        ['trail_activation_gain_pct' => 5.0, 'trailing_distance_pct' => 2.5, 'minimum_locked_profit_pct' => 3.0],
        ['trail_activation_gain_pct' => 6.0, 'trailing_distance_pct' => 2.0, 'minimum_locked_profit_pct' => 3.5],
    ],

    /*
    | Shown wherever an outcome statistic is. The distinction it draws is the
    | one the whole engine rests on.
    */
    'disclaimer' => 'Research and decision support only. P(+5% before stop) is an empirical frequency measured over past setups that resembled this one; it is not a prediction, a guarantee, or advice. The +5% level activates a trailing stop and is not a profit target.',
];
