<?php

namespace App\Services\Backtest;

use App\Services\AssetMetrics;
use App\Services\Strategies\BaseStrategy;
use App\Services\Strategies\HLSLBreakoutStrategy;
use Carbon\Carbon;
use RuntimeException;

class HLSLBreakoutBacktester extends BaseBacktester
{
    public function __construct(private readonly HLSLBreakoutStrategy $strategy)
    {
    }

    /**
     * Prepare and simulate trades for a single ticker.
     *
     * @param string $ticker
     * @param array<int, array<string, mixed>> $rows
     * @return array{
     *     trades: array<int, array<string, mixed>>,
     *     start: Carbon|null,
     *     end: Carbon|null,
     *     signals: array<int, array<string, mixed>>
     * }
     */
    public function processTicker(string $ticker, array $rows): array
    {
        $analysis = $this->strategy->analyze($rows);

        $dailyData = $analysis['daily'];
        if ($dailyData === []) {
            return [
                'trades' => [],
                'start' => null,
                'end' => null,
                'signals' => [],
            ];
        }

        $start = clone $dailyData[0]['date'];
        $end = clone $dailyData[array_key_last($dailyData)]['date'];

        $weeklyData = $analysis['weekly'];
        $signals = $analysis['signals'];

        if ($weeklyData === [] || $signals === []) {
            return [
                'trades' => [],
                'start' => $start,
                'end' => $end,
                'signals' => $signals,
            ];
        }

        $trades = $this->simulateTrades($ticker, $dailyData, $weeklyData, $signals);

        return [
            'trades' => $trades,
            'start' => $start,
            'end' => $end,
            'signals' => $signals,
        ];
    }

    protected function createStrategy(AssetMetrics $metrics): BaseStrategy
    {
        throw new RuntimeException('HLSLBreakoutBacktester does not support incremental BaseBacktester::run usage.');
    }

