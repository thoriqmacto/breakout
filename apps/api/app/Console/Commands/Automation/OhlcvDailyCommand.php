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
 *
 * **It also recovers sessions it missed.** The job used to fetch exactly the
 * target date and nothing else, so a Stockbit token that died on Wednesday and
 * was renewed on Friday left Wednesday and Thursday missing for good: Friday's
 * run asked for Friday. The trading calendar kept advancing meanwhile -- it is
 * built from Yahoo, which needs no Stockbit token -- so it already knew which
 * sessions had been skipped. It was simply never asked.
 *
 * So before fetching, each ticker is checked against the last
 * `--backfill-sessions` trading days the calendar records, and any it holds no
 * bar for widen that ticker's range back to the earliest one. A window rather
 * than a "latest bar" cursor, on purpose: a cursor goes blind the moment the
 * newest day lands, so a manual `--date=` run that filled today after an
 * outage would hide the days before it permanently. Checked against a window,
 * a hole is found on whichever run comes next, in whatever order they ran.
 *
 * This costs almost nothing, which is why the window is generous where the
 * broker-summary job's is not. There, each session is its own request. Here a
 * range is one historical request per ticker that returns every daily bar in
 * it, so recovering a fortnight costs what fetching today costs.
 *
 * A ticker with no bars at all is not backfilled here. That is a new asset,
 * and its history is BackfillAssetHistoryJob's to fetch -- from its IPO, not
 * from the edge of a window.
 */
class OhlcvDailyCommand extends Command
{
    protected $signature = 'automation:ohlcv-daily
        {--date= : Trading date to fetch (YYYY-MM-DD, default: today in Asia/Jakarta)}
        {--tickers=* : Limit the run to specific tickers}
        {--backfill-sessions=20 : How many trading sessions before the target date are checked for missing bars and recovered. 0 fetches the target date only}
        {--no-mirror : Skip the Google Drive mirror for this run}
        {--disk= : Mirror disk override for the seed CSVs}
        {--skip-token-check : Do not preflight the Stockbit token (the scheduler has already done it)}';

    protected $description = 'Fetch and persist one trading day of OHLCV for every price-synced asset, recovering any recent sessions it missed.';

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

        $plan = $this->plan($calendar, $tickers, $date);

        if ($plan['backfill_sessions'] !== []) {
            $this->line(sprintf(
                '  Recovering %d missed session(s) for %d ticker(s): %s',
                count($plan['backfill_sessions']),
                count($plan['backfilled']),
                implode(', ', $plan['backfill_sessions']),
            ));
        }

        $exitCode = self::SUCCESS;

        // One scrape per distinct start date. In the steady state every
        // ticker starts at the target date, so this is the single one-day
        // request it always was; after an outage it is still one request per
        // ticker, just over a wider range.
        foreach ($plan['groups'] as $from => $groupTickers) {
            $result = $this->scrape($groupTickers, Carbon::parse($from, $calendar->timezone()), $date);

            if ($result !== self::SUCCESS && $exitCode === self::SUCCESS) {
                $exitCode = $result;
            }
        }

        $outcome = $this->verifyPersistence($tickers, $date);
        $unrecovered = $this->unrecoveredSessions($plan['due']);

        $metadata->merge([
            'success_ticker_count' => count($outcome['persisted']),
            'failed_ticker_count' => count($outcome['missing']),
            'failed_tickers' => array_slice($outcome['missing'], 0, self::MAX_REPORTED_FAILURES),
            'backfill_window_sessions' => (int) $this->option('backfill-sessions'),
            'backfilled_ticker_count' => count($plan['backfilled']),
            'backfill_sessions' => $plan['backfill_sessions'],
            'backfill_unrecovered_count' => count($unrecovered),
            'backfill_unrecovered' => array_slice($unrecovered, 0, self::MAX_REPORTED_FAILURES),
            'duration_seconds' => round(microtime(true) - $startedAt, 2),
            // The scrape mirrored its own touched CSVs; the runner must not
            // mirror them a second time.
            'mirror_handled' => true,
            // The target date only. A recovered session that is still empty
            // came back from the same request that did deliver today's bar,
            // so it is a day that ticker did not trade -- a suspension, say --
            // rather than a fetch that failed. Counting those here would mark
            // every run partial for weeks after one suspended stock.
            'partial' => $outcome['missing'] !== [],
        ]);

