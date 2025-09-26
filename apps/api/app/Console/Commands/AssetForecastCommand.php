<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Backtest;
use App\Models\BacktestTrade;
use App\Services\Strategies\HLSLBreakoutStrategy;
use Illuminate\Console\Command;

class AssetForecastCommand extends Command
{
    protected $signature = 'asset:forecast
        {--sym=* : Comma-separated or repeated tickers to analyze}
        {--all : Include every asset with price data}
        {--bt-result=* : Include the latest backtest summary for the provided tickers}
        {--trades : Include detailed backtest trades for the --bt-result tickers}
        {--strategy=HLSLBreakout : Strategy identifier (currently only HLSLBreakout)}';

    protected $description = 'Forecast potential entry levels for assets using a breakout strategy.';

    public function handle(): int
    {
        $tickers = $this->option('all') ? $this->resolveAllTickers() : $this->resolveTickers();
        if ($tickers === []) {
            if ($this->option('all')) {
                $this->error('No assets with price data were found.');
            } else {
                $this->error('At least one ticker must be provided via --sym.');
            }
            return Command::FAILURE;
        }

        $backtestSummarySymbols = $this->resolveSymbolListOption('bt-result');
        $includeBacktestTrades = (bool) $this->option('trades');
        $backtestTradeSymbols = [];

        if ($includeBacktestTrades) {
            if ($backtestSummarySymbols === []) {
                $this->error('The --trades option requires --bt-result to specify one or more tickers.');

                return Command::FAILURE;
            }

            $backtestTradeSymbols = $backtestSummarySymbols;
        }

        $strategyOption = (string) $this->option('strategy');
        if ($strategyOption === '') {
            $strategyOption = 'HLSLBreakout';
        }

        if (strcasecmp($strategyOption, 'HLSL') === 0) {
            $strategyOption = 'HLSLBreakout';
        }

        if ($strategyOption !== 'HLSLBreakout') {
            $this->error("Unsupported strategy: {$strategyOption}. Only HLSLBreakout is available at the moment.");
            return Command::FAILURE;
        }

        $strategy = new HLSLBreakoutStrategy();
        $rows = [];

        foreach ($tickers as $symbol) {
            $bars = $this->loadBars($symbol);
            if ($bars === null) {
                continue;
            }

            $analysis = $strategy->analyze($bars);
            $dailyData = $analysis['daily'];
            if ($dailyData === []) {
                $this->warn("{$symbol}: insufficient price history.");
                continue;
            }

            $latestDay = $dailyData[array_key_last($dailyData)];
            $latestClose = (float) $latestDay['close'];
            $latestDate = $latestDay['date']->toDateString();

            $forecast = $this->forecastNextEntry($analysis['weekly'], $analysis['signals']);

            $entryPrice = $forecast['entry_price'];
            $distancePct = null;
            if ($entryPrice !== null && $latestClose > 0.0) {
                $distancePct = (($entryPrice - $latestClose) / $latestClose) * 100.0;
            }

            $rows[] = [
                'symbol' => $symbol,
                'last_close' => sprintf('%.0f', $latestClose),
                'last_date' => $latestDate,
                'entry_price' => $entryPrice !== null ? sprintf('%.0f', $entryPrice) : '—',
                'distance_pct' => $distancePct !== null ? sprintf('%.2f%%', $distancePct) : '—',
                'swing_week' => $forecast['week_end'] ?? '—',
                'volume_ema' => $forecast['volume_ema'] !== null
                    ? number_format((float) $forecast['volume_ema'], 0, '.', ',')
                    : '—',
                'volume_target' => $forecast['volume_target'] !== null
                    ? number_format((float) $forecast['volume_target'], 0, '.', ',')
                    : '—',
                'note' => $forecast['note'] ?? '',
            ];
        }

        if ($rows === []) {
            $this->warn('No rows to display.');
            return Command::FAILURE;
        }

        $this->table([
            'Ticker',
            'Close',
            'Close Date',
            'Alert',
            'Dist%',
            'Swing Week',
            'Volume EMA',
            'Volume Target',
            'Note',
        ], array_map(static function (array $row) {
            return [
                $row['symbol'],
                $row['last_close'],
                $row['last_date'],
                $row['entry_price'],
                $row['distance_pct'],
                $row['swing_week'],
                $row['volume_ema'],
                $row['volume_target'],
                $row['note'],
            ];
        }, $rows));

        $this->displayBacktestData($backtestSummarySymbols, $backtestTradeSymbols);

        return Command::SUCCESS;
    }

