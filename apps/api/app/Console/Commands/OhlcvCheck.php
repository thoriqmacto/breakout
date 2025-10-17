<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\CsvBars;
use App\Services\DbBars;
use App\Services\OhlcvIntegrityChecker;
use App\Services\PythonRunner;
use App\Services\StockbitExodusClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Carbon;

class OhlcvCheck extends Command
{
    protected $signature = 'ohlcv:check
        {symbol? : Asset symbol to check}
        {--all : Check all configured index symbols}
        {--from= : Restrict the check to start at this YYYY-MM-DD date}
        {--to= : Restrict the check to end at this YYYY-MM-DD date}
        {--resolve= : Resolve detected issues (supported: extra-bars, missing-days)}
        {--force-delete : Remove unresolved trading days from the calendar when resolving missing days}';

    protected $description = 'Check OHLCV bar integrity for assets.';

    public function __construct(
        private readonly OhlcvIntegrityChecker $checker,
        private readonly StockbitExodusClient $stockbit,
        private readonly PythonRunner $python,
    )
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $from = $this->option('from') ?: null;
        $to = $this->option('to') ?: null;
        $resolve = $this->option('resolve') ?: null;
        $forceDelete = (bool) $this->option('force-delete');

        if ($resolve !== null) {
            $resolve = strtolower((string) $resolve);
            $supported = ['extra-bars', 'missing-days'];
            if (!in_array($resolve, $supported, true)) {
                $this->error(sprintf(
                    'Unknown resolve option "%s". Supported values: %s',
                    $resolve,
                    implode(', ', $supported)
                ));

                return self::INVALID;
            }
        }

        if ($forceDelete && $resolve !== 'missing-days') {
            $this->warn('The --force-delete option is only applicable when resolving missing days.');
        }

        if ($this->option('all')) {
            return $this->checkAllConfiguredSymbols($from, $to, $resolve, $forceDelete);
        }

        $symbol = $this->argument('symbol');
        if ($symbol === null) {
            $this->error('Please provide a symbol or use the --all option.');

            return self::INVALID;
        }

        $result = $this->checkSymbol(strtoupper((string) $symbol), $from, $to, $resolve, $forceDelete);
        if ($result === null) {
            return self::FAILURE;
        }

