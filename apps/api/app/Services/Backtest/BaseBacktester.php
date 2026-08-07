<?php

namespace App\Services\Backtest;

use App\Services\AssetMetrics;
use App\Services\IdxTicks;
use App\Services\Strategies\BaseStrategy;
use App\Services\Strategies\TrailingStop;
use Illuminate\Support\Carbon;

abstract class BaseBacktester
{
    /**
     * Run a backtest over the supplied OHLCV bars.
     *
     * @param  array<int, array{date:string, open:float, high:float, low:float, close:float}>  $bars
     * @param  float  $capital  Starting capital.
     * @return array{
     *     final_equity: float,
     *     equity_curve: array<int, array{date:string, equity:float}>,
     *     trades: array<int, array{entry_date:string, exit_date:string, entry_price:float, exit_price:float, shares:int, pnl:float}>
     * }
     */
    public function run(array $bars, float $capital): array
    {
        $position = 0;
        $equity = $capital;
        $curve = [];
        $trades = [];
        $entryPrice = 0.0;
        $entryDate = '';
        $extremePrice = null;
        $trailingStop = null;

        foreach ($bars as $i => $bar) {
            $metrics = new AssetMetrics(array_slice($bars, 0, $i + 1));

            if ($position !== 0 && $trailingStop instanceof TrailingStop) {
                if ($position > 0) {
                    $extremePrice = max($extremePrice, $bar['high']);
                    $stopLevel = IdxTicks::floor(
                        $trailingStop->level($extremePrice, $metrics, 'long'),
                        $extremePrice
                    );
                    if ($bar['low'] <= $stopLevel) {
                        $exitPrice = $stopLevel;
                        $equity += $position * $exitPrice;
                        $trades[] = [
                            'entry_date' => $entryDate,
                            'exit_date' => $bar['date'],
                            'entry_price' => $entryPrice,
                            'exit_price' => $exitPrice,
                            'shares' => $position,
                            'pnl' => (float) ($exitPrice - $entryPrice) * $position,
                        ];
                        $position = 0;
                        $trailingStop = null;
                        $extremePrice = null;
                        $curve[] = [
                            'date' => $bar['date'],
                            'equity' => $equity,
                        ];

                        continue;
                    }
                } elseif ($position < 0) {
                    $extremePrice = min($extremePrice, $bar['low']);
                    $stopLevel = IdxTicks::ceil(
                        $trailingStop->level($extremePrice, $metrics, 'short'),
                        $extremePrice
                    );
                    if ($bar['high'] >= $stopLevel) {
                        $exitPrice = $stopLevel;
                        $equity += $position * ($entryPrice - $exitPrice);
                        $trades[] = [
                            'entry_date' => $entryDate,
                            'exit_date' => $bar['date'],
                            'entry_price' => $entryPrice,
                            'exit_price' => $exitPrice,
                            'shares' => $position,
                            'pnl' => (float) ($entryPrice - $exitPrice) * abs($position),
                        ];
                        $position = 0;
                        $trailingStop = null;
                        $extremePrice = null;
                        $curve[] = [
                            'date' => $bar['date'],
                            'equity' => $equity,
                        ];

                        continue;
                    }
                }
            }

            $strategy = $this->createStrategy($metrics);
            $signal = $strategy->signal();

            if ($signal === 'buy' && $position === 0) {
                $price = IdxTicks::round($bar['close']);
                $position = (int) floor($equity / $price);
                $equity -= $position * $price;
                $entryPrice = $price;
                $entryDate = $bar['date'];
                $extremePrice = $bar['high'];
                $trailingStop = $strategy->trailingStop();
            } elseif ($signal === 'sell' && $position > 0) {
                $exitPrice = IdxTicks::round($bar['close']);
                $equity += $position * $exitPrice;
                $trades[] = [
                    'entry_date' => $entryDate,
                    'exit_date' => $bar['date'],
                    'entry_price' => $entryPrice,
                    'exit_price' => $exitPrice,
                    'shares' => $position,
                    'pnl' => (float) ($exitPrice - $entryPrice) * $position,
                ];
                $position = 0;
                $trailingStop = null;
                $extremePrice = null;
            }

            $curve[] = [
                'date' => $bar['date'],
                'equity' => $equity + $position * IdxTicks::round($bar['close']),
            ];
        }

        if ($position > 0) {
            $last = end($bars);
            $lastClose = IdxTicks::round($last['close']);
            $equity += $position * $lastClose;
            $trades[] = [
                'entry_date' => $entryDate,
                'exit_date' => $last['date'],
                'entry_price' => $entryPrice,
                'exit_price' => $lastClose,
                'shares' => $position,
                'pnl' => (float) ($lastClose - $entryPrice) * $position,
            ];
        }

        return [
            'final_equity' => $equity,
            'equity_curve' => $curve,
            'trades' => $trades,
        ];
    }

