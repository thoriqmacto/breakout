<?php

namespace App\Services\Backtest;

use Carbon\Carbon;

class HLSLBreakoutBacktestService
{
    public function __construct(private readonly HLSLBreakoutBacktester $backtester) {}

    public function run(array $tickerDailyData, float $initialCapital = 100000.0, int $boardLot = 100): array
    {
        $allTrades = [];
        $earliestDate = null;
        $latestDate = null;

        foreach ($tickerDailyData as $ticker => $dailyRows) {
            $result = $this->backtester->processTicker($ticker, $dailyRows);

            if ($result['start'] instanceof Carbon) {
                $firstDay = $result['start'];
                $earliestDate = $earliestDate === null || $firstDay->lt($earliestDate)
                    ? clone $firstDay
                    : $earliestDate;
            }

            if ($result['end'] instanceof Carbon) {
                $lastDay = $result['end'];
                $latestDate = $latestDate === null || $lastDay->gt($latestDate)
                    ? clone $lastDay
                    : $latestDate;
            }

            if ($result['trades'] !== []) {
                $allTrades = array_merge($allTrades, $result['trades']);
            }
        }

        usort($allTrades, fn ($a, $b) => $a['entry_date'] <=> $b['entry_date']);

        $capital = $initialCapital;
        $equityCurve = [];
        $enhancedTrades = [];
        $lastEquityDate = null;

        if ($earliestDate !== null) {
            $equityCurve[] = [
                'date' => $earliestDate->toDateString(),
                'equity' => round($capital, 2),
            ];
            $lastEquityDate = $earliestDate;
        }

        foreach ($allTrades as $trade) {
            $entryPrice = $trade['entry_price'];
            $shares = $entryPrice > 0.0
                ? (int) floor($capital / $entryPrice / $boardLot) * $boardLot
                : 0;

            if ($shares <= 0) {
                continue;
            }

            $invested = $shares * $entryPrice;
            $capital -= $invested;

            $exitPrice = $trade['exit_price'];
            $exitValue = $shares * $exitPrice;
            $capital += $exitValue;

            $returnPct = $entryPrice > 0 ? (($exitPrice - $entryPrice) / $entryPrice) * 100.0 : 0.0;
            $profit = $exitValue - $invested;

            $enhancedTrades[] = array_merge($trade, [
                'shares' => $shares,
                'return_pct' => round($returnPct, 2),
                'profit' => $profit,
            ]);

            $equityCurve[] = [
                'date' => $trade['exit_date']->toDateString(),
                'equity' => round($capital, 2),
            ];
            $lastEquityDate = $trade['exit_date'];
        }

        if ($latestDate !== null && ($lastEquityDate === null || $latestDate->gt($lastEquityDate))) {
            $equityCurve[] = [
                'date' => $latestDate->toDateString(),
                'equity' => round($capital, 2),
            ];
        }

        $stats = $this->backtester->compileStats($enhancedTrades, $equityCurve, $initialCapital, $capital);

        return [
            'trades' => array_map(function (array $trade) {
                return [
                    'ticker' => $trade['ticker'],
                    'entry_date' => $trade['entry_date']?->toDateString(),
                    'entry_price' => round($trade['entry_price'], 4),
                    'shares' => $trade['shares'] ?? 0,
                    'exit_date' => $trade['exit_date']?->toDateString(),
                    'exit_price' => round($trade['exit_price'], 4),
                    'return_pct' => $trade['return_pct'] ?? 0.0,
                    'reason' => $trade['reason'],
                ];
            }, $enhancedTrades),
            'equity_curve' => $equityCurve,
            'stats' => $stats,
        ];
    }
}