        return $result ? self::SUCCESS : self::FAILURE;
    }

    private function checkAllConfiguredSymbols(?string $from, ?string $to, ?string $resolve, bool $forceDelete): int
    {
        $symbols = array_map('strtoupper', config('csv.index_symbols', []));
        if ($symbols === []) {
            $this->error('No index symbols configured in csv.index_symbols.');

            return self::FAILURE;
        }

        $hadIssues = false;
        foreach ($symbols as $symbol) {
            $result = $this->checkSymbol($symbol, $from, $to, $resolve, $forceDelete);
            if ($result !== true) {
                $hadIssues = true;
            }
        }

        return $hadIssues ? self::FAILURE : self::SUCCESS;
    }

    private function checkSymbol(string $symbol, ?string $from, ?string $to, ?string $resolve, bool $forceDelete): ?bool
    {
        /** @var Asset|null $asset */
        $asset = Asset::query()->where('symbol', $symbol)->first();

        if ($asset === null) {
            $this->warn(sprintf('Asset not found for symbol: %s', $symbol));

            return null;
        }

        $report = $this->checker->checkAsset($asset, $from, $to);

        if ($resolve === 'extra-bars' && !empty($report['extra_bars'])) {
            $this->newLine();
            $this->info(sprintf('Resolving extra bars for %s...', $symbol));
            $this->resolveExtraBars($asset, $report['extra_bars']);
            $report = $this->checker->checkAsset($asset, $from, $to);
        } elseif ($resolve === 'extra-bars') {
            $this->newLine();
            $this->info(sprintf('No extra bars to resolve for %s.', $symbol));
        }

        if ($resolve === 'missing-days' && !empty($report['missing_trading_days'])) {
            $this->newLine();
            $this->info(sprintf('Resolving missing trading days for %s...', $symbol));
            $this->resolveMissingDays($asset, $report['missing_trading_days'], $forceDelete);
            $report = $this->checker->checkAsset($asset, $from, $to);
        } elseif ($resolve === 'missing-days') {
            $this->newLine();
            $this->info(sprintf('No missing trading days to resolve for %s.', $symbol));
        }

        $this->displayReport($report);

        return (bool) ($report['is_consistent'] ?? false);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function displayReport(array $report): void
    {
        $this->newLine();
        $this->info(sprintf('Asset %s (#%d)', $report['symbol'] ?? 'N/A', $report['asset_id']));
        $this->line(sprintf('  Range: %s → %s', $report['range_start'] ?? '-', $report['range_end'] ?? '-'));
        $this->line(sprintf('  First/Last bar: %s / %s', $report['first_bar'] ?? '-', $report['last_bar'] ?? '-'));
        $this->line(sprintf(
            '  Global coverage: %s → %s',
            $report['global_first_bar'] ?? '-',
            $report['global_last_bar'] ?? '-'
        ));
        $this->line(sprintf(
            '  Rows: %d total (%d unique) vs %d trading days',
            $report['total_rows'] ?? 0,
            $report['unique_rows'] ?? 0,
            $report['expected_trading_days'] ?? 0
        ));
        $this->line(sprintf('  Trading days covered: %d', $report['actual_trading_days'] ?? 0));

        if (!empty($report['missing_trading_days'])) {
            $this->warn(sprintf(
                '  Missing trading days (%d): %s',
                count($report['missing_trading_days']),
                implode(', ', $report['missing_trading_days'])
            ));
        }

        if (!empty($report['extra_bars'])) {
            $this->warn(sprintf(
                '  Extra bars (%d): %s',
                count($report['extra_bars']),
                implode(', ', $report['extra_bars'])
            ));
        }

        if (!empty($report['duplicate_bars'])) {
            $this->warn('  Duplicate bars: ' . implode(', ', $report['duplicate_bars']));
        }

        $status = ($report['is_consistent'] ?? false) ? '<fg=green>PASSED</>' : '<fg=red>FAILED</>';
        $this->line(sprintf('  Consistency: %s', $status));
    }

    private function resolveExtraBars(Asset $asset, array $extraBars): void
    {
        $dates = array_values(array_unique(array_filter(
            $extraBars,
            static fn ($date): bool => is_string($date) && $date !== ''
        )));

        if ($dates === []) {
            $this->info('  No valid extra bar dates found to resolve.');

            return;
        }

        sort($dates);

        $deleted = DB::table('price_bars')
            ->where('asset_id', $asset->id)
            ->whereIn('date', $dates)
            ->delete();

        $this->info(sprintf('  Removed %d bar(s) from database for %s.', $deleted, $asset->symbol));

        $csvDir = (string) config('csv.seed_dir');
        if ($csvDir === '') {
            $this->warn('  CSV seed directory not configured; skipping CSV cleanup.');

            return;
        }

        $csvPath = rtrim($csvDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . strtoupper($asset->symbol) . '.csv';

        if (!is_file($csvPath)) {
            $this->warn(sprintf('  CSV seed file not found for %s at %s; skipping CSV cleanup.', $asset->symbol, $csvPath));

            return;
        }

        $rows = CsvBars::read($csvPath);
        $beforeCount = count($rows);

        foreach ($dates as $date) {
            unset($rows[$date]);
        }

        $removedFromCsv = $beforeCount - count($rows);

        if ($removedFromCsv > 0) {
            CsvBars::write($csvPath, $rows);
            $this->info(sprintf('  Removed %d row(s) from CSV seed file for %s.', $removedFromCsv, $asset->symbol));
        } else {
            $this->warn(sprintf('  No matching rows found in CSV seed file for %s.', $asset->symbol));
        }
    }

    private function resolveMissingDays(Asset $asset, array $missingDays, bool $forceDelete): void
    {
        $dates = array_values(array_unique(array_filter(
            $missingDays,
            static fn ($date): bool => is_string($date) && $date !== ''
        )));

        if ($dates === []) {
            $this->info('  No valid missing trading day dates found to resolve.');

            return;
        }

        sort($dates);

        $period = (string) (config('stockbit.historical.period') ?? 'HS_PERIOD_DAILY');
        if ($period === '') {
            $period = 'HS_PERIOD_DAILY';
        }

        $limit = $this->resolvePositiveInt(config('stockbit.historical.limit')) ?? 1;
        $page = $this->resolvePositiveInt(config('stockbit.historical.page')) ?? 1;

        $bars = [];

        foreach ($dates as $date) {
            $response = $this->stockbit->historicalSummary($asset->symbol, $period, $date, $date, $limit, $page);

            if (isset($response['error'])) {
                $this->warn(sprintf(
                    '  Unable to fetch OHLCV for %s on %s: %s (%s).',
                    $asset->symbol,
                    $date,
                    $response['error'],
                    $response['message'] ?? 'no message'
                ));

                continue;
            }

            $normalized = $this->normalizeHistoricalResponse($response);

            if (!isset($normalized[$date])) {
                $this->warn(sprintf(
                    '  Stockbit did not return OHLCV data for %s on %s.',
                    $asset->symbol,
                    $date
                ));

                $fallback = $this->fetchMissingDayFromPython($asset, $date);

                if ($fallback === null) {
                    $this->warn(sprintf(
                        '  Skipping %s on %s because no fallback data was retrieved.',
                        $asset->symbol,
                        $date
                    ));

                    if ($forceDelete) {
                        $this->forceDeleteTradingDay($date);
                    }

                    continue;
                }

                $bars[$date] = $fallback;

                continue;
            }

            $bars[$date] = $normalized[$date];
        }

        if ($bars === []) {
            $this->warn(sprintf('  No OHLCV data retrieved for %s.', $asset->symbol));

            return;
        }

        $chunkSize = (int) config('csv.chunk_size', 200);
        $dbBars = new DbBars($chunkSize, false);

        foreach ($bars as $date => $ohlcv) {
            $dbBars->add([
                'asset_id' => $asset->id,
                'date' => $date,
                'open' => $ohlcv['open'],
                'high' => $ohlcv['high'],
                'low' => $ohlcv['low'],
                'close' => $ohlcv['close'],
                'volume' => $ohlcv['volume'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $dbBars->flush();

        $this->info(sprintf('  Upserted %d bar(s) into database for %s.', count($bars), $asset->symbol));

        $csvDir = (string) config('csv.seed_dir');
        if ($csvDir === '') {
            $this->warn('  CSV seed directory not configured; skipping CSV update.');

            return;
        }

        $csvPath = rtrim($csvDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . strtoupper($asset->symbol) . '.csv';
        $existing = CsvBars::read($csvPath);

        foreach ($bars as $date => $ohlcv) {
            $existing[$date] = [
                'date' => $date,
                'open' => $this->formatPrice($ohlcv['open'], 0),
                'high' => $this->formatPrice($ohlcv['high'], 0),
                'low' => $this->formatPrice($ohlcv['low'], 0),
                'close' => $this->formatPrice($ohlcv['close'], 0),
                'volume' => $ohlcv['volume'],
            ];
        }

        CsvBars::write($csvPath, $existing);

        $this->info(sprintf('  Upserted %d row(s) into CSV seed file for %s.', count($bars), $asset->symbol));
    }

    private function fetchMissingDayFromPython(Asset $asset, string $date): ?array
    {
        $symbol = strtoupper($asset->symbol);
        $args = [
            '--start=' . $date,
            '--end=' . $date,
            $symbol . '.JK',
        ];

        $result = $this->python->run('get_stocks.py', null, $args);

        if (!$result['ok']) {
            $message = trim($result['stderr'] ?: $result['stdout']);
            $this->warn(sprintf(
                '  Python get_stocks.py failed for %s on %s%s',
                $symbol,
                $date,
                $message !== '' ? sprintf(': %s', $message) : '.'
            ));

            return null;
        }

        $csvPath = resource_path('python/csv/' . $symbol . '_PY.csv');

        if (!is_file($csvPath)) {
            $this->warn(sprintf(
                '  Python fallback CSV not found for %s at %s.',
                $symbol,
                $csvPath
            ));

            return null;
        }

        $rows = CsvBars::read($csvPath);

        if (!isset($rows[$date])) {
            $this->warn(sprintf(
                '  Python fallback did not contain OHLCV data for %s on %s.',
                $symbol,
                $date
            ));

            return null;
        }

        $this->info(sprintf(
            '  Retrieved OHLCV data for %s on %s from python fallback.',
            $symbol,
            $date
        ));

        return [
            'open' => (float) $rows[$date]['open'],
            'high' => (float) $rows[$date]['high'],
            'low' => (float) $rows[$date]['low'],
            'close' => (float) $rows[$date]['close'],
            'volume' => (int) $rows[$date]['volume'],
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, array{open: float, high: float, low: float, close: float, volume: int}>
     */
    private function normalizeHistoricalResponse(array $response): array
    {
        $containers = [];

        if (isset($response['data']) && is_array($response['data'])) {
            $containers[] = $response['data'];
        }

        $containers[] = $response;

        $rows = [];

        foreach ($containers as $container) {
            if (!is_array($container)) {
                continue;
            }

            $items = [];

            if (isset($container['result']) && is_array($container['result'])) {
                $items = $container['result'];
            } elseif (array_is_list($container)) {
                $items = $container;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $date = $this->normalizeHistoricalDate($item['date'] ?? null);
                $open = $this->parseHistoricalPrice($item['open'] ?? null);
                $high = $this->parseHistoricalPrice($item['high'] ?? null);
                $low = $this->parseHistoricalPrice($item['low'] ?? null);
                $close = $this->parseHistoricalPrice($item['close'] ?? null);
                $volume = $this->parseHistoricalVolume($item['volume'] ?? null);

                if ($date === null || $open === null || $high === null || $low === null || $close === null || $volume === null) {
                    continue;
                }

                $rows[$date] = [
                    'open' => $open,
                    'high' => $high,
                    'low' => $low,
                    'close' => $close,
                    'volume' => $volume,
                ];
            }
        }

        ksort($rows);

        return $rows;
    }

    private function normalizeHistoricalDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseHistoricalPrice(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = preg_replace('/[^0-9\-\.]/', '', str_replace(',', '', $value));

        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    private function parseHistoricalVolume(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) round((float) $value);
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = preg_replace('/[^0-9]/', '', $value);

        if ($normalized === '') {
            return null;
        }

        return (int) $normalized;
    }

    private function formatPrice(float $value, int $decimals): string
    {
        return number_format($value, $decimals, '.', '');
    }

    private function resolvePositiveInt(mixed $value): ?int
    {
        if (is_numeric($value)) {
            $int = (int) $value;

            return $int > 0 ? $int : null;
        }

        return null;
    }

    private function forceDeleteTradingDay(string $date): void
    {
        $deleted = DB::table('trading_days')->where('date', $date)->delete();

        if ($deleted > 0) {
            $this->info(sprintf('  Removed trading day %s from database.', $date));
        } else {
            $this->warn(sprintf('  Trading day %s not found in database.', $date));
        }

        $path = database_path('seeders/data/trading_days.php');

        if (!is_file($path)) {
            $this->warn('  Trading day seeder file not found; skipping seeder removal.');

            return;
        }

        try {
            $data = include $path;
        } catch (\Throwable $e) {
            $this->warn('  Unable to load trading day seeder file: ' . $e->getMessage());

            return;
        }

        if (!is_array($data)) {
            $this->warn('  Trading day seeder file did not return an array; skipping seeder removal.');

            return;
        }

        $remaining = [];
        $removed = false;

        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (($row['date'] ?? null) === $date) {
                $removed = true;

                continue;
            }

            $remaining[] = $row;
        }

        if (!$removed) {
            $this->warn(sprintf('  Trading day %s not found in seeder file.', $date));

            return;
        }

        $contents = "<?php\n\nreturn " . $this->exportArray(array_values($remaining)) . ";\n";

        File::ensureDirectoryExists(dirname($path));

        if (File::put($path, $contents) === false) {
            $this->warn('  Failed to update trading day seeder file.');

            return;
        }

        $this->info(sprintf('  Removed trading day %s from seeder file.', $date));
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function exportArray(array $records): string
    {
        $export = var_export($records, true);

        $export = preg_replace('/^([ ]*)array \(/m', '$1[', $export);
        $export = preg_replace('/\)(,?)$/m', ']$1', $export);

        return str_replace('NULL', 'null', (string) $export);
    }
}
