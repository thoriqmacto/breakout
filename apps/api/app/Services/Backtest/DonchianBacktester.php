<?php

namespace App\Services\Backtest;

use App\Services\AssetMetrics;
use App\Services\Strategies\DonchianBreakout;

class DonchianBacktester
{
    /**
     * @param array<int, array{date:string, open:float, high:float, low:float, close:float}> $bars
     * @param float $capital Starting capital supplied by user
     * @return array{final_equity: float, equity_curve: array<int, array{date:string, equity:float}>}
     */
    public function run(array $bars, float $capital): array
    {
        $position = 0; // number of shares
        $equity = $capital;
        $curve = [];

        foreach ($bars as $i => $bar) {
            $metrics = new AssetMetrics(array_slice($bars, 0, $i + 1));
            $strategy = new DonchianBreakout($metrics);
            $signal = $strategy->signal();

            if ($signal === 'buy' && $position === 0) {
                $position = (int) floor($equity / $bar['close']);
                $equity -= $position * $bar['close'];
            } elseif ($signal === 'sell' && $position > 0) {
                $equity += $position * $bar['close'];
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
        }

        return [
            'final_equity' => $equity,
            'equity_curve' => $curve,
        ];
    }
}
