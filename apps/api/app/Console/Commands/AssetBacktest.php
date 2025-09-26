<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Asset;
use App\Services\AssetMetrics;
use App\Services\Backtest\GenericBacktester;
use App\Services\Backtest\HLSLBreakoutBacktestService;
use App\Services\Strategies\AtrBreakout;
use App\Services\Strategies\DonchianBreakout;
use App\Services\Strategies\MovingAverageCrossover;
use App\Services\Strategies\RocMomentum;
use App\Services\Strategies\RsiReversal;
use App\Services\Strategies\SupportResistanceBreakout;
use App\Services\Strategies\TrailingStop;

class AssetBacktest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'asset:backtest
        {--sym= : Asset ticker symbol}
        {--strategy=DonchBO : Strategy class name}
        {--capital=3000000 : Starting capital}
        {--compare : Compare multiple strategies}
        {--strategies=* : Comma-separated list when using --compare}
        {--trailing= : Trailing stop definition (percent:0.05 or atr:3,14)}
        {--trailing-strategies=* : Strategies to apply the trailing stop}
        {--trades : Print all trades during backtest}
        {--tickers= : Comma-separated tickers for the HLSL breakout scan}
        {--board-lot=100 : Board lot size for the breakout scan}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run a backtest for a given asset symbol and strategy';

    public function __construct(private readonly HLSLBreakoutBacktestService $hlslBacktest)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tickerOption = (string) ($this->option('tickers') ?? '');

        if (trim($tickerOption) !== '') {
            if ($this->option('compare')) {
                $this->error('The --tickers option cannot be combined with --compare.');
                return Command::FAILURE;
            }

            return $this->runHlslBreakout($tickerOption);
        }

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
            'DonchBO' => DonchianBreakout::class,
            'AtrBO' => AtrBreakout::class,
            'RocMomentum' => RocMomentum::class,
            'MACross' => MovingAverageCrossover::class,
            'RsiReversal' => RsiReversal::class,
            'SR_BO' => SupportResistanceBreakout::class,
        ];

        $capital = (float) $this->option('capital');

        $trailingOpt = (string) $this->option('trailing');
        $trailingStop = null;
        if ($trailingOpt !== '') {
            [$type, $params] = explode(':', $trailingOpt, 2) + [null, null];
            if ($type === 'percent' && $params !== null) {
                $trailingStop = TrailingStop::percent((float) $params);
            } elseif ($type === 'atr' && $params !== null) {
                $parts = array_map('trim', explode(',', $params));
                $multiple = (float) ($parts[0] ?? 0);
                $period = isset($parts[1]) ? (int) $parts[1] : 14;
                $trailingStop = TrailingStop::atr($multiple, $period);
            } else {
                $this->error('Invalid trailing stop format.');
                return Command::FAILURE;
            }
        }

        $targetsOpt = $this->option('trailing-strategies');
        if (is_array($targetsOpt)) {
            $trailingStrategies = $targetsOpt;
        } elseif (is_string($targetsOpt) && $targetsOpt !== '') {
            $trailingStrategies = array_map('trim', explode(',', $targetsOpt));
        } else {
            $trailingStrategies = [];
        }
        $applyTrailingToAll = $trailingStrategies === [] || in_array('*', $trailingStrategies, true);

        if ($this->option('trades') && $this->option('compare')) {
            $this->error('The --trades option cannot be combined with --compare.');
            return Command::FAILURE;
        }

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
                $useTrailing = $trailingStop && ($applyTrailingToAll || in_array($name, $trailingStrategies, true));
                $strategy = $useTrailing
                    ? new $class(new AssetMetrics([$bars[0]]), trailingStop: $trailingStop)
                    : new $class(new AssetMetrics([$bars[0]]));
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
        $useTrailing = $trailingStop && ($applyTrailingToAll || in_array($name, $trailingStrategies, true));
        $strategy = $useTrailing
            ? new $class(new AssetMetrics([$bars[0]]), trailingStop: $trailingStop)
            : new $class(new AssetMetrics([$bars[0]]));
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

        if ($this->option('trades')) {
            $this->info("Strategy: {$name}");

            $tradeRows = [];
            foreach ($result['trades'] as $i => $t) {
                $tradeRows[] = [
                    $i + 1,
                    $t['entry_date'],
                    $t['exit_date'],
                    sprintf('%.2f', $t['entry_price']),
                    sprintf('%.2f', $t['exit_price']),
                    (string) $t['shares'],
                    sprintf('%.2f', $t['pnl']),
                ];
            }

            $this->table(
                ['#', 'Entry Date', 'Exit Date', 'Entry Price', 'Exit Price', 'Shares', 'PnL'],
                $tradeRows
            );
        }

        return Command::SUCCESS;
    }

    private function runHlslBreakout(string $tickerOption): int
    {
        $tickers = array_filter(array_map('trim', explode(',', $tickerOption)));
        $tickers = array_values(array_unique(array_map('strtoupper', $tickers)));

        if ($tickers === []) {
            $this->error('At least one ticker must be provided via --tickers.');
            return Command::FAILURE;
        }

        $dataByTicker = [];
        foreach ($tickers as $ticker) {
            $asset = Asset::where('symbol', $ticker)->first();
            if (! $asset) {
                $this->warn("Asset {$ticker} not found. Skipping.");
                continue;
            }

            $prices = $asset->prices()->orderBy('date')->get(['date', 'open', 'high', 'low', 'close', 'volume']);
            if ($prices->isEmpty()) {
                $this->warn("No OHLCV data available for {$ticker}. Skipping.");
                continue;
            }

            $dataByTicker[$ticker] = $prices->map(function ($price) {
                $date = $price->date instanceof \DateTimeInterface
                    ? $price->date->toDateString()
                    : (string) $price->date;

                return [
                    'date' => $date,
                    'open' => (float) $price->open,
                    'high' => (float) $price->high,
                    'low' => (float) $price->low,
                    'close' => (float) $price->close,
                    'volume' => (float) $price->volume,
                ];
            })->all();
        }

        if ($dataByTicker === []) {
            $this->error('No OHLCV data available for the provided tickers.');
            return Command::FAILURE;
        }

        $initialCapital = (float) $this->option('capital');
        $boardLotOption = $this->option('board-lot');
        $boardLot = is_numeric($boardLotOption) ? max((int) $boardLotOption, 1) : 100;

        $result = $this->hlslBacktest->run($dataByTicker, $initialCapital, $boardLot);
        $stats = $result['stats'] ?? [];

        $this->line('');
        $this->info('HLSL Breakout Backtest');
        $this->line('Tickers: ' . implode(', ', array_keys($dataByTicker)));
        $this->line('Board Lot: ' . $boardLot);

        $statRows = [
            ['Initial Capital', number_format($stats['initial_capital'] ?? $initialCapital, 2)],
            ['Final Equity', number_format($stats['final_equity'] ?? $initialCapital, 2)],
            ['Total Return %', number_format($stats['total_return_pct'] ?? 0.0, 2)],
            ['CAGR %', number_format($stats['CAGR_pct'] ?? 0.0, 2)],
            ['Max Drawdown %', number_format($stats['max_drawdown_pct'] ?? 0.0, 2)],
            ['Trades', (string) ($stats['num_trades'] ?? 0)],
            ['Win Rate %', number_format($stats['win_rate_pct'] ?? 0.0, 2)],
            ['Avg Win %', number_format($stats['avg_win_pct'] ?? 0.0, 2)],
            ['Avg Loss %', number_format($stats['avg_loss_pct'] ?? 0.0, 2)],
            ['Profit Factor', ($stats['profit_factor'] ?? null) === null
                ? 'N/A'
                : number_format((float) $stats['profit_factor'], 2)
            ],
        ];

        $this->table(['Metric', 'Value'], $statRows);

        if ($this->option('trades')) {
            $this->line('');
            $this->info('Trades');

            $trades = $result['trades'] ?? [];
            if ($trades === []) {
                $this->info('No trades were executed.');
            } else {
                $tradeRows = [];
                foreach ($trades as $index => $trade) {
                    $tradeRows[] = [
                        $index + 1,
                        $trade['ticker'] ?? '',
                        $trade['entry_date'] ?? '',
                        number_format((float) ($trade['entry_price'] ?? 0.0), 4),
                        (string) ($trade['shares'] ?? 0),
                        $trade['exit_date'] ?? '',
                        number_format((float) ($trade['exit_price'] ?? 0.0), 4),
                        number_format((float) ($trade['return_pct'] ?? 0.0), 2),
                        $trade['reason'] ?? '',
                    ];
                }

                $this->table([
                    '#',
                    'Ticker',
                    'Entry Date',
                    'Entry Price',
                    'Shares',
                    'Exit Date',
                    'Exit Price',
                    'Return %',
                    'Reason',
                ], $tradeRows);
            }
        }

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
