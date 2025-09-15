<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\AssetMetrics;
use Illuminate\Console\Command;

class AssetMetricsCommand extends Command
{
    protected $signature = 'asset:metrics {--sym=} {--all}';
    protected $description = 'Display asset metrics using AssetMetrics service';

    public function handle()
    {
        if ($this->option('all')) {
            $symbols = Asset::pluck('symbol')->all();
        } elseif ($this->option('sym')) {
            $symbols = array_map('trim', explode(',', (string) $this->option('sym')));
        } else {
            $input = $this->ask('Enter symbols (comma separated)');
            $symbols = array_map('trim', explode(',', (string) $input));
        }

        $headers = ['Symbol', 'Close', 'MA50', 'MA100', '20wH', '55wH', 'ATR14d', 'ROC13', 'AvgVol20', 'IsUptrend?', 'Bars'];
        $rows = [];

        foreach ($symbols as $symbol) {
            $asset = Asset::where('symbol', $symbol)->first();
            if (!$asset) {
                continue;
            }
            $bars = $asset->prices()->orderBy('date')->get()->toArray();
            $metrics = new AssetMetrics($bars);
            $totalBars = count($bars);

            $close = $metrics->lastClose();
            $ma50 = round($metrics->movingAverage(50), 0);
            $ma100 = round($metrics->movingAverage(100), 0);
            $roc13 = round($metrics->rocWeeks(13), 2);
            $avgVol20 = round($metrics->averageVolume(20), 0);
            $isUptrend = $metrics->isUptrend();

            $rows[] = [
                'symbol' => $symbol,
                'close' => $close,
                'ma50' => $ma50,
                'ma100' => $ma100,
                'high20' => round($metrics->periodHigh(20), 0),
                'high55' => round($metrics->periodHigh(55), 0),
                'atr14' => round($metrics->atr(14), 0),
                'roc13' => $roc13,
                'avg_vol20' => $avgVol20,
                'uptrend' => $isUptrend ? 'Yes' : 'No',
                'bars' => $totalBars,
                'sort_uptrend' => $isUptrend ? 1 : 0,
                'sort_above_ma100' => $close > $ma100 ? 1 : 0,
                'sort_above_ma50' => $close > $ma50 ? 1 : 0,
            ];
        }

        usort($rows, function ($a, $b) {
            return [$b['sort_uptrend'], $b['sort_above_ma100'], $b['sort_above_ma50'], $b['roc13']]
                <=> [$a['sort_uptrend'], $a['sort_above_ma100'], $a['sort_above_ma50'], $a['roc13']];
        });

        $rows = array_map(fn($r) => [
            $r['symbol'],
            $r['close'],
            $r['ma50'],
            $r['ma100'],
            $r['high20'],
            $r['high55'],
            $r['atr14'],
            $r['roc13'],
            $r['avg_vol20'],
            $r['uptrend'],
            $r['bars'],
        ], $rows);

        $this->table($headers, $rows);

        return Command::SUCCESS;
    }
}
