<?php

namespace App\Services\Strategy;

use Illuminate\Support\Carbon;

/**
 * Compares a small set of trailing parameters on identical signals.
 *
 * The request was to optimise 5% / 2% / 3%. Reporting how well that
 * combination did, on its own, would answer a different and much easier
 * question: any parameter set looks reasonable when nothing is standing next
 * to it. What is worth knowing is whether it sits on a plateau -- neighbours
 * performing similarly, so the choice is robust -- or on a spike, where a
 * half-point either way collapses the result and the number is noise that has
 * been mistaken for an edge.
 *
 * Three deliberate limits:
 *
 *   The grid is five cells, not five hundred. Every additional cell is
 *   another chance to find a combination that fits this particular history,
 *   and a fitted parameter is worse than an arbitrary one because it comes
 *   with unearned confidence.
 *
 *   The signals are generated once and shared. Trailing parameters change how
 *   a fill is managed, never whether it happened, so every cell sees exactly
 *   the same trades. Two cells with different signal sets are not comparable
 *   at all.
 *
 *   The period is split chronologically, and the split is reported per cell.
 *   Choosing on the whole dataset and then presenting the whole dataset as
 *   validation is the standard way to publish an overfit result, so the
 *   in-sample, validation and out-of-sample columns are always shown together
 *   -- a cell that wins in-sample and collapses out-of-sample is telling you
 *   something, and it can only tell you if all three are on screen.
 *
 * Drawdown is measured on an equally weighted, sequential equity curve of the
 * net returns. It is not a portfolio simulation: it assumes one position at a
 * time and no compounding, which understates the drawdown of a concentrated
 * book and overstates that of a diversified one.
 */
class ParameterGridComparator
{
    public const SPLIT_IN_SAMPLE = 'in_sample';

    public const SPLIT_VALIDATION = 'validation';

    public const SPLIT_OUT_OF_SAMPLE = 'out_of_sample';

