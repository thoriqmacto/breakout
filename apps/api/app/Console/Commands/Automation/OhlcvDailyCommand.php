<?php

namespace App\Console\Commands\Automation;

use App\Models\Asset;
use App\Models\Price;
use App\Services\Automation\RunMetadata;
use App\Services\Automation\StockbitTokenHealth;
use App\Services\Automation\TradingWeekResolver;
use App\Support\AssetList;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

/**
 * The daily OHLCV update, run at 16:00 WIB on every valid IDX trading day.
 *
 * This is orchestration, not a second scraper. It resolves the market date and
 * the ticker list, hands both to the existing `stockbit:scrape --historical`
 * with a one-day range, and then reports what actually landed. The scraping,
 * the CSV writing, the DB upsert and the Drive mirror are all the paths that
 * already existed and are already exercised by manual runs.
 *
 * What it adds is honesty about the result. `stockbit:scrape` continues past a
 * ticker that errors, which is right for a bulk job, but it means an exit code
 * of 0 does not mean 412 tickers were updated. So afterwards this asks the
 * database which tickers actually have a bar for the requested date, and
 * reports the ones that do not by name.
 */
class OhlcvDailyCommand extends Command
{
    protected $signature = 'automation:ohlcv-daily
        {--date= : Trading date to fetch (YYYY-MM-DD, default: today in Asia/Jakarta)}
        {--tickers=* : Limit the run to specific tickers}
        {--no-mirror : Skip the Google Drive mirror for this run}
        {--disk= : Mirror disk override for the seed CSVs}
        {--skip-token-check : Do not preflight the Stockbit token (the scheduler has already done it)}';

    protected $description = 'Fetch and persist one trading day of OHLCV for every price-synced asset.';

    /**
     * Failures listed by name on the run record. Beyond this the count still
     * tells the whole story, and a metadata blob does not need 400 symbols.
     */
    private const MAX_REPORTED_FAILURES = 50;

