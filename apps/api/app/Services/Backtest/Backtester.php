<?php

namespace App\Services\Backtest;

use App\Services\AssetMetrics;
use App\Services\IdxTicks;
use App\Services\Strategies\Strategy;
use App\Services\Strategies\TrailingStop;

abstract class Backtester
{
    /**
     * Run a backtest over the supplied OHLCV bars.
     *
     * @param array<int, array{date:string, open:float, high:float, low:float, close:float}> $bars
     * @param float $capital Starting capital.
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
                            'entry_date'  => $entryDate,
                            'exit_date'   => $bar['date'],
                            'entry_price' => $entryPrice,
                            'exit_price'  => $exitPrice,
                            'shares'      => $position,
                            'pnl'         => (float)($exitPrice - $entryPrice) * $position,
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
                            'entry_date'  => $entryDate,
                            'exit_date'   => $bar['date'],
                            'entry_price' => $entryPrice,
                            'exit_price'  => $exitPrice,
                            'shares'      => $position,
                            'pnl'         => (float)($entryPrice - $exitPrice) * abs($position),
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
                $entryDate  = $bar['date'];
                $extremePrice = $bar['high'];
                $trailingStop = $strategy->trailingStop();
            } elseif ($signal === 'sell' && $position > 0) {
                $exitPrice = IdxTicks::round($bar['close']);
                $equity += $position * $exitPrice;
                $trades[] = [
                    'entry_date'  => $entryDate,
                    'exit_date'   => $bar['date'],
                    'entry_price' => $entryPrice,
                    'exit_price'  => $exitPrice,
                    'shares'      => $position,
                    'pnl'         => (float)($exitPrice - $entryPrice) * $position,
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
                'entry_date'  => $entryDate,
                'exit_date'   => $last['date'],
                'entry_price' => $entryPrice,
                'exit_price'  => $lastClose,
                'shares'      => $position,
                'pnl'         => (float)($lastClose - $entryPrice) * $position,
            ];
        }

        return [
            'final_equity' => $equity,
            'equity_curve' => $curve,
            'trades'       => $trades,
        ];
    }

    abstract protected function createStrategy(AssetMetrics $metrics): Strategy;
}
