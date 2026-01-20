<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Asset;
use App\Services\CsvBars;
use App\Services\SymbolDate;
use App\Services\DbBars;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use App\Services\PythonRunner;
use App\Services\BrokerSummaryImporter;
use App\Support\StockbitTokenStore;
use App\Support\AssetList;
use App\Models\TradingCalendarDay;
use App\Models\TradingDay;
use Illuminate\Support\Facades\Schema;

class AssetSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'asset:sync
        {--check-python : Search python/csv for missing assets}
        {--run-python : Run python get_stocks.py when needed}
        {--import-csv : Import found python csv files into seed folder}
        {--continue : Skip confirmation before latest-data check}
        {--chk-date= : Custom date (YYYY-MM-DD) used for latest comparison}
        {--eod : Confirm all prompts and use today for chk-date}
        {--broker-summary : Fetch broker summary data from Stockbit}
        {--broker-summary-import-only : Skip fetching broker summary data and only import from disk}
        {--broker-summary-date= : Broker summary single date (YYYY-MM-DD)}
        {--broker-summary-from= : Broker summary start date (YYYY-MM-DD)}
        {--broker-summary-to= : Broker summary end date (YYYY-MM-DD)}
        {--broker-summary-tickers=* : Limit broker summary sync to specific tickers}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize historical prices prioritizing Stockbit scrape with python/yfinance as fallback';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info($this->description);

        $checkedYfinance = false;
        $yfinanceReady = null;
        $yfinanceLatestDate = null;
        $stockbitBackfillDays = (int) config('stockbit.backfill_days', 14);
        $eodDate = null;
        $eodTradingDate = null;

        if ($this->option('eod')) {
            $eodDate = Carbon::now()->toDateString();
            $eodTradingDate = $this->resolveLatestTradingDay($eodDate) ?? $eodDate;
            if ($eodTradingDate !== $eodDate) {
                $this->warn("Using {$eodTradingDate} as latest trading day for EOD checks.");
            }
            $this->input->setOption('check-python', true);
            $this->input->setOption('run-python', true);
            $this->input->setOption('import-csv', true);
            $this->input->setOption('continue', true);
            $this->input->setOption('chk-date', $eodTradingDate);
            $this->input->setOption('broker-summary', true);
            if (!$this->option('broker-summary-from')) {
                $this->input->setOption('broker-summary-from', $eodTradingDate);
            }
            if (!$this->option('broker-summary-to')) {
                $this->input->setOption('broker-summary-to', $eodTradingDate);
            }
        }

        $brokerSummaryDate = $this->option('broker-summary-date');
        if ($brokerSummaryDate) {
            $this->input->setOption('broker-summary-from', $brokerSummaryDate);
            $this->input->setOption('broker-summary-to', $brokerSummaryDate);
        }

        $assetSettings = Asset::query()
            ->orderBy('symbol')
            ->get(['id', 'symbol', 'sync_price', 'sync_profile', 'sync_broker_summary'])
            ->keyBy(fn ($asset) => strtoupper((string) $asset->symbol));

        $shouldSyncProfile = static function (string $symbol) use ($assetSettings): bool {
            $asset = $assetSettings->get(strtoupper($symbol));
            if (!$asset) {
                return true;
            }

            return (bool) $asset->sync_profile;
        };

        $indexSymbols = AssetList::symbols(true);
        $seedDir = config('csv.seed_dir');
        $brokerSummaryImportOnly = (bool) $this->option('broker-summary-import-only');
        $brokerSummaryRequested = (bool) $this->option('broker-summary') || $brokerSummaryImportOnly;

        // Collect CSV files available in seed directory
        $seedFiles = glob($seedDir . '/*.csv') ?: [];
        $csvSymbols = array_map(fn($f) => strtoupper(basename($f, '.csv')), $seedFiles);

        // Compare config symbols with available CSV files
        $missing = array_diff($indexSymbols, $csvSymbols);
        if (!empty($missing) && !$this->option('broker-summary')) {
            $this->warn('CSV seed files missing for: ' . implode(', ', $missing));

            $this->info('Attempting to backfill missing CSV from Stockbit first ...');
            $from = Carbon::now()->subDays($stockbitBackfillDays)->toDateString();
            $to = Carbon::now()->toDateString();
            foreach ($missing as $sym) {
                if ($this->fetchWithStockbit($sym, $from, $to, !$shouldSyncProfile($sym))) {
                    $this->info("Stockbit CSV backfill complete for {$sym}.");
                }
            }

            // Refresh missing after Stockbit attempt
            $seedFiles = glob($seedDir . '/*.csv') ?: [];
            $csvSymbols = array_map(fn($f) => strtoupper(basename($f, '.csv')), $seedFiles);
            $missing = array_diff($indexSymbols, $csvSymbols);

            if (!empty($missing)) {
                // Offer to check python csv directory for missing files
                $checkPython = $this->option('check-python') ??
                               $this->confirm('Check python csv folder for these assets?', false);
                if ($checkPython) {
                    $pyDir = resource_path('python/csv');

                    $found     = [];
                    $missingPy = [];
                    foreach ($missing as $sym) {
                        $pyFile = $pyDir . '/' . $sym . '_PY.csv';
                        if (is_file($pyFile)) {
                            $found[$sym] = $pyFile;
                        } else {
                            $missingPy[] = $sym;
                            $this->warn("Python csv not found for {$sym}; Stockbit backfill may be required.");
                        }
                    }

                    if (!empty($missingPy)) {
                        $tickFile = resource_path('python/tickers.txt');

                        // Reset tickers file so it only contains the newly missing symbols
                        $tickers = array_map(fn($sym) => $sym . '.JK', $missingPy);
                        file_put_contents($tickFile, implode(PHP_EOL, $tickers) . PHP_EOL);

                        $this->info('Missing tickers written to tickers.txt: ' . implode(', ', $tickers));

                        $runPython = $this->option('run-python') ??
                                     $this->confirm('Run python get_stocks.py now?', false);
                        if ($runPython) {
                            if ($this->option('eod')) {
                                $this->ensureYfinanceChecked(
                                    $checkedYfinance,
                                    $yfinanceReady,
                                    $yfinanceLatestDate,
                                    (string) ($eodTradingDate ?? $eodDate)
                                );
                                if ($yfinanceReady === false) {
                                    $this->warn('Skipping python download because latest IDX data is not available yet.');
                                    $runPython = false;
                                }
                            }

                            if ($runPython) {
                                $this->call('python:run', ['script' => 'get_stocks.py']);
                            } else {
                                $this->warn('No python code to run.');
                            }
                        }
                    }

                    if (!empty($found)) {
                        $this->info('Found python csv files:');
                        foreach ($found as $sym => $file) {
                            $this->line('- ' . $sym . ': ' . basename($file));
                        }

                        $importCsv = $this->option('import-csv') ??
                                     $this->confirm('Import these csv files into the seed directory?', false);
                        if ($importCsv) {
                            foreach ($found as $sym => $pyFile) {
                                $rows = CsvBars::read($pyFile);
                                if ($rows !== []) {
                                    $dest = $seedDir . '/' . $sym . '.csv';
                                    $existing = CsvBars::read($dest);
                                    $merged = CsvBars::merge($existing, $rows);
                                    CsvBars::write($dest, $merged);
                                    $this->info('Seed file written: ' . basename($dest));
                                } else {
                                    $this->warn("No data in python csv for {$sym}");
                                }
                            }

                            // Re-scan seed directory after attempting to import
                            $seedFiles = glob($seedDir . '/*.csv') ?: [];
                            $csvSymbols = array_map(fn($f) => strtoupper(basename($f, '.csv')), $seedFiles);
                            $missing = array_diff($indexSymbols, $csvSymbols);
                        }
                    }
                }
            } else {
                $this->comment('Stockbit backfill completed missing CSV files.');
            }
        }

        if (!$this->option('continue') &&
            !$this->confirm('Continue checking latest data anyway?', false)
        ) {
            return Command::FAILURE;
        }

        // Upsert missing bars from CSV into DB for each symbol
        $chunk = (int) config('csv.chunk_size', 200);
        $dbBars = new DbBars($chunk, false);

        foreach ($indexSymbols as $symbol) {
            $csvPath = $seedDir . '/' . $symbol . '.csv';
            if (!is_file($csvPath)) {
                $this->info("Attempting to backfill missing CSV for {$symbol} from Stockbit…");
                $from = Carbon::now()->subDays($stockbitBackfillDays)->toDateString();
                $to = Carbon::now()->toDateString();

                if (!$this->fetchWithStockbit($symbol, $from, $to, !$shouldSyncProfile($symbol))) {
                    $this->warn("Skipping {$symbol}; CSV still missing after Stockbit attempt. Python fallback will be used if available.");
                }

                if (!is_file($csvPath)) {
                    $this->warn("Skipping {$symbol}; CSV still missing after Stockbit attempt.");
                    continue;
                }
            }

            // Ensure asset exists in DB
            $asset = Asset::firstOrCreate(
                ['symbol' => $symbol],
                ['name' => $symbol]
            );

            $csvRows = CsvBars::read($csvPath);

            // Existing DB dates for the asset
            $existing = DB::table('price_bars')
                ->where('asset_id', $asset->id)
                ->pluck('date')
                ->all();
            $existing = array_flip($existing);

            foreach ($csvRows as $ymd => $row) {
                if (isset($existing[$ymd])) {
                    continue; // already present
                }

                $dbBars->add([
                    'asset_id'   => $asset->id,
                    'date'       => $ymd,
                    'open'       => $row['open'],
                    'high'       => $row['high'],
                    'low'        => $row['low'],
                    'close'      => $row['close'],
                    'volume'     => $row['volume'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if ($dbBars->inserted()>0) {
            $this->info('Upserted ' . $dbBars->inserted() . ' rows into DB.');
        }

        $dbBars->flush();

        $shouldCheckLatest = !$this->option('continue') || $this->option('eod') || !$this->option('broker-summary');

        if ($shouldCheckLatest) {
            // Ask user for a custom date to check against latest data
            $chkLatest = $this->option('chk-date');
            $showIsLatest = (bool) $chkLatest;
            if (!$chkLatest) {
                $autoChkLatest = $this->resolveLatestTradingDay(Carbon::now()->toDateString());
                if ($autoChkLatest) {
                    $chkLatest = $autoChkLatest;
                    $showIsLatest = true;
                }
            }

            if (
                !$chkLatest &&
                !$this->option('continue') &&
                $this->confirm('Do you have your own chk-date to compare with latest-date data?', false)
            ) {
                $chkLatest = $this->ask('Enter chk-date (YYYY-MM-DD)');
                $showIsLatest = true;
            }

            if ($chkLatest) {
                $resolvedChkLatest = $this->resolveLatestTradingDay($chkLatest);
                if ($resolvedChkLatest && $resolvedChkLatest !== $chkLatest) {
                    $this->warn("Adjusted chk-date to latest trading day: {$resolvedChkLatest}.");
                    $chkLatest = $resolvedChkLatest;
                }
            }

            // After syncing, display latest data from CSV and DB for each symbol
            $rows = [];
            $i = 1;
            foreach ($indexSymbols as $symbol) {
                $csvPath = $seedDir . '/' . $symbol . '.csv';
                $csvRows = CsvBars::read($csvPath);
                $dates   = SymbolDate::latest($symbol, $csvRows, $chkLatest);

                if ($chkLatest && Carbon::parse($dates['latest'])->lt(Carbon::parse($chkLatest))) {
                    $start  = Carbon::parse($dates['latest'])->addDay()->toDateString();
                    $end    = $chkLatest ?: now()->toDateString();
                    $stockbitFetched = $this->fetchWithStockbit($symbol, $start, $end, !$shouldSyncProfile($symbol));
                    $csvRows = CsvBars::read($csvPath);
                    $dates   = SymbolDate::latest($symbol, $csvRows, $chkLatest);

                    if (Carbon::parse($dates['latest'])->lt(Carbon::parse($chkLatest))) {
                        if ($this->option('eod')) {
                            $this->ensureYfinanceChecked(
                                $checkedYfinance,
                                $yfinanceReady,
                                $yfinanceLatestDate,
                                (string) ($eodTradingDate ?? $eodDate)
                            );
                            if ($yfinanceReady === false) {
                                $message = "Skipping download for {$symbol} because latest IDX data is not yet available.";
                                if ($yfinanceLatestDate) {
                                    $message .= ' Last available date: ' . $yfinanceLatestDate . '.';
                                }
                                $this->warn($message);
                                continue;
                            }
                        }

                        $ticker = $symbol . '.JK';

                        $this->call('python:run', [
                            'script' => 'get_stocks.py',
                            '--arg'  => ["--start={$start}", "--end={$end}", $ticker],
                        ]);

                        $pyFile = resource_path('python/csv/' . $symbol . '_PY.csv');
                        if (is_file($pyFile)) {
                            $newRows = CsvBars::read($pyFile);
                            if ($newRows !== []) {
                                $existing = CsvBars::read($csvPath);
                                $merged   = CsvBars::merge($existing, $newRows);
                                CsvBars::write($csvPath, $merged);

                                $asset = Asset::firstOrCreate(['symbol' => $symbol], ['name' => $symbol]);
                                $existingDates = DB::table('price_bars')
                                    ->where('asset_id', $asset->id)
                                    ->pluck('date')
                                    ->all();
                                $existingDates = array_flip($existingDates);

                                $updBars = new DbBars($chunk, false);
                                foreach ($newRows as $ymd => $row) {
                                    if (isset($existingDates[$ymd])) {
                                        continue;
                                    }

                                    $updBars->add([
                                        'asset_id'   => $asset->id,
                                        'date'       => $ymd,
                                        'open'       => $row['open'],
                                        'high'       => $row['high'],
                                        'low'        => $row['low'],
                                        'close'      => $row['close'],
                                        'volume'     => $row['volume'],
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]);
                                }
                                $updBars->flush();

                                $csvRows = $merged;
                                $dates   = SymbolDate::latest($symbol, $csvRows, $chkLatest);
                            }
                        }
                    }
                }

                $row = [
                    $i,
                    $symbol,
                    $dates['csv'] ?? 'n/a',
                    $dates['db'] ?? 'n/a',
                    $dates['total'] ?? 'n/a',
                ];

                if ($showIsLatest) {
                    $row[] = $dates['is_latest'] ?? 'no';
                }

                $rows[] = $row;

                $i++;
            }

            $headers = ['No','Symbol', 'CSV Latest', 'DB Latest', 'Total Bars'];
            if ($showIsLatest) {
                $headers[] = 'Is Latest?';
            }

            $this->table($headers, $rows);
        }

        if ($brokerSummaryImportOnly) {
            try {
                /** @var BrokerSummaryImporter $importer */
                $importer = app(BrokerSummaryImporter::class);
                $disk = config('stockbit.save_disk');
                $jsonDir = config('stockbit.save_dir');
                $summary = $importer->importFromDisk($disk, $jsonDir);

                $fileCount = $summary['file_count'] ?? 0;
                $rowCount = $summary['row_count'] ?? 0;
                $this->line("Imported broker summaries: {$rowCount} rows from {$fileCount} files.");
            } catch (\Throwable $exception) {
                $this->warn('Failed to import broker summaries: ' . $exception->getMessage());
            }

            return Command::SUCCESS;
        }

        if ($brokerSummaryRequested) {
            if (!$brokerSummaryImportOnly) {
                $brokerSummarySymbols = $this->resolveBrokerSummarySymbols(
                    $this->option('broker-summary-tickers') ?? [],
                    $indexSymbols,
                    $assetSettings
                );

                if ($brokerSummarySymbols === []) {
                    $this->warn('No broker summary tickers matched the sync settings.');
                } else {
                    $from = $this->option('broker-summary-from');
                    $to = $this->option('broker-summary-to');
                    [$fromDate, $toDate] = $this->normalizeBrokerSummaryRange($from, $to);

                    if ($fromDate && $toDate) {
                        if ($this->shouldFetchBrokerSummaries($fromDate, $toDate)) {
                            $this->fetchBrokerSummaries($brokerSummarySymbols, $fromDate, $toDate);
                        }
                    } else {
                        $this->warn('Skipping broker summary fetch; invalid date range provided.');
                    }
                }
            }

            try {
                /** @var BrokerSummaryImporter $importer */
                $importer = app(BrokerSummaryImporter::class);
                $disk = config('stockbit.save_disk');
                $jsonDir = config('stockbit.save_dir');
                $summary = $importer->importFromDisk($disk, $jsonDir);

                $fileCount = $summary['file_count'] ?? 0;
                $rowCount = $summary['row_count'] ?? 0;
                $this->line("Imported broker summaries: {$rowCount} rows from {$fileCount} files.");
            } catch (\Throwable $exception) {
                $this->warn('Failed to import broker summaries: ' . $exception->getMessage());
            }
        }

        if (!$this->option('eod') && $this->option('broker-summary')){
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function fetchWithStockbit(string $symbol, string $from, string $to, bool $isNotProfile): bool
    {
        if (!$this->stockbitTokenAvailable()) {
            $this->warn("Skipping Stockbit fetch for {$symbol}; bearer token not configured.");

            return false;
        }

        $result = $this->call('stockbit:scrape', [
            'tickers' => [$symbol],
            '--from' => $from,
            '--to' => $to,
            '--historical' => true,
            '--no-profile-sync' => $isNotProfile,
        ]);

        if ($result !== Command::SUCCESS) {
            $this->warn("Stockbit fetch failed for {$symbol}; falling back to yfinance if available.");

            return false;
        }

        return true;
    }

    /**
     * @param array<int, string> $requested
     * @param array<int, string> $defaults
     * @param \Illuminate\Support\Collection $assetSettings
     * @return array<int, string>
     */
    private function resolveBrokerSummarySymbols(array $requested, array $defaults, $assetSettings): array
    {
        $symbols = $requested !== [] ? $requested : $defaults;
        $symbols = array_values(array_unique(array_map('strtoupper', $symbols)));

        $filtered = [];

        foreach ($symbols as $symbol) {
            $asset = $assetSettings->get($symbol);
            if ($asset && !$asset->sync_broker_summary) {
                $this->line("Skipping broker summary for {$symbol}; sync disabled.");
                continue;
            }

            $filtered[] = $symbol;
        }

        return $filtered;
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function normalizeBrokerSummaryRange(?string $from, ?string $to): array
    {
        $fallback = Carbon::now()->toDateString();
        $from = $from ?: $fallback;
        $to = $to ?: $from;

        try {
            $fromDate = Carbon::parse($from)->toDateString();
            $toDate = Carbon::parse($to)->toDateString();
        } catch (\Throwable) {
            return [null, null];
        }

        if (Carbon::parse($fromDate)->greaterThan(Carbon::parse($toDate))) {
            return [null, null];
        }

        return [$fromDate, $toDate];
    }

    private function shouldFetchBrokerSummaries(string $from, string $to): bool
    {
        $tradingDayCount = $this->countTradingDaysInRange($from, $to);

        if ($tradingDayCount === null) {
            return true;
        }

        if ($tradingDayCount === 0) {
            $range = $from === $to ? $from : "{$from} → {$to}";
            $this->warn("Skipping broker summary fetch; no trading days in range ({$range}).");

            return false;
        }

        return true;
    }

    private function countTradingDaysInRange(string $from, string $to): ?int
    {
        if (Schema::hasTable('trading_days')) {
            return TradingDay::query()
                ->whereBetween('date', [$from, $to])
                ->count();
        }

        return null;
    }

    private function resolveLatestTradingDay(string $date): ?string
    {
        if (Schema::hasTable('trading_calendar')) {
            $row = TradingCalendarDay::query()
                ->where('date', '<=', $date)
                ->where('is_trading_day', true)
                ->orderByDesc('date')
                ->first(['date']);

            return $row?->date?->toDateString();
        }

        if (Schema::hasTable('trading_days')) {
            $row = TradingDay::query()
                ->where('date', '<=', $date)
                ->orderByDesc('date')
                ->first(['date']);

            return $row?->date?->toDateString();
        }

        return null;
    }

    /**
     * @param array<int, string> $symbols
     */
    private function fetchBrokerSummaries(array $symbols, string $from, string $to): void
    {
        if (!$this->stockbitTokenAvailable()) {
            $this->warn('Skipping broker summary fetch; bearer token not configured.');
            return;
        }

        $result = $this->call('stockbit:scrape', [
            'tickers' => $symbols,
            '--from' => $from,
            '--to' => $to,
            '--market-detector' => true,
            '--no-profile-sync' => true,
        ]);

        if ($result !== Command::SUCCESS) {
            $this->warn('Broker summary fetch failed.');
        }
    }

    private function stockbitTokenAvailable(): bool
    {
        try {
            /** @var StockbitTokenStore $store */
            $store = app(StockbitTokenStore::class);
            $bearer = $store->get() ?: config('stockbit.bearer');

            return is_string($bearer) && $bearer !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    private function ensureYfinanceChecked(
        bool &$checked,
        ?bool &$yfinanceReady,
        ?string &$yfinanceLatestDate,
        string $latestDate
    ): void {
        if ($checked) {
            return;
        }

        $checked = true;
        $this->info('Checking latest IDX data availability from python/yfinance fallback ...');

        try {
            /** @var PythonRunner $runner */
            $runner = app(PythonRunner::class);
            $response = $runner->run('get_stocks.py', null, [
                '--check-latest',
                '--latest-symbol=^JKSE',
                "--latest-date={$latestDate}",
            ]);

            if ($response['ok'] && is_array($response['json'])) {
                $yfinanceLatestDate = $response['json']['latest_date'] ?? null;
                $yfinanceReady = (bool) ($response['json']['is_available'] ?? false);

                if ($yfinanceReady) {
                    $this->info('Latest IDX data on yfinance is available.');
                } else {
                    $message = 'Latest IDX data on yfinance is not available yet.';
                    if ($yfinanceLatestDate) {
                        $message .= ' Last available date: ' . $yfinanceLatestDate . '.';
                    }
                    $this->warn($message);
                }
            } else {
                $yfinanceReady = null;
                $this->warn('Unable to determine latest IDX data availability from yfinance.');
            }
        } catch (\Throwable $e) {
            $yfinanceReady = null;
            $this->warn('Failed to check yfinance latest data: ' . $e->getMessage());
        }
    }
}