    public function handle(
        TradingWeekResolver $calendar,
        StockbitTokenHealth $tokenHealth,
        RunMetadata $metadata,
    ): int {
        $startedAt = microtime(true);

        $date = $this->resolveDate($calendar);

        if ($date === null) {
            return self::INVALID;
        }

        $metadata->merge([
            'job' => 'ohlcv_daily',
            'market_date' => $date->toDateString(),
            'timezone' => $calendar->timezone(),
        ]);

        // The scheduler preflights before it takes the shared Stockbit lock,
        // so this is for manual invocations -- but it must exist, or running
        // this by hand on a dead token spends an hour discovering it.
        if (! $this->option('skip-token-check')) {
            $preflight = $tokenHealth->preflight();

            if (! $preflight['ok']) {
                $metadata->merge([
                    'blocked_token' => true,
                    'skip_reason' => $preflight['reason'],
                    'error_summary' => $preflight['message'],
                ]);

                $this->error((string) $preflight['message']);

                return self::FAILURE;
            }
        }

        $day = $calendar->describeDay($date);

        if (! $day['known']) {
            $this->warn(sprintf(
                'The trading calendar has no row for %s. Build it with "php artisan trading-calendar:build" before relying on this schedule.',
                $date->toDateString(),
            ));
        } elseif (! $day['is_trading_day']) {
            // The scheduler's condition normally means this is never reached.
            // A manual run on a holiday should still not call Stockbit.
            $metadata->merge(['skipped' => true, 'skip_reason' => 'not_trading_day']);
            $this->warn(sprintf('%s is not an IDX trading day; nothing to fetch.', $date->toDateString()));

            return self::SUCCESS;
        }

        $tickers = $this->resolveTickers();

        if ($tickers === []) {
            $metadata->merge(['skipped' => true, 'skip_reason' => 'no_price_sync_assets', 'ticker_count' => 0]);
            $this->warn('No assets have sync_price enabled, so there is nothing to update.');

            return self::SUCCESS;
        }

        $metadata->set('ticker_count', count($tickers));

        $this->info(sprintf(
            'Fetching %s daily bars for %d ticker(s) [%s].',
            $date->toDateString(),
            count($tickers),
            $calendar->timezone(),
        ));

        $exitCode = $this->scrape($tickers, $date);

        $outcome = $this->verifyPersistence($tickers, $date);

        $metadata->merge([
            'success_ticker_count' => count($outcome['persisted']),
            'failed_ticker_count' => count($outcome['missing']),
            'failed_tickers' => array_slice($outcome['missing'], 0, self::MAX_REPORTED_FAILURES),
            'duration_seconds' => round(microtime(true) - $startedAt, 2),
            // The scrape mirrored its own touched CSVs; the runner must not
            // mirror them a second time.
            'mirror_handled' => true,
            'partial' => $outcome['missing'] !== [],
        ]);

        if ($outcome['missing'] !== []) {
            $metadata->set('error_summary', sprintf(
                'No %s bar was persisted for %d of %d ticker(s): %s.',
                $date->toDateString(),
                count($outcome['missing']),
                count($tickers),
                implode(', ', array_slice($outcome['missing'], 0, 20)),
            ));

            $this->warn(sprintf(
                '%d of %d ticker(s) have no %s bar after the scrape: %s',
                count($outcome['missing']),
                count($tickers),
                $date->toDateString(),
                implode(', ', array_slice($outcome['missing'], 0, 20)),
            ));
        }

        $this->info(sprintf(
            '%d of %d ticker(s) now hold a %s bar.',
            count($outcome['persisted']),
            count($tickers),
            $date->toDateString(),
        ));

        if ($exitCode !== self::SUCCESS) {
            return $exitCode;
        }

        // Every ticker failing is not a partial success, it is a failed run --
        // usually the API refusing the whole batch.
        return $outcome['persisted'] === [] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Delegate to the existing scraper for exactly one day.
     *
     * --no-profile-sync matters: the profile is slow-moving reference data and
     * re-fetching it for every ticker every afternoon is a large number of
     * calls that change nothing.
     *
     * @param  array<int, string>  $tickers
     */
    private function scrape(array $tickers, Carbon $date): int
    {
        $parameters = [
            'tickers' => $tickers,
            '--historical' => true,
            '--from' => $date->toDateString(),
            '--to' => $date->toDateString(),
            '--no-profile-sync' => true,
        ];

        $disk = $this->option('disk');

        if (is_string($disk) && $disk !== '') {
            $parameters['--disk'] = $disk;
        }

        if ($this->option('no-mirror')) {
            // The scraper resolves its mirror disk from configuration when
            // --disk is blank, so the only way to turn mirroring off for one
            // run is to remove the configured default for its duration.
            $original = Config::get('csv.mirror_disk');
            Config::set('csv.mirror_disk', null);

            try {
                return Artisan::call('stockbit:scrape', $parameters, $this->getOutput());
            } finally {
                Config::set('csv.mirror_disk', $original);
            }
        }

        return Artisan::call('stockbit:scrape', $parameters, $this->getOutput());
    }

    /**
     * Which of the requested tickers actually hold a bar for the date.
     *
     * Asking the database is the only measure that cannot be fooled: an API
     * that returns 200 with an empty result, a ticker that was suspended, and
     * a transport error all end the same way -- no bar -- and all three
     * deserve to be reported rather than counted as done.
     *
     * @param  array<int, string>  $tickers
     * @return array{persisted: array<int, string>, missing: array<int, string>}
     */
    private function verifyPersistence(array $tickers, Carbon $date): array
    {
        $assetIds = Asset::query()
            ->whereIn('symbol', $tickers)
            ->pluck('id', 'symbol')
            ->all();

        $withBar = Price::query()
            ->whereIn('asset_id', array_values($assetIds))
            ->whereDate('date', $date->toDateString())
            ->pluck('asset_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->flip();

        $persisted = [];
        $missing = [];

        foreach ($tickers as $ticker) {
            $assetId = $assetIds[$ticker] ?? null;

            if ($assetId !== null && $withBar->has((int) $assetId)) {
                $persisted[] = $ticker;

                continue;
            }

            $missing[] = $ticker;
        }

        return ['persisted' => $persisted, 'missing' => $missing];
    }

    private function resolveDate(TradingWeekResolver $calendar): ?Carbon
    {
        $option = $this->option('date');

        if (! is_string($option) || trim($option) === '') {
            // "Today" is a market question, so it is asked in Jakarta rather
            // than of the server clock, which runs in UTC and is seven hours
            // behind: at 16:00 WIB it is still yesterday there.
            return $calendar->today();
        }

        try {
            return Carbon::parse(trim($option), $calendar->timezone())->startOfDay();
        } catch (\Throwable) {
            $this->error('--date must be a YYYY-MM-DD date.');

            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    private function resolveTickers(): array
    {
        /** @var array<int, string> $option */
        $option = $this->option('tickers') ?: [];

        $tickers = $option !== []
            ? $option
            // sync_price is the existing per-asset switch for price updates.
            // Honouring it here is what keeps an asset the operator muted from
            // quietly coming back every afternoon.
            : AssetList::symbols(true);

        $normalized = [];

        foreach ($tickers as $ticker) {
            $symbol = strtoupper(trim((string) $ticker));

            if ($symbol !== '') {
                $normalized[$symbol] = $symbol;
            }
        }

        $normalized = array_values($normalized);
        sort($normalized);

        return $normalized;
    }
}