    public function __construct(
        private readonly SignalOutcomeEvaluator $evaluator,
        private readonly SignalOutcomeSimulator $simulator,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>|null  $grid
     * @param  array<int, string>|null  $symbols
     * @return array<string, mixed>
     */
    public function compare(
        Carbon $from,
        Carbon $to,
        StrategyProfile $base,
        ?array $grid = null,
        ?array $symbols = null,
        string $scoreVersion = StrategyScoringService::VERSION,
        float $inSampleFraction = 0.6,
        float $validationFraction = 0.2,
    ): array {
        $grid ??= (array) config('strategy_profile.parameter_grid', []);

        if ($grid === []) {
            $grid = [[
                'trail_activation_gain_pct' => $base->trailActivationGainPct,
                'trailing_distance_pct' => $base->trailingDistancePct,
                'minimum_locked_profit_pct' => $base->minimumLockedProfitPct,
            ]];
        }

        $report = [
            'signals' => 0,
            'evaluated' => 0,
            'persisted' => 0,
            'not_triggered' => 0,
            'no_plan' => 0,
            'missing_data' => 0,
            'resolved' => 0,
        ];

        // Materialised once. Every cell is scored on this exact list.
        $fills = [];

        foreach ($this->evaluator->fills($from, $to, $base, $symbols, $scoreVersion, $report) as $fill) {
            $fills[] = [
                'entry_date' => (string) $fill['entry_date'],
                'fill_price' => (float) $fill['fill_price'],
                'initial_stop' => $fill['initial_stop'],
                'forward_bars' => $fill['forward_bars'],
                'symbol' => (string) $fill['asset']->symbol,
            ];
        }

        $boundaries = $this->splitBoundaries($fills, $inSampleFraction, $validationFraction);

        $cells = [];

        foreach ($grid as $overrides) {
            $profile = $base->withOverrides($overrides);
            $costs = new TradingCostModel($profile);

            $bySplit = [
                self::SPLIT_IN_SAMPLE => [],
                self::SPLIT_VALIDATION => [],
                self::SPLIT_OUT_OF_SAMPLE => [],
            ];
            $all = [];

            foreach ($fills as $fill) {
                $outcome = $this->simulator->simulate(
                    $fill['fill_price'],
                    $fill['initial_stop'],
                    $fill['entry_date'],
                    $fill['forward_bars'],
                    $profile,
                    $costs,
                );

                if (! $outcome->resolved) {
                    continue;
                }

                $record = [
                    'entry_date' => $fill['entry_date'],
                    'net_return_pct' => $outcome->netReturnPct,
                    'gross_return_pct' => $outcome->grossReturnPct,
                    'hold_sessions' => $outcome->holdSessions,
                    'reached_5pct_before_stop' => $outcome->reachedFivePctBeforeStop,
                    'five_versus_stop_resolved' => $outcome->fiveVersusStopResolved(),
                ];

                $all[] = $record;
                $bySplit[$this->splitFor($fill['entry_date'], $boundaries)][] = $record;
            }

            $cells[] = [
                'parameters' => [
                    'trail_activation_gain_pct' => $profile->trailActivationGainPct,
                    'trailing_distance_pct' => $profile->trailingDistancePct,
                    'minimum_locked_profit_pct' => $profile->minimumLockedProfitPct,
                ],
                'version' => $profile->version,
                'all' => $this->metrics($all),
                'splits' => [
                    self::SPLIT_IN_SAMPLE => $this->metrics($bySplit[self::SPLIT_IN_SAMPLE]),
                    self::SPLIT_VALIDATION => $this->metrics($bySplit[self::SPLIT_VALIDATION]),
                    self::SPLIT_OUT_OF_SAMPLE => $this->metrics($bySplit[self::SPLIT_OUT_OF_SAMPLE]),
                ],
            ];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'base_version' => $base->version,
            'signal_flow' => $report,
            'trades_available' => count($fills),
            'split_boundaries' => $boundaries,
            'cells' => $cells,
            'disclaimer' => $base->disclaimer,
            'caveat' => 'Drawdown assumes one equally weighted position at a time and no compounding. Cells are compared on identical signals; only the management parameters differ.',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $trades
     * @return array<string, mixed>
     */
    private function metrics(array $trades): array
    {
        $count = count($trades);

        if ($count === 0) {
            return [
                'trades' => 0,
                'hit_rate_5pct' => null,
                'win_rate' => null,
                'expectancy_pct' => null,
                'median_return_pct' => null,
                'average_return_pct' => null,
                'max_drawdown_pct' => null,
                'profit_factor' => null,
                'median_hold_sessions' => null,
            ];
        }

        usort($trades, static fn (array $a, array $b): int => $a['entry_date'] <=> $b['entry_date']);

        $returns = [];
        $holds = [];
        $wins = 0;
        $grossWins = 0.0;
        $grossLosses = 0.0;
        $hits = 0;
        $decidable = 0;

        $equity = 0.0;
        $peak = 0.0;
        $maxDrawdown = 0.0;

        foreach ($trades as $trade) {
            $net = (float) ($trade['net_return_pct'] ?? 0.0);
            $returns[] = $net;
            $holds[] = (int) $trade['hold_sessions'];

            if ($net > 0) {
                $wins++;
                $grossWins += $net;
            } else {
                $grossLosses += abs($net);
            }

            if ($trade['five_versus_stop_resolved']) {
                $decidable++;

                if ($trade['reached_5pct_before_stop']) {
                    $hits++;
                }
            }

            $equity += $net;
            $peak = max($peak, $equity);
            $maxDrawdown = max($maxDrawdown, $peak - $equity);
        }

        return [
            'trades' => $count,
            'hit_rate_5pct' => $decidable > 0 ? round($hits / $decidable, 4) : null,
            'win_rate' => round($wins / $count, 4),
            'expectancy_pct' => round(array_sum($returns) / $count, 4),
            'median_return_pct' => $this->median($returns),
            'average_return_pct' => round(array_sum($returns) / $count, 4),
            'max_drawdown_pct' => round($maxDrawdown, 4),
            'profit_factor' => $grossLosses > 0.0 ? round($grossWins / $grossLosses, 4) : null,
            'median_hold_sessions' => $this->median($holds),
        ];
    }

    /**
     * Chronological split points, by trade count rather than by calendar.
     *
     * Splitting on dates would put wildly different numbers of trades in each
     * period whenever activity clusters, and a validation window holding four
     * trades validates nothing.
     *
     * @param  array<int, array<string, mixed>>  $fills
     * @return array{in_sample_end: ?string, validation_end: ?string}
     */
    private function splitBoundaries(array $fills, float $inSampleFraction, float $validationFraction): array
    {
        if ($fills === []) {
            return ['in_sample_end' => null, 'validation_end' => null];
        }

        $dates = array_map(static fn (array $fill): string => (string) $fill['entry_date'], $fills);
        sort($dates);

        $count = count($dates);
        $inSampleIndex = max(0, min($count - 1, (int) floor($count * $inSampleFraction) - 1));
        $validationIndex = max($inSampleIndex, min($count - 1, (int) floor($count * ($inSampleFraction + $validationFraction)) - 1));

        return [
            'in_sample_end' => $dates[$inSampleIndex],
            'validation_end' => $dates[$validationIndex],
        ];
    }

    /**
     * @param  array{in_sample_end: ?string, validation_end: ?string}  $boundaries
     */
    private function splitFor(string $entryDate, array $boundaries): string
    {
        if ($boundaries['in_sample_end'] === null) {
            return self::SPLIT_IN_SAMPLE;
        }

        if ($entryDate <= $boundaries['in_sample_end']) {
            return self::SPLIT_IN_SAMPLE;
        }

        if ($boundaries['validation_end'] !== null && $entryDate <= $boundaries['validation_end']) {
            return self::SPLIT_VALIDATION;
        }

        return self::SPLIT_OUT_OF_SAMPLE;
    }

    /**
     * @param  array<int, int|float>  $values
     */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return round(
            $count % 2 === 1
                ? (float) $values[$middle]
                : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2.0,
            4,
        );
    }
}
