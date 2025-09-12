<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Asset;
use App\Services\Backtest\GenericBacktester;
use App\Services\Strategies\AtrBreakout;
use App\Services\Strategies\DonchianBreakout;
use App\Services\Strategies\RocMomentum;
use App\Services\AssetMetrics;
use Illuminate\Support\Carbon;

class AssetBacktest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'asset:backtest
        {--sym= : Asset ticker symbol}
        {--strategy=DonchianBreakout : Strategy class name}
        {--capital=3000000 : Starting capital}
        {--compare : Compare multiple strategies}
        {--strategies=* : Comma-separated list when using --compare}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run a backtest for a given asset symbol and strategy';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $symbol = strtoupper((string) $this->option('sym'));
        if ($symbol === '') {
            $this->error('Symbol must be provided via --sym option.');
            return Command::FAILURE;
        }

        $asset = Asset::where('symbol', $symbol)->first();
        if (!$asset) {
            $this->error("Asset {$symbol} not found.");
            return Command::FAILURE;
        }

        $prices = $asset->prices()->orderBy('date')->get(['date','open','high','low','close']);
        if ($prices->isEmpty()) {
            $this->error('No price data available for this asset.');
            return Command::FAILURE;
        }

        $bars = $prices->map(fn($p) => [
            'date'  => $p->date->toDateString(),
            'open'  => (float) $p->open,
            'high'  => (float) $p->high,
            'low'   => (float) $p->low,
            'close' => (float) $p->close,
        ])->all();

        $map = [
            'DonchianBreakout' => DonchianBreakout::class,
            'AtrBreakout' => AtrBreakout::class,
            'RocMomentum' => RocMomentum::class,
        ];

        $capital = (float) $this->option('capital');

        if ($this->option('compare')) {
            $namesOpt = $this->option('strategies');
            if (is_array($namesOpt)) {
                $names = $namesOpt;
            } elseif (is_string($namesOpt) && $namesOpt !== '') {
                $names = array_map('trim', explode(',', $namesOpt));
            } else {
                $names = [];
            }
            if ($names === []) {
                $names = array_keys($map);
            }

            $metricsByName = [];
            foreach ($names as $name) {
                if (!array_key_exists($name, $map)) {
                    $this->error("Unknown strategy: {$name}");
                    return Command::FAILURE;
                }
                $class = $map[$name];
                $strategy = new $class(new AssetMetrics([$bars[0]]));
                $backtester = new GenericBacktester($strategy);
                $result = $backtester->run($bars, $capital);
                $metricsByName[$name] = $this->calculateMetrics($bars, $result['equity_curve'], $result['trades'], $capital, $result['final_equity']);
            }
            $this->info("Symbol: {$symbol}");
            $this->info('Bars: ' . count($bars));

            $metricLabels = [
                'cagr' => 'CAGR',
                'maxdd' => 'MaxDD',
                'sharpe' => 'Sharpe',
                'winrate' => 'Win-rate',
                'profit_factor' => 'Profit Factor',
                'trades' => 'Trades',
            ];

            $rows = [];
            foreach ($metricLabels as $key => $label) {
                $row = [$label];
                foreach ($names as $name) {
                    $row[] = (string) $metricsByName[$name][$key];
                }
                $rows[] = $row;
            }

            $headers = array_merge(['Metric'], $names);
            $this->table($headers, $rows);
            return Command::SUCCESS;
        }

        $name = (string) $this->option('strategy');
        if (!array_key_exists($name, $map)) {
            $this->error("Unknown strategy: {$name}");
            return Command::FAILURE;
        }

        $class = $map[$name];
        $strategy = new $class(new AssetMetrics([$bars[0]]));
        $backtester = new GenericBacktester($strategy);

        $result = $backtester->run($bars, $capital);

        $metrics = $this->calculateMetrics($bars, $result['equity_curve'], $result['trades'], $capital, $result['final_equity']);

        $rows = [
            ['CAGR', $metrics['cagr']],
            ['MaxDD', $metrics['maxdd']],
            ['Sharpe', $metrics['sharpe']],
            ['Win-rate', $metrics['winrate']],
            ['Profit Factor', $metrics['profit_factor']],
            ['Trades', (string) $metrics['trades']],
        ];

        $this->table(['Metric', 'Value'], $rows);

        return Command::SUCCESS;
    }

    /**
     * @param array<int, array{date:string, open:float, high:float, low:float, close:float}> $bars
     * @param array<int, array{date:string, equity:float}> $curve
     * @param array<int, array{entry_date:string, exit_date:string, entry_price:float, exit_price:float, shares:int, pnl:float}> $trades
     * @return array<string, string|int>
     */
    private function calculateMetrics(array $bars, array $curve, array $trades, float $capital, float $final): array
    {
        $start = Carbon::parse($bars[0]['date']);
        $end   = Carbon::parse($bars[count($bars)-1]['date']);
        $years = max(1e-9, $start->diffInDays($end) / 365);
        $cagr  = ($final / $capital) ** (1 / $years) - 1;

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
            $variance = array_sum(array_map(fn($r) => ($r - $avg) ** 2, $returns)) / count($returns);
            $std = sqrt($variance);
        }
        $sharpe = $std > 0 ? $avg / $std * sqrt(252) : 0.0;

        $wins = 0; $gain = 0; $loss = 0;
        foreach ($trades as $t) {
            $pnl = $t['pnl'];
            if ($pnl > 0) { $wins++; $gain += $pnl; }
            else { $loss += abs($pnl); }
        }
        $tradeCount = count($trades);
        $winRate = $tradeCount > 0 ? $wins / $tradeCount : 0.0;
        $profitFactor = $loss > 0 ? $gain / $loss : ($gain > 0 ? INF : 0.0);

        return [
            'cagr' => sprintf('%.2f%%', $cagr * 100),
            'maxdd' => sprintf('%.2f%%', $maxDD * 100),
            'sharpe' => sprintf('%.2f', $sharpe),
            'winrate' => sprintf('%.2f%%', $winRate * 100),
            'profit_factor' => is_infinite($profitFactor) ? 'Inf' : sprintf('%.2f', $profitFactor),
            'trades' => $tradeCount,
        ];
    }
}