    /**
     * Determine the next actionable swing high and associated volume filters.
     *
     * @param array<int, array<string, mixed>> $weeklyData
     * @param array<int, array<string, mixed>> $signals
     * @return array{
     *     entry_price: float|null,
     *     week_end: string|null,
     *     volume_ema: float|null,
     *     volume_target: float|null,
     *     note: string|null
     * }
     */
    private function forecastNextEntry(array $weeklyData, array $signals): array
    {
        if ($weeklyData === []) {
            return [
                'entry_price' => null,
                'week_end' => null,
                'volume_ema' => null,
                'volume_target' => null,
                'note' => 'Not enough weekly data.',
            ];
        }

        $lastSignalIndex = null;
        if ($signals !== []) {
            $lastSignal = end($signals);
            $lastSignalIndex = is_array($lastSignal) ? (int) $lastSignal['week_index'] : null;
            reset($signals);
        }

        for ($i = count($weeklyData) - 1; $i >= 0; $i--) {
            $week = $weeklyData[$i];
            $isSwingHigh = ! empty($week['is_swing_high']);
            if (! $isSwingHigh) {
                continue;
            }

            if ($lastSignalIndex !== null && $i <= $lastSignalIndex) {
                break;
            }

            $volumeEma = isset($week['volume_ema']) ? (float) $week['volume_ema'] : null;

            return [
                'entry_price' => (float) $week['high'],
                'week_end' => $week['end_date']->toDateString(),
                'volume_ema' => $volumeEma,
                'volume_target' => $volumeEma !== null ? $volumeEma * 1.2 : null,
                'note' => null,
            ];
        }

        if ($lastSignalIndex !== null && isset($weeklyData[$lastSignalIndex])) {
            $signalWeek = $weeklyData[$lastSignalIndex];
            $volumeEma = isset($signalWeek['volume_ema']) ? (float) $signalWeek['volume_ema'] : null;

            return [
                'entry_price' => null,
                'week_end' => $signalWeek['end_date']->toDateString(),
                'volume_ema' => $volumeEma,
                'volume_target' => $volumeEma !== null ? $volumeEma * 1.2 : null,
                'note' => 'Latest swing high already triggered. Await new setup.',
            ];
        }

        return [
            'entry_price' => null,
            'week_end' => null,
            'volume_ema' => null,
            'volume_target' => null,
            'note' => 'No swing highs detected.',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function resolveTickers(): array
    {
        $raw = $this->option('sym');
        if (is_string($raw)) {
            $raw = [$raw];
        } elseif (! is_array($raw)) {
            $raw = [];
        }

        $values = [];
        foreach ($raw as $value) {
            if (is_string($value) && trim($value) !== '') {
                $values = array_merge($values, explode(',', $value));
            }
        }

        $values = array_map(static fn ($ticker) => strtoupper(trim((string) $ticker)), $values);
        $values = array_filter($values, static fn ($ticker) => $ticker !== '');

        return array_values(array_unique($values));
    }

    /**
     * @return array<int, string>
     */
    private function resolveAllTickers(): array
    {
        return Asset::query()
            ->whereHas('prices')
            ->orderBy('symbol')
            ->pluck('symbol')
            ->map(static fn ($symbol) => strtoupper((string) $symbol))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function resolveSymbolListOption(string $name): array
    {
        $raw = $this->option($name);

        if ($raw === null || $raw === false) {
            return [];
        }

        if (is_string($raw)) {
            $raw = [$raw];
        } elseif ($raw === true) {
            $raw = [];
        } elseif (! is_array($raw)) {
            $raw = [];
        }

        $values = [];
        foreach ($raw as $value) {
            if (is_string($value) && trim($value) !== '') {
                $values = array_merge($values, explode(',', $value));
            }
        }

        $values = array_map(static fn ($ticker) => strtoupper(trim((string) $ticker)), $values);
        $values = array_filter($values, static fn ($ticker) => $ticker !== '');

        return array_values(array_unique($values));
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function loadBars(string $symbol): ?array
    {
        $asset = Asset::where('symbol', $symbol)->first();
        if (! $asset) {
            $this->warn("Asset {$symbol} not found. Skipping.");
            return null;
        }

        $prices = $asset->prices()->orderBy('date')->get(['date', 'open', 'high', 'low', 'close', 'volume']);
        if ($prices->isEmpty()) {
            $this->warn("No price data available for {$symbol}. Skipping.");
            return null;
        }

        return $prices->map(static function ($price) {
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

    private function displayBacktestData(array $summarySymbols, array $tradeSymbols): void
    {
        $requestedSymbols = array_values(array_unique(array_merge($summarySymbols, $tradeSymbols)));
        if ($requestedSymbols === []) {
            return;
        }

        $dataBySymbol = [];
        foreach ($requestedSymbols as $symbol) {
            $backtest = $this->findLatestBacktestForSymbol($symbol);
            if ($backtest === null) {
                $this->warn("No backtest data found for {$symbol}.");
                continue;
            }

            $trades = $this->loadBacktestTrades($backtest['run_id'], $symbol);

            $dataBySymbol[$symbol] = [
                'backtest' => $backtest['model'],
                'trades' => $trades,
            ];
        }

        foreach ($requestedSymbols as $symbol) {
            if (! isset($dataBySymbol[$symbol])) {
                continue;
            }

            $backtest = $dataBySymbol[$symbol]['backtest'];
            $trades = $dataBySymbol[$symbol]['trades'];

            if (in_array($symbol, $summarySymbols, true)) {
                $this->line('');
                $this->info("Backtest Summary for {$symbol}");
                $this->line('Run ID: ' . $backtest->run_id);
                if ($backtest->created_at) {
                    $this->line('Created: ' . $backtest->created_at->toDateTimeString());
                }

                $this->table(['Metric', 'Value'], $this->formatBacktestStats($backtest->stats_json ?? []));
            }

            if (in_array($symbol, $tradeSymbols, true)) {
                $this->line('');
                $this->info("Backtest Trades for {$symbol}");

                if ($trades === []) {
                    $this->line('No trades recorded for this backtest run.');
                    continue;
                }

                $rows = [];
                foreach ($trades as $index => $trade) {
                    $rows[] = [
                        $index + 1,
                        $trade->entry_date?->toDateString() ?? '—',
                        $trade->exit_date?->toDateString() ?? '—',
                        sprintf('%.4f', (float) $trade->entry_px),
                        sprintf('%.4f', (float) ($trade->exit_px ?? 0.0)),
                        sprintf('%.0f', (float) $trade->units),
                        sprintf('%.2f', (float) ($trade->pnl ?? 0.0)),
                    ];
                }

                $this->table(
                    ['#', 'Entry Date', 'Exit Date', 'Entry Price', 'Exit Price', 'Units', 'PnL'],
                    $rows
                );
            }
        }
    }

    /**
     * @return array<int, array{0:string,1:string}>
     */
    private function formatBacktestStats(array $stats): array
    {
        $formatNumber = static function ($value, int $decimals = 2): string {
            if ($value === null) {
                return '—';
            }

            return number_format((float) $value, $decimals);
        };

        return [
            ['Initial Capital', $formatNumber($stats['initial_capital'] ?? null)],
            ['Final Equity', $formatNumber($stats['final_equity'] ?? null)],
            ['Total Return %', $formatNumber($stats['total_return_pct'] ?? null)],
            ['CAGR %', $formatNumber($stats['CAGR_pct'] ?? null)],
            ['Max Drawdown %', $formatNumber($stats['max_drawdown_pct'] ?? null)],
            ['Trades', isset($stats['num_trades']) ? (string) $stats['num_trades'] : '—'],
            ['Win Rate %', $formatNumber($stats['win_rate_pct'] ?? null)],
            ['Avg Win %', $formatNumber($stats['avg_win_pct'] ?? null)],
            ['Avg Loss %', $formatNumber($stats['avg_loss_pct'] ?? null)],
            ['Profit Factor', $stats['profit_factor'] === null
                ? '—'
                : $formatNumber($stats['profit_factor'])],
        ];
    }

    /**
     * @return array{run_id:string, model:Backtest}|null
     */
    private function findLatestBacktestForSymbol(string $symbol): ?array
    {
        $backtest = Backtest::query()
            ->select('backtests.*')
            ->join('backtest_trades', 'backtest_trades.run_id', '=', 'backtests.run_id')
            ->join('assets', 'assets.id', '=', 'backtest_trades.asset_id')
            ->where('assets.symbol', $symbol)
            ->orderByDesc('backtests.created_at')
            ->orderByDesc('backtest_trades.entry_date')
            ->first();

        if (! $backtest) {
            $backtest = Backtest::query()
                ->whereJsonContains('params_json->symbols', $symbol)
                ->orderByDesc('created_at')
                ->first();
        }

        if (! $backtest) {
            return null;
        }

        return [
            'run_id' => $backtest->run_id,
            'model' => $backtest,
        ];
    }

    /**
     * @return array<int, BacktestTrade>
     */
    private function loadBacktestTrades(string $runId, string $symbol): array
    {
        return BacktestTrade::query()
            ->where('run_id', $runId)
            ->whereHas('asset', static function ($query) use ($symbol) {
                $query->where('symbol', $symbol);
            })
            ->orderBy('entry_date')
            ->get()
            ->all();
    }
}
