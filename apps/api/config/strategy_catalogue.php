<?php

use App\Services\Strategies\BreakoutAtr;
use App\Services\Strategies\DonchianBreakout;
use App\Services\Strategies\HLSLBreakoutStrategy;
use App\Services\Strategies\MovingAverageCrossover;
use App\Services\Strategies\RocMomentum;
use App\Services\Strategies\RsiReversal;
use App\Services\Strategies\SupportResistanceBreakout;

return [

    /*
    |--------------------------------------------------------------------------
    | Built-in strategies
    |--------------------------------------------------------------------------
    |
    | The strategies that ship as code rather than as rows a person edits.
    | Until this file existed the list lived inline in AssetBacktest::handle(),
    | which meant the only way to answer "how many strategies are there?" was
    | to read a command -- and the dashboard, having no way to ask, printed the
    | number 6 as a string literal. It happened to be right. It would have gone
    | on being printed after it stopped being right.
    |
    | So this is the one list, and everything reads it: the backtest command
    | resolves --strategy through it, the API serves it, and the Strategies
    | page renders it beside the editable ones.
    |
    | Read-only by design. These are algorithms with constructor parameters and
    | a signal() method, not the rules JSON that StrategyRunner executes. Their
    | defaults are recorded here so the page can show what a run actually uses,
    | but changing one means changing the class, and presenting them as
    | editable would be a label over code that ignores it.
    |
    | `key` is the value --strategy takes. It is part of the CLI contract, so
    | it is not renamed for tidiness.
    |
    */

    'built_in' => [
        [
            'key' => 'DonchBO',
            'name' => 'Donchian breakout',
            'class' => DonchianBreakout::class,
            'summary' => 'Buys a close above the highest high of the lookback window, and exits below the matching low.',
            'parameters' => [
                ['name' => 'period', 'default' => 20, 'unit' => 'sessions'],
            ],
            'supports_trailing_stop' => true,
            'backtester' => 'DonchianBacktester',
        ],
        [
            'key' => 'AtrBO',
            'name' => 'ATR breakout',
            'class' => BreakoutAtr::class,
            'summary' => 'Buys a close that clears the prior range by a multiple of ATR, so the trigger widens with volatility instead of using a fixed distance.',
            'parameters' => [
                ['name' => 'multiplier', 'default' => 1.0, 'unit' => 'ATR'],
                ['name' => 'period', 'default' => 14, 'unit' => 'sessions'],
            ],
            'supports_trailing_stop' => true,
            'backtester' => 'GenericBacktester',
        ],
        [
            'key' => 'RocMomentum',
            'name' => 'Rate-of-change momentum',
            'class' => RocMomentum::class,
            'summary' => 'Buys when the rate of change over the lookback exceeds a threshold.',
            'parameters' => [
                ['name' => 'lookback', 'default' => 13, 'unit' => 'sessions'],
                ['name' => 'threshold', 'default' => 5.0, 'unit' => '%'],
            ],
            'supports_trailing_stop' => true,
            'backtester' => 'RocMomentumBacktester',
        ],
        [
            'key' => 'MACross',
            'name' => 'Moving average crossover',
            'class' => MovingAverageCrossover::class,
            'summary' => 'Buys when the short moving average crosses above the long one, and exits on the opposite cross.',
            'parameters' => [
                ['name' => 'short_period', 'default' => 50, 'unit' => 'sessions'],
                ['name' => 'long_period', 'default' => 200, 'unit' => 'sessions'],
            ],
            'supports_trailing_stop' => true,
            'backtester' => 'GenericBacktester',
        ],
        [
            'key' => 'RsiReversal',
            'name' => 'RSI reversal',
            'class' => RsiReversal::class,
            'summary' => 'Buys out of oversold and sells out of overbought. Mean-reverting, and the only built-in that is not trend-following.',
            'parameters' => [
                ['name' => 'period', 'default' => 14, 'unit' => 'sessions'],
                ['name' => 'overbought', 'default' => 70.0, 'unit' => 'RSI'],
                ['name' => 'oversold', 'default' => 30.0, 'unit' => 'RSI'],
            ],
            'supports_trailing_stop' => true,
            'backtester' => 'GenericBacktester',
        ],
        [
            'key' => 'SR_BO',
            'name' => 'Support/resistance breakout',
            'class' => SupportResistanceBreakout::class,
            'summary' => 'Buys a break of the highest high over a window measured in weeks rather than sessions.',
            'parameters' => [
                ['name' => 'weeks', 'default' => 55, 'unit' => 'weeks'],
            ],
            'supports_trailing_stop' => true,
            'backtester' => 'GenericBacktester',
        ],

        /*
        | Listed with the rest because it is a strategy an operator can run,
        | and leaving it out is how the count goes wrong again -- but it is
        | genuinely different from the six above, and the page says so rather
        | than flattening the distinction. Its signal() returns 'hold': it
        | annotates weekly swing pivots for the forecast, and its backtest runs
        | through a dedicated service instead of GenericBacktester, which is
        | why --compare refuses it.
        */
        [
            'key' => 'HLSLBreakout',
            'name' => 'HL/SL breakout',
            'class' => HLSLBreakoutStrategy::class,
            'summary' => 'Annotates weekly swing highs and lows from N=2 pivots and backtests breaks of them. Does not emit a daily signal, and cannot be compared against the others in one run.',
            'parameters' => [
                ['name' => 'pivot_length', 'default' => 2, 'unit' => 'bars'],
            ],
            'supports_trailing_stop' => false,
            'backtester' => 'HLSLBreakoutBacktester',
            'emits_daily_signal' => false,
            'aliases' => ['HLSL'],
        ],
    ],

];