    abstract protected function createStrategy(AssetMetrics $metrics): BaseStrategy;

    /**
     * Calculate performance metrics for a backtest.
     *
     * @param  array<int, array{date:string, open:float, high:float, low:float, close:float}>  $bars
     * @param  array<int, array{date:string, equity:float}>  $curve
     * @param  array<int, array{entry_date:string, exit_date:string, entry_price:float, exit_price:float, shares:int, pnl:float}>  $trades
     * @return array{cagr:float, maxdd:float, sharpe:float, winrate:float, profit_factor:float, trades:int}
     */
    public function calculateMetrics(array $bars, array $curve, array $trades, float $capital, float $final): array
    {
        $start = Carbon::parse($bars[0]['date']);
        $end = Carbon::parse($bars[count($bars) - 1]['date']);

        // Calculate the holding period using the Actual/Actual day-count
        // convention.  This avoids treating leap years as longer than a
        // calendar year which previously caused small inaccuracies when the
        // backtest spanned a leap day (e.g. 2020-01-01 to 2021-01-01).
        $years = 0.0;
        $cursor = $start->copy();
        while ($cursor->lt($end)) {
            $nextYearStart = $cursor->copy()->addYear()->startOfYear();
            $segmentEnd = $end->lt($nextYearStart) ? $end : $nextYearStart;
            $days = $cursor->diffInDays($segmentEnd);
            $years += $days / $cursor->daysInYear;
            $cursor = $segmentEnd;
        }
        $years = max(1e-9, $years);
        $cagr = ($final / $capital) ** (1 / $years) - 1;

        $peak = $curve[0]['equity'];
        $maxDD = 0.0;
        foreach ($curve as $pt) {
            $peak = max($peak, $pt['equity']);
            $dd = ($pt['equity'] - $peak) / $peak;
            $maxDD = min($maxDD, $dd);
        }
        $maxDD = abs($maxDD);

        $returns = [];
        $prev = $curve[0]['equity'];
        for ($i = 1; $i < count($curve); $i++) {
            $eq = $curve[$i]['equity'];
            $returns[] = ($eq - $prev) / $prev;
            $prev = $eq;
        }
        $avg = $returns === [] ? 0.0 : array_sum($returns) / count($returns);
        $std = 0.0;
        if ($returns !== []) {
            $variance = array_sum(array_map(fn ($r) => ($r - $avg) ** 2, $returns)) / count($returns);
            $std = sqrt($variance);
        }
        $sharpe = $std > 0 ? $avg / $std * sqrt(252) : 0.0;

        $wins = 0;
        $gain = 0;
        $loss = 0;
        foreach ($trades as $t) {
            $pnl = $t['pnl'];
            if ($pnl > 0) {
                $wins++;
                $gain += $pnl;
            } else {
                $loss += abs($pnl);
            }
        }
        $tradeCount = count($trades);
        $winRate = $tradeCount > 0 ? $wins / (float) $tradeCount : 0.0;
        $profitFactor = $loss > 0 ? $gain / $loss : ($gain > 0 ? INF : 0.0);

        return [
            'cagr' => $cagr,
            'maxdd' => $maxDD,
            'sharpe' => $sharpe,
            'winrate' => $winRate,
            'profit_factor' => $profitFactor,
            'trades' => $tradeCount,
        ];
    }
}