        if ($unrecovered !== []) {
            $this->line(sprintf(
                '<fg=gray>  %d earlier session(s) still have no bar after recovery, usually a day the ticker did not trade: %s</>',
                count($unrecovered),
                implode(', ', array_slice($unrecovered, 0, 20)),
            ));
        }

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
     * Delegate to the existing scraper for one range, ending at the target
     * date. The range is a single day unless this ticker group has sessions
     * to recover.
     *
     * --no-profile-sync matters: the profile is slow-moving reference data and
     * re-fetching it for every ticker every afternoon is a large number of
     * calls that change nothing.
     *
     * --no-seeder-sync matters for a different reason. It does not stop the
     * profile fetch that an asset with no profile JSON still triggers -- the
     * scraper needs its IPO date to pick a range -- it stops that profile being
     * written back into database/seeders/data/profiles, which is version
     * controlled. This command runs on the deployed box, where the deploy does
     * `git reset --hard <sha>`, so such a write is discarded at the next deploy
     * even where it is permitted; where it is not, it used to abort the run on
     * the first newly added ticker. The file belongs in a development checkout,
     * committed, which the run now says at the end.
     *
     * @param  array<int, string>  $tickers
     */
    private function scrape(array $tickers, Carbon $from, Carbon $date): int
    {
        $parameters = [
            'tickers' => $tickers,
            '--historical' => true,
            '--from' => $from->toDateString(),
            '--to' => $date->toDateString(),
            '--no-profile-sync' => true,
            '--no-seeder-sync' => true,
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
     * Work out, per ticker, which recent sessions it is missing, and where
     * its fetch therefore has to start.
     *
     * Only sessions on or after a ticker's first stored bar count as missing:
     * an asset listed last week has no bars for the month before its IPO, and
     * treating those as gaps would re-request them every night forever.
     *
     * @param  array<int, string>  $tickers
     * @return array{
     *     groups: array<string, array<int, string>>,
     *     due: array<string, array<int, string>>,
     *     backfilled: array<int, string>,
     *     backfill_sessions: array<int, string>
     * }
     */
    private function plan(TradingWeekResolver $calendar, array $tickers, Carbon $date): array
    {
        $target = $date->toDateString();
        $window = $this->backfillWindow($calendar, $date);
        $held = $window === [] ? [] : $this->heldBars($tickers, $window[0], $target);
        $firstBar = $window === [] ? [] : $this->firstBars($tickers);

        $groups = [];
        $due = [];
        $backfilled = [];
        $sessions = [];

        foreach ($tickers as $ticker) {
            $missed = [];
            $first = $firstBar[$ticker] ?? null;

            // No bars at all is a new asset, whose history is fetched from its
            // IPO by BackfillAssetHistoryJob rather than from here.
            if ($first !== null) {
                foreach ($window as $session) {
                    if ($session >= $first && ! isset($held[$ticker][$session])) {
                        $missed[] = $session;
                    }
                }
            }

            $from = $missed === [] ? $target : $missed[0];

            if ($missed !== []) {
                $backfilled[] = $ticker;
                array_push($sessions, ...$missed);
            }

            $groups[$from][] = $ticker;
            $due[$ticker] = $missed;
        }

        ksort($groups);

        $sessions = array_values(array_unique($sessions));
        sort($sessions);

        return [
            'groups' => $groups,
            'due' => $due,
            'backfilled' => $backfilled,
            'backfill_sessions' => $sessions,
        ];
    }

    /**
     * The trading sessions before the target date that are checked for holes,
     * oldest first, as date strings.
     *
     * Taken from the calendar, so a weekend or an IDX holiday inside the
     * window is not mistaken for a missed session.
     *
     * @return array<int, string>
     */
    private function backfillWindow(TradingWeekResolver $calendar, Carbon $date): array
    {
        $size = max(0, (int) $this->option('backfill-sessions'));

        if ($size === 0) {
            return [];
        }

        // Calendar days to search: generous enough that `$size` sessions fit
        // even across a long holiday closure, and bounded so a stale calendar
        // cannot turn this into a scan of years.
        $span = $size * 2 + 14;

        $sessions = $calendar->tradingDaysBetween(
            $date->copy()->subDays($span),
            $date->copy()->subDay(),
        );

        return array_map(
            static fn (Carbon $day): string => $day->toDateString(),
            array_slice($sessions, -$size),
        );
    }

    /**
     * Which (ticker, date) pairs already hold a bar within a range.
     *
     * @param  array<int, string>  $tickers
     * @return array<string, array<string, true>>
     */
    private function heldBars(array $tickers, string $from, string $to): array
    {
        $assetIds = Asset::query()->whereIn('symbol', $tickers)->pluck('id', 'symbol')->all();

        if ($assetIds === []) {
            return [];
        }

        $symbols = array_flip(array_map('intval', $assetIds));
        $held = [];

        Price::query()
            ->whereIn('asset_id', array_values($assetIds))
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->get(['asset_id', 'date'])
            ->each(function (Price $price) use ($symbols, &$held): void {
                $symbol = $symbols[(int) $price->asset_id] ?? null;

                if ($symbol !== null) {
                    $held[$symbol][Carbon::parse($price->date)->toDateString()] = true;
                }
            });

        return $held;
    }

    /**
     * The earliest stored bar for each ticker, as a date string.
     *
     * @param  array<int, string>  $tickers
     * @return array<string, string>
     */
    private function firstBars(array $tickers): array
    {
        $assetIds = Asset::query()->whereIn('symbol', $tickers)->pluck('id', 'symbol')->all();

        if ($assetIds === []) {
            return [];
        }

        $rows = Price::query()
            ->whereIn('asset_id', array_values($assetIds))
            ->selectRaw('asset_id, MIN(date) as first_date')
            ->groupBy('asset_id')
            ->pluck('first_date', 'asset_id')
            ->all();

        $first = [];

        foreach ($assetIds as $symbol => $assetId) {
            $value = $rows[$assetId] ?? null;

            if ($value !== null && $value !== '') {
                $first[$symbol] = Carbon::parse((string) $value)->toDateString();
            }
        }

        return $first;
    }

    /**
     * The sessions a ticker was due that still have no bar, as "TICKER date".
     *
     * @param  array<string, array<int, string>>  $due
     * @return array<int, string>
     */
    private function unrecoveredSessions(array $due): array
    {
        $due = array_filter($due);

        if ($due === []) {
            return [];
        }

        $dates = array_merge(...array_values($due));
        $held = $this->heldBars(array_keys($due), min($dates), max($dates));
        $unrecovered = [];

        foreach ($due as $ticker => $sessions) {
            foreach ($sessions as $session) {
                if (! isset($held[$ticker][$session])) {
                    $unrecovered[] = "{$ticker} {$session}";
                }
            }
        }

        return $unrecovered;
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
