<?php

namespace App\Services\Backtest;

use App\Services\AssetMetrics;
use App\Services\Strategies\Strategy;

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

        foreach ($bars as $i => $bar) {
            $metrics = new AssetMetrics(array_slice($bars, 0, $i + 1));
            $strategy = $this->createStrategy($metrics);
            $signal = $strategy->signal();

            if ($signal === 'buy' && $position === 0) {
                $position = (int) floor($equity / $bar['close']);
                $equity -= $position * $bar['close'];
                $entryPrice = $bar['close'];
                $entryDate  = $bar['date'];
            } elseif ($signal === 'sell' && $position > 0) {
                $exitPrice = $bar['close'];
                $equity += $position * $exitPrice;
                $trades[] = [
                    'entry_date'  => $entryDate,
                    'exit_date'   => $bar['date'],
                    'entry_price' => $entryPrice,
                    'exit_price'  => $exitPrice,
                    'shares'      => $position,
                    'pnl'         => ($exitPrice - $entryPrice) * $position,
                ];
                $position = 0;
            }

            $curve[] = [
                'date' => $bar['date'],
                'equity' => $equity + $position * $bar['close'],
            ];
        }

        if ($position > 0) {
            $last = end($bars);
            $equity += $position * $last['close'];
            $trades[] = [
                'entry_date'  => $entryDate,
                'exit_date'   => $last['date'],
                'entry_price' => $entryPrice,
                'exit_price'  => $last['close'],
                'shares'      => $position,
                'pnl'         => ($last['close'] - $entryPrice) * $position,
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
