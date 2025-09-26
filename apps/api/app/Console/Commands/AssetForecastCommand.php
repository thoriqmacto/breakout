<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Backtest;
use App\Models\BacktestTrade;
use App\Services\AssetMetrics;
use App\Services\Backtest\HLSLBreakoutBacktestService;
use App\Services\Strategies\HLSLBreakoutStrategy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AssetForecastCommand extends Command
{
    private const DEFAULT_INITIAL_CAPITAL_IDR = 10_000_000.0;

    protected $signature = 'asset:forecast
        {--sym=* : Comma-separated or repeated tickers to analyze}
        {--all : Include every asset with price data}
        {--filter= : Filter results when combined with --all (comma-separated; available: "uptrend", "withAlert")}
        {--sort= : Sort results when combined with --all (format: column,direction)}
        {--bt-result : Include the latest backtest summary for the selected tickers}
        {--trades : Include detailed backtest trades for the selected tickers}
        {--rerun : Re-run backtests for the selected tickers}
        {--strategy=HLSLBreakout : Strategy identifier (currently only HLSLBreakout)}
        {--initial-cap= : Initial capital (IDR) for simulated backtests; defaults to 10,000,000}';

    protected $description = 'Forecast potential entry levels for assets using a breakout strategy.';

    public function __construct(private readonly HLSLBreakoutBacktestService $hlslBacktestService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $filters = $this->normalizeFilters();
        if ($filters === false) {
            return Command::FAILURE;
        }

        $sortConfig = $this->normalizeSortOption();
        if ($sortConfig === false) {
            return Command::FAILURE;
        }

        $initialCapital = $this->resolveInitialCapital();
        if ($initialCapital === false) {
            return Command::FAILURE;
        }

        $tickers = $this->option('all') ? $this->resolveAllTickers() : $this->resolveTickers();
        if ($tickers === []) {
            if ($this->option('all')) {
                $this->error('No assets with price data were found.');
            } else {
                $this->error('At least one ticker must be provided via --sym.');
            }
            return Command::FAILURE;
        }

        $includeBacktestSummary = (bool) $this->option('bt-result');
        $backtestSummarySymbols = $includeBacktestSummary ? $tickers : [];
        $includeBacktestTrades = (bool) $this->option('trades');
        $backtestTradeSymbols = [];
        $forceBacktestRerun = (bool) $this->option('rerun');

        if ($forceBacktestRerun && ! $includeBacktestSummary) {
            $this->error('The --rerun option requires --bt-result.');

            return Command::FAILURE;
        }

        if ($includeBacktestTrades) {
            if (! $includeBacktestSummary) {
                $this->error('The --trades option requires --bt-result.');

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

            $noteOverride = null;
            if ($dailyData === []) {
                $this->warn("{$symbol}: insufficient price history.");

                $lastBar = $bars[array_key_last($bars)];
                $latestClose = (float) $lastBar['close'];
                $latestDateValue = $lastBar['date'];
                $noteOverride = 'Insufficient price history.';
            } else {
                $latestDay = $dailyData[array_key_last($dailyData)];
                $latestClose = (float) $latestDay['close'];
                $latestDateValue = $latestDay['date'];
            }

            $latestDate = $latestDateValue instanceof \DateTimeInterface
                ? $latestDateValue->format('Y-m-d')
                : (string) $latestDateValue;

            $forecast = $this->forecastNextEntry($analysis['weekly'], $analysis['signals']);
            $weeklyAtr = $this->computeWeeklyAtr($analysis['weekly']);

            $entryPrice = $forecast['entry_price'];
            $distancePct = null;
            if ($entryPrice !== null && $latestClose > 0.0) {
                $distancePct = (($entryPrice - $latestClose) / $latestClose) * 100.0;
            }

            if ($filters !== [] && ! $this->passesFilters($filters, $bars, $forecast)) {
                continue;
            }

            $finalEquity = $this->computeBacktestFinalEquity($symbol, $bars, $strategyOption, $initialCapital);

            $noteParts = array_filter([
                $noteOverride,
                $forecast['note'] ?? null,
            ], static fn ($value) => is_string($value) && $value !== '');

            $rows[] = [
                'symbol' => $symbol,
                'last_close' => sprintf('%.0f', $latestClose),
                'last_close_value' => $latestClose,
                'last_date' => $latestDate,
                'entry_price' => $entryPrice !== null ? sprintf('%.0f', $entryPrice) : '—',
                'entry_price_value' => $entryPrice,
                'distance_pct' => $distancePct !== null ? sprintf('%.2f%%', $distancePct) : '—',
                'distance_pct_value' => $distancePct,
                'swing_week' => $forecast['week_end'] ?? '—',
                'swing_week_value' => $forecast['week_end'] ?? null,
                'atr_weekly' => $weeklyAtr !== null ? sprintf('%.0f', $weeklyAtr) : '—',
                'atr_weekly_value' => $weeklyAtr,
                'volume_ema' => $forecast['volume_ema'] !== null
                    ? number_format((float) $forecast['volume_ema'], 0, '.', ',')
                    : '—',
                'volume_ema_value' => $forecast['volume_ema'],
                'volume_target' => $forecast['volume_target'] !== null
                    ? number_format((float) $forecast['volume_target'], 0, '.', ',')
                    : '—',
                'volume_target_value' => $forecast['volume_target'],
                'final_equity' => $finalEquity !== null
                    ? number_format($finalEquity, 0, '.', ',')
                    : '—',
                'final_equity_value' => $finalEquity,
                'note' => $noteParts !== [] ? implode(' ', $noteParts) : '',
            ];
        }

        if ($rows === []) {
            $this->warn('No rows to display.');
            return Command::FAILURE;
        }

        if ($sortConfig !== null) {
            $rows = $this->sortRows($rows, $sortConfig);
        }

        $tableRows = [];
        foreach (array_values($rows) as $index => $row) {
            $tableRows[] = [
                (string) ($index + 1),
                $row['symbol'],
                $row['last_close'],
                $row['last_date'],
                $row['entry_price'],
                $row['distance_pct'],
                $row['swing_week'],
                $row['atr_weekly'],
                $row['volume_ema'],
                $row['volume_target'],
                $row['final_equity'],
                $row['note'],
            ];
        }

        $this->table([
            '#',
            'Ticker',
            'Close',
            'Close Date',
            'Alert',
            'Dist%',
            'Swing Week',
            'ATR',
            'Volume EMA',
            'Volume Target',
            'Final Equity',
            'Note',
        ], $tableRows, 'default');

        $this->line('');
        $this->line(sprintf(
            'Final Equity reflects a %s IDR simulation of the %s strategy.',
            number_format((float) $initialCapital, 0, '.', ','),
            $strategyOption
        ));

        $this->displayBacktestData(
            $backtestSummarySymbols,
            $backtestTradeSymbols,
            $strategyOption,
            $forceBacktestRerun,
            $initialCapital
        );

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

    private function computeWeeklyAtr(array $weeklyData, int $period = 2): ?float
    {
        $count = count($weeklyData);
        if ($count < $period) {
            return null;
        }

        $start = max(0, $count - $period);
        $trueRanges = [];

        for ($i = $start; $i < $count; $i++) {
            $week = $weeklyData[$i];
            $high = (float) ($week['high'] ?? 0.0);
            $low = (float) ($week['low'] ?? 0.0);
            $trueRange = $high - $low;

            if ($i > 0) {
                $prevClose = (float) ($weeklyData[$i - 1]['close'] ?? 0.0);
                $trueRange = max($trueRange, abs($high - $prevClose), abs($low - $prevClose));
            }

            $trueRanges[] = $trueRange;
        }

        if ($trueRanges === []) {
            return null;
        }

        return array_sum($trueRanges) / count($trueRanges);
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
     * @return array<int, string>|false
     */
    private function normalizeFilters(): array|false
    {
        $option = $this->option('filter');
        if ($option === null) {
            return [];
        }

        if (! $this->option('all')) {
            $this->error('The --filter option requires --all.');

            return false;
        }

        $raw = trim((string) $option);
        if ($raw === '') {
            return [];
        }

        $supported = [
            'uptrend' => 'uptrend',
            'withalert' => 'withalert',
        ];
        $displayNames = [
            'uptrend' => 'uptrend',
            'withalert' => 'withAlert',
        ];

        $values = array_filter(array_map(static function (string $value): string {
            return strtolower(trim($value));
        }, explode(',', $raw)), static fn ($value) => $value !== '');

        $normalized = [];
        foreach ($values as $value) {
            if (! array_key_exists($value, $supported)) {
                $this->error(sprintf(
                    'Unsupported filter: %s. Available filters: %s.',
                    $value,
                    implode(', ', array_values($displayNames))
                ));

                return false;
            }

            $normalized[] = $supported[$value];
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array{key:string, type:string, direction:string}|null|false
     */
    private function normalizeSortOption(): array|null|false
    {
        $option = $this->option('sort');
        if ($option === null) {
            return null;
        }

        if (! $this->option('all')) {
            $this->error('The --sort option requires --all.');

            return false;
        }

        $raw = trim((string) $option);
        if ($raw === '') {
            $this->error('Invalid --sort value. Expected format: column,direction.');

            return false;
        }

        $parts = array_map(static fn ($value) => trim((string) $value), explode(',', $raw));
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            $this->error('Invalid --sort value. Expected format: column,direction.');

            return false;
        }

        [$columnInput, $directionInput] = $parts;
        $normalizedColumn = strtolower(preg_replace('/[^a-z0-9]/i', '', $columnInput));
        $direction = strtolower($directionInput);

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $this->error('Invalid sort direction. Use "asc" or "desc".');

            return false;
        }

        $columns = $this->getSortableColumns();
        if (! array_key_exists($normalizedColumn, $columns)) {
            $this->error(sprintf(
                'Unsupported sort column: %s. Available columns: %s.',
                $columnInput,
                implode(', ', array_unique(array_column($columns, 'label')))
            ));

            return false;
        }

        return [
            'key' => $columns[$normalizedColumn]['key'],
            'type' => $columns[$normalizedColumn]['type'],
            'direction' => $direction,
        ];
    }

    private function resolveInitialCapital(): float|false
    {
        $option = $this->option('initial-cap');
        if ($option === null || $option === '') {
            return self::DEFAULT_INITIAL_CAPITAL_IDR;
        }

        if (is_array($option)) {
            $option = end($option) ?: reset($option);
        }

        $raw = str_replace([',', '_', ' '], '', (string) $option);
        if ($raw === '' || ! is_numeric($raw)) {
            $this->error('The --initial-cap option must be a positive number.');

            return false;
        }

        $value = (float) $raw;
        if ($value <= 0.0) {
            $this->error('The --initial-cap option must be a positive number.');

            return false;
        }

        return $value;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array{key:string, type:string, direction:string} $sortConfig
     * @return array<int, array<string, mixed>>
     */
    private function sortRows(array $rows, array $sortConfig): array
    {
        $key = $sortConfig['key'];
        $type = $sortConfig['type'];
        $direction = $sortConfig['direction'];

        usort($rows, function (array $a, array $b) use ($key, $type, $direction): int {
            $aValue = $a[$key] ?? null;
            $bValue = $b[$key] ?? null;

            if ($aValue === $bValue) {
                return 0;
            }

            if ($aValue === null) {
                return 1;
            }

            if ($bValue === null) {
                return -1;
            }

            if ($type === 'numeric') {
                $comparison = (float) $aValue <=> (float) $bValue;
            } else {
                $comparison = strcmp((string) $aValue, (string) $bValue);
            }

            if ($direction === 'desc') {
                $comparison *= -1;
            }

            return $comparison;
        });

        return $rows;
    }

    /**
     * @return array<string, array{key:string, type:string, label:string}>
     */
    private function getSortableColumns(): array
    {
        return [
            'ticker' => ['key' => 'symbol', 'type' => 'string', 'label' => 'Ticker'],
            'symbol' => ['key' => 'symbol', 'type' => 'string', 'label' => 'Ticker'],
            'close' => ['key' => 'last_close_value', 'type' => 'numeric', 'label' => 'Close'],
            'closedate' => ['key' => 'last_date', 'type' => 'string', 'label' => 'Close Date'],
            'alert' => ['key' => 'entry_price_value', 'type' => 'numeric', 'label' => 'Alert'],
            'dist' => ['key' => 'distance_pct_value', 'type' => 'numeric', 'label' => 'Dist%'],
            'distpct' => ['key' => 'distance_pct_value', 'type' => 'numeric', 'label' => 'Dist%'],
            'distpercent' => ['key' => 'distance_pct_value', 'type' => 'numeric', 'label' => 'Dist%'],
            'swingweek' => ['key' => 'swing_week_value', 'type' => 'string', 'label' => 'Swing Week'],
            'atrwk' => ['key' => 'atr_weekly_value', 'type' => 'numeric', 'label' => 'ATR Wk'],
            'atrweek' => ['key' => 'atr_weekly_value', 'type' => 'numeric', 'label' => 'ATR Wk'],
            'atrweekly' => ['key' => 'atr_weekly_value', 'type' => 'numeric', 'label' => 'ATR Wk'],
            'volumeema' => ['key' => 'volume_ema_value', 'type' => 'numeric', 'label' => 'Volume EMA'],
            'volumetarget' => ['key' => 'volume_target_value', 'type' => 'numeric', 'label' => 'Volume Target'],
            'equity' => ['key' => 'final_equity_value', 'type' => 'numeric', 'label' => 'Final Equity'],
            'finalequity' => ['key' => 'final_equity_value', 'type' => 'numeric', 'label' => 'Final Equity'],
        ];
    }

    /**
     * @param array<int, string> $filters
     * @param array<int, array{date:string, open:float, high:float, low:float, close:float, volume?:float}> $bars
     * @param array<string, mixed> $forecast
     */
    private function passesFilters(array $filters, array $bars, array $forecast): bool
    {
        $metrics = null;

        foreach ($filters as $filter) {
            switch ($filter) {
                case 'uptrend':
                    $metrics ??= new AssetMetrics($bars);
                    if (! $metrics->isUptrend()) {
                        return false;
                    }
                    break;
                case 'withalert':
                    if (($forecast['entry_price'] ?? null) === null) {
                        return false;
                    }
                    break;
            }
        }

        return true;
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

    /**
     * @param array<int, array<string, mixed>> $dailyBars
     */
    private function computeBacktestFinalEquity(
        string $symbol,
        array $dailyBars,
        string $strategyOption,
        float $initialCapital
    ): ?float {
        if ($strategyOption !== 'HLSLBreakout') {
            return null;
        }

        $result = $this->hlslBacktestService->run([
            $symbol => $dailyBars,
        ], $initialCapital);

        $stats = $result['stats'] ?? [];
        if (! is_array($stats) || ! array_key_exists('final_equity', $stats)) {
            return null;
        }

        $finalEquity = $stats['final_equity'];

        return is_numeric($finalEquity) ? (float) $finalEquity : null;
    }

    private function displayBacktestData(
        array $summarySymbols,
        array $tradeSymbols,
        string $strategyOption,
        bool $forceRerun,
        float $initialCapital
    ): void
    {
        $requestedSymbols = array_values(array_unique(array_merge($summarySymbols, $tradeSymbols)));
        if ($requestedSymbols === []) {
            return;
        }

        if (count($requestedSymbols) > 1) {
            $this->displayCombinedBacktestData(
                $summarySymbols,
                $tradeSymbols,
                $requestedSymbols,
                $strategyOption,
                $forceRerun,
                $initialCapital
            );

            return;
        }

        $symbol = $requestedSymbols[0];
        if ($forceRerun) {
            $backtest = $this->runAndStoreBacktestForSymbol($symbol, $strategyOption, $initialCapital);
        } else {
            $backtest = $this->findLatestBacktestForSymbol($symbol, $initialCapital, $strategyOption)
                ?? $this->runAndStoreBacktestForSymbol($symbol, $strategyOption, $initialCapital);
        }

        if ($backtest === null) {
            $this->warn("No backtest data found for {$symbol}.");

            return;
        }

        $trades = $this->loadBacktestTrades($backtest['run_id'], $symbol);
        $formatPrice = static fn ($price) => $price !== null
            ? sprintf('%.0f', (float) $price)
            : '—';

        if (in_array($symbol, $summarySymbols, true)) {
            $this->line('');
            $this->info("Backtest Summary for {$symbol}");
            $this->line('Run ID: ' . $backtest['model']->run_id);
            if ($backtest['model']->created_at) {
                $this->line('Created: ' . $backtest['model']->created_at->toDateTimeString());
            }

            $this->table(['Metric', 'Value'], $this->formatBacktestStats($backtest['model']->stats_json ?? []), 'default');
        }

        if (! in_array($symbol, $tradeSymbols, true)) {
            return;
        }

        $this->line('');
        $this->info("Backtest Trades for {$symbol}");

        if ($trades === []) {
            $this->line('No trades recorded for this backtest run.');

            return;
        }

        $rows = [];
        foreach ($trades as $index => $trade) {
            $rows[] = [
                $index + 1,
                $trade->entry_date?->toDateString() ?? '—',
                $trade->exit_date?->toDateString() ?? '—',
                $formatPrice($trade->entry_px),
                $formatPrice($trade->exit_px),
                sprintf('%.0f', (float) $trade->units),
                sprintf('%.0f', (float) ($trade->pnl ?? 0)),
            ];
        }

        $this->table(
            ['#', 'Entry Date', 'Exit Date', 'Entry', 'Exit', 'Units', 'PnL'],
            $rows,
            'default'
        );
    }

    private function displayCombinedBacktestData(
        array $summarySymbols,
        array $tradeSymbols,
        array $requestedSymbols,
        string $strategyOption,
        bool $forceRerun,
        float $initialCapital
    ): void
    {
        if ($forceRerun) {
            $backtest = $this->runAndStoreBacktestForSymbols($requestedSymbols, $strategyOption, $initialCapital);
        } else {
            $backtest = $this->findLatestBacktestCoveringSymbols($requestedSymbols, $strategyOption, $initialCapital)
                ?? $this->runAndStoreBacktestForSymbols($requestedSymbols, $strategyOption, $initialCapital);
        }

        if ($backtest === null) {
            $this->warn('No backtest data found for ' . implode(', ', $requestedSymbols) . '.');

            return;
        }

        $backtestModel = $backtest['model'];
        $params = is_array($backtestModel->params_json) ? $backtestModel->params_json : [];
        $storedSymbols = [];
        if (isset($params['symbols']) && is_array($params['symbols'])) {
            $storedSymbols = array_values(array_unique(array_map(static function ($symbol) {
                return strtoupper(trim((string) $symbol));
            }, $params['symbols'])));
        }

        $runSymbols = $storedSymbols !== [] ? $storedSymbols : $requestedSymbols;
        $uniqueSummarySymbols = array_values(array_unique($summarySymbols));

        if ($uniqueSummarySymbols !== []) {
            $this->line('');
            $this->info('Backtest Summary (Combined)');
            $this->line('Tickers: ' . implode(', ', $runSymbols));
            $this->line('Run ID: ' . $backtestModel->run_id);
            if ($backtestModel->created_at) {
                $this->line('Created: ' . $backtestModel->created_at->toDateTimeString());
            }

            $this->table(['Metric', 'Value'], $this->formatBacktestStats($backtestModel->stats_json ?? []), 'default');
        }

        $uniqueTradeSymbols = array_values(array_unique($tradeSymbols));
        if ($uniqueTradeSymbols === []) {
            return;
        }

        $formatPrice = static fn ($price) => $price !== null
            ? sprintf('%.0f', (float) $price)
            : '—';

        $combinedRows = [];
        $symbolsForTrades = $runSymbols !== []
            ? array_values(array_intersect($runSymbols, $uniqueTradeSymbols))
            : $uniqueTradeSymbols;

        foreach ($symbolsForTrades as $symbol) {
            $trades = $this->loadBacktestTrades($backtest['run_id'], $symbol);
            if ($trades === []) {
                continue;
            }

            foreach ($trades as $trade) {
                $combinedRows[] = [
                    count($combinedRows) + 1,
                    $symbol,
                    $trade->entry_date?->toDateString() ?? '—',
                    $trade->exit_date?->toDateString() ?? '—',
                    $formatPrice($trade->entry_px),
                    $formatPrice($trade->exit_px),
                    sprintf('%.0f', (float) $trade->units),
                    sprintf('%.0f', (float) ($trade->pnl ?? 0)),
                ];
            }
        }

        $missingTradeSymbols = array_values(array_diff($uniqueTradeSymbols, $symbolsForTrades));

        $this->line('');
        $this->info('Backtest Trades');

        if ($combinedRows === []) {
            $this->line('No trades recorded for this backtest run.');

            foreach ($missingTradeSymbols as $missingSymbol) {
                $this->warn($missingSymbol . ': no trade data available in this backtest run.');
            }

            return;
        }

        $this->table(
            ['#', 'Symbol', 'Entry Date', 'Exit Date', 'Entry', 'Exit', 'Units', 'PnL'],
            $combinedRows,
            'default'
        );

        foreach ($missingTradeSymbols as $missingSymbol) {
            $this->warn($missingSymbol . ': no trade data available in this backtest run.');
        }
    }

    /**
     * Attempt to run and persist a backtest for the provided symbol.
     *
     * @return array{run_id:string, model:Backtest}|null
     */
    private function runAndStoreBacktestForSymbol(string $symbol, string $strategyOption, float $initialCapital): ?array
    {
        if ($strategyOption !== 'HLSLBreakout') {
            $this->warn("Automatic backtests are only available for the HLSLBreakout strategy.");
            return null;
        }

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

        $dailyRows = $prices->map(static function ($price) {
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

        $this->line("Generating {$strategyOption} backtest for {$symbol}...");

        $result = $this->hlslBacktestService->run([
            $symbol => $dailyRows,
        ], $initialCapital);
        $stats = $result['stats'] ?? [];
        $trades = $result['trades'] ?? [];

        if ($trades === []) {
            $trades = $this->fallbackTradesFromLatestRun($symbol);
        }

        return DB::transaction(function () use ($symbol, $asset, $stats, $trades, $strategyOption, $initialCapital) {
            $runId = 'auto-hlsl-' . Str::uuid()->toString();

            $backtest = Backtest::create([
                'run_id' => $runId,
                'created_at' => now(),
                'params_json' => [
                    'symbols' => [$symbol],
                    'strategy' => $strategyOption,
                    'generated_by' => 'asset:forecast',
                    'initial_capital' => $initialCapital,
                ],
                'stats_json' => $stats,
                'notes' => 'Auto-generated via asset:forecast --bt-result',
            ]);

            foreach ($trades as $trade) {
                $shares = (float) ($trade['shares'] ?? 0.0);
                $entryPrice = (float) ($trade['entry_price'] ?? 0.0);
                $exitPrice = isset($trade['exit_price']) ? (float) $trade['exit_price'] : null;
                $profit = array_key_exists('pnl', $trade)
                    ? ($trade['pnl'] !== null ? (float) $trade['pnl'] : null)
                    : ($exitPrice !== null ? ($exitPrice - $entryPrice) * $shares : null);

                BacktestTrade::create([
                    'run_id' => $runId,
                    'asset_id' => $asset->id,
                    'entry_date' => $trade['entry_date'] ?? null,
                    'entry_px' => $entryPrice,
                    'exit_date' => $trade['exit_date'] ?? null,
                    'exit_px' => $exitPrice,
                    'units' => $shares,
                    'pnl' => $profit,
                ]);
            }

            return [
                'run_id' => $runId,
                'model' => $backtest,
            ];
        });
    }

    /**
     * @param array<int, string> $symbols
     * @return array{run_id:string, model:Backtest}|null
     */
    private function runAndStoreBacktestForSymbols(array $symbols, string $strategyOption, float $initialCapital): ?array
    {
        if ($strategyOption !== 'HLSLBreakout') {
            $this->warn('Automatic backtests are only available for the HLSLBreakout strategy.');

            return null;
        }

        $normalizedSymbols = array_values(array_unique(array_map(static function ($symbol) {
            return strtoupper(trim((string) $symbol));
        }, $symbols)));

        if ($normalizedSymbols === []) {
            return null;
        }

        $assets = Asset::query()
            ->whereIn('symbol', $normalizedSymbols)
            ->get()
            ->keyBy(static fn ($asset) => strtoupper((string) $asset->symbol));

        $dailyData = [];
        foreach ($normalizedSymbols as $symbol) {
            $asset = $assets->get($symbol);
            if (! $asset) {
                $this->warn("Asset {$symbol} not found. Skipping.");
                continue;
            }

            $prices = $asset->prices()->orderBy('date')->get(['date', 'open', 'high', 'low', 'close', 'volume']);
            if ($prices->isEmpty()) {
                $this->warn("No price data available for {$symbol}. Skipping.");
                continue;
            }

            $dailyData[$symbol] = $prices->map(static function ($price) {
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

        if ($dailyData === []) {
            return null;
        }

        $includedSymbols = array_keys($dailyData);
        $this->line(
            'Generating ' . $strategyOption . ' backtest for ' . implode(', ', $includedSymbols) . '...'
        );

        $result = $this->hlslBacktestService->run($dailyData, $initialCapital);
        $stats = $result['stats'] ?? [];
        $trades = $result['trades'] ?? [];

        if ($trades === []) {
            foreach ($includedSymbols as $symbol) {
                $fallbackTrades = $this->fallbackTradesFromLatestRun($symbol);
                if ($fallbackTrades === []) {
                    continue;
                }

                foreach ($fallbackTrades as $trade) {
                    $trades[] = array_merge($trade, ['ticker' => $symbol]);
                }
            }
        }

        $assetMap = $assets->only($includedSymbols);

        return DB::transaction(function () use ($stats, $trades, $strategyOption, $includedSymbols, $assetMap, $initialCapital) {
            $runId = 'auto-hlsl-' . Str::uuid()->toString();

            $backtest = Backtest::create([
                'run_id' => $runId,
                'created_at' => now(),
                'params_json' => [
                    'symbols' => $includedSymbols,
                    'strategy' => $strategyOption,
                    'generated_by' => 'asset:forecast',
                    'initial_capital' => $initialCapital,
                ],
                'stats_json' => $stats,
                'notes' => 'Auto-generated via asset:forecast --bt-result',
            ]);

            foreach ($trades as $trade) {
                $ticker = strtoupper((string) ($trade['ticker'] ?? ''));
                if (! isset($assetMap[$ticker])) {
                    continue;
                }

                $shares = (float) ($trade['shares'] ?? 0.0);
                $entryPrice = (float) ($trade['entry_price'] ?? 0.0);
                $exitPrice = isset($trade['exit_price']) ? (float) $trade['exit_price'] : null;
                $profit = array_key_exists('pnl', $trade)
                    ? ($trade['pnl'] !== null ? (float) $trade['pnl'] : null)
                    : ($exitPrice !== null ? ($exitPrice - $entryPrice) * $shares : null);

                BacktestTrade::create([
                    'run_id' => $runId,
                    'asset_id' => $assetMap[$ticker]->id,
                    'entry_date' => $trade['entry_date'] ?? null,
                    'entry_px' => $entryPrice,
                    'exit_date' => $trade['exit_date'] ?? null,
                    'exit_px' => $exitPrice,
                    'units' => $shares,
                    'pnl' => $profit,
                ]);
            }

            return [
                'run_id' => $runId,
                'model' => $backtest,
            ];
        });
    }

    /**
     * @param array<int, string> $symbols
     * @return array{run_id:string, model:Backtest}|null
     */
    private function findLatestBacktestCoveringSymbols(
        array $symbols,
        string $strategyOption,
        ?float $initialCapital = null
    ): ?array {
        $normalized = array_values(array_unique(array_map(static function ($symbol) {
            return strtoupper(trim((string) $symbol));
        }, $symbols)));

        if ($normalized === []) {
            return null;
        }

        $query = Backtest::query()
            ->where('params_json->generated_by', 'asset:forecast')
            ->where('params_json->strategy', $strategyOption);

        foreach ($normalized as $symbol) {
            $query->whereJsonContains('params_json->symbols', $symbol);
        }

        $backtest = $query
            ->orderByDesc('created_at')
            ->first();

        if (! $backtest) {
            return null;
        }

        if (! $this->backtestMatchesCriteria($backtest, $initialCapital, $strategyOption)) {
            return null;
        }

        return [
            'run_id' => $backtest->run_id,
            'model' => $backtest,
        ];
    }

    /**
     * Attempt to reuse trades from the most recent backtest when reruns
     * generate no simulated trades. This preserves historical trade context
     * so reports continue to display transactions for the symbol.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fallbackTradesFromLatestRun(string $symbol): array
    {
        $latest = $this->findLatestBacktestForSymbol($symbol);
        if ($latest === null) {
            return [];
        }

        return BacktestTrade::query()
            ->where('run_id', $latest['run_id'])
            ->whereHas('asset', static function ($query) use ($symbol) {
                $query->where('symbol', $symbol);
            })
            ->orderBy('entry_date')
            ->get()
            ->map(static function (BacktestTrade $trade) {
                return [
                    'entry_date' => $trade->entry_date?->toDateString(),
                    'entry_price' => (float) $trade->entry_px,
                    'exit_date' => $trade->exit_date?->toDateString(),
                    'exit_price' => $trade->exit_px !== null ? (float) $trade->exit_px : null,
                    'shares' => (float) $trade->units,
                    'pnl' => $trade->pnl !== null ? (float) $trade->pnl : null,
                ];
            })
            ->all();
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

        $formatProfitFactor = static function (array $values) use ($formatNumber): string {
            if (! array_key_exists('profit_factor', $values)) {
                return '—';
            }

            if ($values['profit_factor'] === null) {
                return '—';
            }

            return $formatNumber($values['profit_factor']);
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
            ['Profit Factor', $formatProfitFactor($stats)],
        ];
    }

    /**
     * @return array{run_id:string, model:Backtest}|null
     */
    private function findLatestBacktestForSymbol(
        string $symbol,
        ?float $initialCapital = null,
        ?string $strategyOption = null
    ): ?array {
        $backtest = Backtest::query()
            ->select('backtests.*')
            ->join('backtest_trades', 'backtest_trades.run_id', '=', 'backtests.run_id')
            ->join('assets', 'assets.id', '=', 'backtest_trades.asset_id')
            ->where('assets.symbol', $symbol)
            ->orderByDesc('backtests.created_at')
            ->orderByDesc('backtest_trades.entry_date')
            ->first();

        if ($backtest && ! $this->backtestMatchesCriteria($backtest, $initialCapital, $strategyOption)) {
            $backtest = null;
        }

        if (! $backtest) {
            $query = Backtest::query()
                ->whereJsonContains('params_json->symbols', $symbol)
                ->orderByDesc('created_at');

            if ($strategyOption !== null) {
                $query->where(function ($inner) use ($strategyOption) {
                    $inner->whereNull('params_json->strategy')
                        ->orWhere('params_json->strategy', $strategyOption);
                });
            }

            $candidate = $query->first();
            if ($candidate && ! $this->backtestMatchesCriteria($candidate, $initialCapital, $strategyOption)) {
                $candidate = null;
            }

            $backtest = $candidate;
        }

        if (! $backtest) {
            return null;
        }

        return [
            'run_id' => $backtest->run_id,
            'model' => $backtest,
        ];
    }

    private function backtestMatchesCriteria(Backtest $backtest, ?float $initialCapital, ?string $strategyOption): bool
    {
        if ($initialCapital !== null && ! $this->initialCapitalMatches($initialCapital, $backtest)) {
            return false;
        }

        if ($strategyOption !== null) {
            $params = is_array($backtest->params_json) ? $backtest->params_json : [];
            $storedStrategy = isset($params['strategy']) ? (string) $params['strategy'] : null;
            if ($storedStrategy !== null && strcasecmp($storedStrategy, $strategyOption) !== 0) {
                return false;
            }
        }

        return true;
    }

    private function initialCapitalMatches(float $expected, Backtest $backtest): bool
    {
        $stored = $this->extractInitialCapitalFromBacktest($backtest);

        return abs($stored - $expected) < 0.0001;
    }

    private function extractInitialCapitalFromBacktest(Backtest $backtest): float
    {
        $params = is_array($backtest->params_json) ? $backtest->params_json : [];
        if (isset($params['initial_capital']) && is_numeric($params['initial_capital'])) {
            return (float) $params['initial_capital'];
        }

        $stats = is_array($backtest->stats_json) ? $backtest->stats_json : [];
        if (isset($stats['initial_capital']) && is_numeric($stats['initial_capital'])) {
            return (float) $stats['initial_capital'];
        }

        return self::DEFAULT_INITIAL_CAPITAL_IDR;
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
