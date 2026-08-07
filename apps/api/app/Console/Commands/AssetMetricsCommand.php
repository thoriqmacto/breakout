<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\BandarDetectorSummary;
use App\Models\Metric;
use App\Services\AssetMetrics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AssetMetricsCommand extends Command
{
    protected $signature = 'asset:metrics {--sym=} {--all} {--persist : Persist the calculated metrics to the database}';

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

        $headers = ['Rank', 'Symbol', 'Close', 'MA50', 'MA100', '20wH', '55wH', 'ATR14d', 'ROC13', 'AvgVol20', 'Vol/Avg20', 'Close/20wH', 'Close/55wH', 'IsUptrend?', 'Bars', 'PBAS', 'BAVG'];
        $rows = [];
        $persist = (bool) $this->option('persist');
        $persistedCount = 0;

        foreach ($symbols as $symbol) {
            $asset = Asset::where('symbol', $symbol)->first();
            if (! $asset) {
                continue;
            }
            $bars = $asset->prices()->orderBy('date')->get()->toArray();
            if ($bars === []) {
                continue;
            }
            $metrics = new AssetMetrics($bars);
            $totalBars = count($bars);
            $latestDate = $bars[array_key_last($bars)]['date'] ?? null;

            $close = $metrics->lastClose();
            $closeRounded = round($close, 2);
            $ma50 = round($metrics->movingAverage(50), 0);
            $ma100 = round($metrics->movingAverage(100), 0);
            $roc13 = round($metrics->rocWeeks(13), 2);
            $avgVol20 = $metrics->averageVolume(20);
            $lastVolume = $metrics->lastVolume();
            $volToAvg = $avgVol20 > 0 ? round($lastVolume / $avgVol20, 5) : 0;
            $avgVol20 = round($avgVol20, 0);
            $high20 = $metrics->periodHigh(20);
            $high55 = $metrics->periodHigh(55);
            $closeVsHigh20 = $high20 > 0 ? round($close / $high20, 2) : 0;
            $closeVsHigh55 = $high55 > 0 ? round($close / $high55, 2) : 0;
            $high20 = round($high20, 0);
            $high55 = round($high55, 0);
            $isUptrend = $metrics->isUptrend();
            $atr14 = round($metrics->atr(14), 0);
            $pbas = DB::table('features_daily')
                ->where('symbol', $asset->symbol)
                ->orderByDesc('date')
                ->value('pbas');
            $pbas = $pbas === null ? null : (int) $pbas;
            $bavg = null;
            if ($latestDate) {
                $bavg = BandarDetectorSummary::query()
                    ->where('asset_id', $asset->id)
                    ->whereNotNull('average_price')
                    ->whereDate('from_date', '<=', $latestDate)
                    ->whereDate('to_date', '>=', $latestDate)
                    ->orderByDesc('from_date')
                    ->value('average_price');
                $bavg = $bavg === null ? null : (float) $bavg;
            }

            $rows[] = [
                'symbol' => $symbol,
                'close' => $close,
                'ma50' => $ma50,
                'ma100' => $ma100,
                'high20' => $high20,
                'high55' => $high55,
                'atr14' => $atr14,
                'roc13' => (float) $roc13,
                'avg_vol20' => $avgVol20,
                'vol_vs_avg20' => $volToAvg,
                'close_vs_high20' => $closeVsHigh20,
                'close_vs_high55' => $closeVsHigh55,
                'uptrend' => $isUptrend ? 'Yes' : 'No',
                'bars' => $totalBars,
                'pbas' => $pbas,
                'bavg' => $bavg,
                'sort_uptrend' => $isUptrend ? 1 : 0,
                'sort_roc13' => (float) $roc13,
                'sort_close_vs_high55' => (float) $closeVsHigh55,
                'sort_close_vs_high20' => (float) $closeVsHigh20,
                'sort_vol_vs_avg20' => (float) $volToAvg,
            ];

            if ($persist) {
                Metric::updateOrCreate(
                    ['asset_id' => $asset->id],
                    [
                        'symbol' => $asset->symbol,
                        'name' => $asset->name,
                        'close' => $closeRounded,
                        'ma50' => $ma50,
                        'ma100' => $ma100,
                        'high20' => $high20,
                        'high55' => $high55,
                        'atr14' => $atr14,
                        'roc13' => (float) $roc13,
                        'avg_vol20' => $avgVol20,
                        'vol_vs_avg20' => $volToAvg,
                        'close_vs_high20' => $closeVsHigh20,
                        'close_vs_high55' => $closeVsHigh55,
                        'uptrend' => $isUptrend,
                        'bars' => $totalBars,
                        'pbas' => $pbas,
                        'bavg' => $bavg,
                        'sort_uptrend' => $isUptrend ? 1 : 0,
                        'sort_roc13' => (float) $roc13,
                        'sort_close_vs_high55' => (float) $closeVsHigh55,
                        'sort_close_vs_high20' => (float) $closeVsHigh20,
                        'sort_vol_vs_avg20' => (float) $volToAvg,
                    ]
                );
                $persistedCount++;
            }
        }

        usort($rows, function ($a, $b) {
            $A = [
                (int) ($a['sort_uptrend'] ?? 0),
                (float) ($a['sort_roc13'] ?? 0),
                (float) ($a['sort_close_vs_high55'] ?? 0),
                (float) ($a['sort_close_vs_high20'] ?? 0),
                (float) ($a['sort_vol_vs_avg20'] ?? 0),
            ];
            $B = [
                (int) ($b['sort_uptrend'] ?? 0),
                (float) ($b['sort_roc13'] ?? 0),
                (float) ($b['sort_close_vs_high55'] ?? 0),
                (float) ($b['sort_close_vs_high20'] ?? 0),
                (float) ($b['sort_vol_vs_avg20'] ?? 0),
            ];
            $result = $B <=> $A; // descending

            if ($result === 0) {
                return $a['symbol'] <=> $b['symbol'];
            }

            return $result;
        });

        $rankedRows = [];

        foreach (array_values($rows) as $index => $r) {
            $rankedRows[] = [
                (string) ($index + 1),
                $r['symbol'],
                $r['close'],
                $r['ma50'],
                $r['ma100'],
                $r['high20'],
                $r['high55'],
                $r['atr14'],
                $r['roc13'],
                $r['avg_vol20'],
                $r['vol_vs_avg20'],
                $r['close_vs_high20'],
                $r['close_vs_high55'],
                $r['uptrend'],
                $r['bars'],
                $r['pbas'],
                $r['bavg'],
            ];
        }

        $this->table($headers, $rankedRows);

        if ($persist) {
            $this->info(sprintf('Persisted metrics for %d asset(s).', $persistedCount));
        }

        return Command::SUCCESS;
    }
}
