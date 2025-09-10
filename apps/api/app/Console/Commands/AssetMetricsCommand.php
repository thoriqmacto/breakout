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

        $headers = ['Symbol', 'Close', 'MA30', 'High20', 'ATR14', 'ROC13', 'Uptrend'];
        $rows = [];

        foreach ($symbols as $symbol) {
            $asset = Asset::where('symbol', $symbol)->first();
            if (!$asset) {
                continue;
            }
            $bars = $asset->prices()->orderBy('date')->get()->toArray();
            $metrics = new AssetMetrics($bars);
            $rows[] = [
                $symbol,
                $metrics->lastClose(),
                round($metrics->movingAverage(30), 0),
                round($metrics->periodHigh(20), 0),
                round($metrics->atr(14), 0),
                round($metrics->rocWeeks(13), 2),
                $metrics->isUptrend() ? 'Yes' : 'No',
            ];
        }

        $this->table($headers, $rows);

        return Command::SUCCESS;
    }
}
