<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Asset;
use App\Services\Backtest\GenericBacktester;
use App\Services\Strategies\AtrBreakout;
use App\Services\Strategies\DonchianBreakout;
use App\Services\Strategies\RocMomentum;
use App\Services\AssetMetrics;

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
                $metrics = $backtester->calculateMetrics($bars, $result['equity_curve'], $result['trades'], $capital, $result['final_equity']);
                $metricsByName[$name] = $this->formatMetrics($metrics);
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

        $metrics = $backtester->calculateMetrics($bars, $result['equity_curve'], $result['trades'], $capital, $result['final_equity']);
        $metrics = $this->formatMetrics($metrics);

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
     * @param array<string, float|int> $metrics
     * @return array<string, string|int>
     */
    private function formatMetrics(array $metrics): array
    {
        return [
            'cagr' => sprintf('%.2f%%', $metrics['cagr'] * 100),
            'maxdd' => sprintf('%.2f%%', $metrics['maxdd'] * 100),
            'sharpe' => sprintf('%.2f', $metrics['sharpe']),
            'winrate' => sprintf('%.2f%%', $metrics['winrate'] * 100),
            'profit_factor' => is_infinite($metrics['profit_factor']) ? 'Inf' : sprintf('%.2f', $metrics['profit_factor']),
            'trades' => $metrics['trades'],
        ];
    }
}