    /**
     * Compile statistics for the executed trades.
     *
     * @param array<int, array<string, mixed>> $trades
     * @param array<int, array{date:string, equity:float}> $equityCurve
     */
    public function compileStats(array $trades, array $equityCurve, float $initialCapital, float $finalCapital): array
    {
        $tradeCount = count($trades);
        $wins = 0;
        $losses = 0;
        $winReturns = [];
        $lossReturns = [];
        $grossProfit = 0.0;
        $grossLoss = 0.0;

        foreach ($trades as $trade) {
            $pct = $trade['return_pct'] ?? 0.0;
            $profit = $trade['profit'] ?? 0.0;
            if ($pct > 0.0) {
                $wins++;
                $winReturns[] = $pct;
                $grossProfit += $profit;
            } elseif ($pct < 0.0) {
                $losses++;
                $lossReturns[] = $pct;
                $grossLoss += $profit;
            }
        }

        $winRate = $tradeCount > 0 ? ($wins / $tradeCount) * 100.0 : 0.0;
        $avgWin = $winReturns !== [] ? array_sum($winReturns) / count($winReturns) : 0.0;
        $avgLoss = $lossReturns !== [] ? array_sum($lossReturns) / count($lossReturns) : 0.0;
        $profitFactor = $grossLoss < 0 ? $grossProfit / abs($grossLoss) : ($grossProfit > 0 ? INF : 0.0);

        $curveBars = $this->equityCurveToBars($equityCurve);
        $tradeSummaries = array_map(function (array $trade) {
            return [
                'entry_date' => $trade['entry_date']?->toDateString() ?? '',
                'exit_date' => $trade['exit_date']?->toDateString() ?? '',
                'entry_price' => $trade['entry_price'],
                'exit_price' => $trade['exit_price'],
                'shares' => $trade['shares'] ?? 0,
                'pnl' => $trade['profit'] ?? 0.0,
            ];
        }, $trades);

        $metrics = ($curveBars !== [] && $tradeSummaries !== [])
            ? $this->calculateMetrics($curveBars, $equityCurve, $tradeSummaries, $initialCapital, $finalCapital)
            : ['cagr' => 0.0, 'maxdd' => 0.0, 'sharpe' => 0.0, 'winrate' => 0.0, 'profit_factor' => 0.0, 'trades' => $tradeCount];

        $totalReturnPct = $initialCapital > 0.0
            ? (($finalCapital - $initialCapital) / $initialCapital) * 100.0
            : 0.0;

        return [
            'initial_capital' => round($initialCapital, 2),
            'final_equity' => round($finalCapital, 2),
            'total_return_pct' => round($totalReturnPct, 2),
            'CAGR_pct' => round($metrics['cagr'] * 100, 2),
            'max_drawdown_pct' => round($metrics['maxdd'] * 100, 2),
            'num_trades' => $tradeCount,
            'win_rate_pct' => round($winRate, 2),
            'avg_win_pct' => round($avgWin, 2),
            'avg_loss_pct' => round($avgLoss, 2),
            'profit_factor' => is_infinite($profitFactor) ? null : round($profitFactor, 2),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $dailyData
     * @param array<int, array<string, mixed>> $weeklyData
     * @param array<int, array<string, mixed>> $signals
     * @return array<int, array<string, mixed>>
     */
    private function simulateTrades(string $ticker, array $dailyData, array $weeklyData, array $signals): array
    {
        $trades = [];
        $lastSwingLowMap = $this->strategy->buildWeeklySwingLowMap($weeklyData);
        $lastExitIndex = -1;

        foreach ($signals as $signal) {
            $week = $weeklyData[$signal['week_index']];
            $lastDayIndex = $week['daily_indices'] !== [] ? max($week['daily_indices']) : null;
            if ($lastDayIndex === null) {
                continue;
            }

            $entryIndex = $lastDayIndex + 1;
            if (! isset($dailyData[$entryIndex])) {
                continue;
            }

            if ($entryIndex <= $lastExitIndex) {
                continue;
            }

            $entryDay = $dailyData[$entryIndex];
            $entryPrice = $entryDay['open'];
            $entryDate = clone $entryDay['date'];

            $highestHigh = $entryDay['high'];
            $exit = null;
            $exitIndex = $entryIndex;

            for ($i = $entryIndex; $i < count($dailyData); $i++) {
                $day = $dailyData[$i];
                $highestHigh = max($highestHigh, $day['high']);
                $trailingStop = $highestHigh * 0.98;

                $weekKey = $day['date']->copy()->endOfWeek(Carbon::FRIDAY)->toDateString();
                $lastSwingLow = $lastSwingLowMap[$weekKey] ?? null;

                $threshold = $lastSwingLow !== null ? min($trailingStop, $lastSwingLow) : $trailingStop;

                if ($day['close'] < $threshold) {
                    $reason = $lastSwingLow !== null && $threshold === $lastSwingLow
                        ? 'swing_low_break'
                        : 'trailing_stop';
                    $exit = [
                        'date' => clone $day['date'],
                        'price' => $day['close'],
                        'reason' => $reason,
                    ];
                    $exitIndex = $i;
                    break;
                }
            }

            if ($exit === null) {
                $finalDay = $dailyData[array_key_last($dailyData)];
                $exit = [
                    'date' => clone $finalDay['date'],
                    'price' => $finalDay['close'],
                    'reason' => 'final_liquidation',
                ];
                $exitIndex = array_key_last($dailyData);
            }

            $trades[] = [
                'ticker' => $ticker,
                'entry_date' => $entryDate,
                'entry_price' => $entryPrice,
                'exit_date' => $exit['date'],
                'exit_price' => $exit['price'],
                'reason' => $exit['reason'],
            ];

            $lastExitIndex = $exitIndex;
        }

        return $trades;
    }

    /**
     * @param array<int, array{date:string, equity:float}> $equityCurve
     * @return array<int, array{date:string, open:float, high:float, low:float, close:float}>
     */
    private function equityCurveToBars(array $equityCurve): array
    {
        if (count($equityCurve) < 2) {
            return [];
        }

        return array_map(function (array $point) {
            return [
                'date' => $point['date'],
                'open' => $point['equity'],
                'high' => $point['equity'],
                'low' => $point['equity'],
                'close' => $point['equity'],
            ];
        }, $equityCurve);
    }
}

