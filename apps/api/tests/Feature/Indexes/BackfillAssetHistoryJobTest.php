<?php

namespace Tests\Feature\Indexes;

use App\Jobs\BackfillAssetHistoryJob;
use App\Models\Asset;
use App\Models\TradingCalendarDay;
use App\Services\Automation\StockbitTokenHealth;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The backfill has to ask for the whole history, and has to know when not to.
 *
 * The first is the entire point: the daily collector pins --from and --to to
 * one session, so only a call that omits --from reaches the scraper's IPO-date
 * default. A backfill that quietly passed a date would leave every new symbol
 * with one bar and nothing on screen would say so.
 *
 * The second is what keeps it from being a liability: a dead token or a
 * collector already running means come back later, not fail.
 */
class BackfillAssetHistoryJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Calls to the stand-in scraper, one array of inputs per invocation.
     *
     * @var array<int, array<string, mixed>>
     */
    public static array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::$calls = [];

        // A stand-in for stockbit:scrape, registered under the same name so
        // the job's Artisan::call reaches it. Running the real one would make
        // network calls to Stockbit.
        $this->app[Kernel::class]->registerCommand(new class extends Command
        {
            protected $signature = 'stockbit:scrape
                {tickers?*}
                {--all}
                {--market-detector}
                {--historical}
                {--from=}
                {--to=}
                {--no-persist}
                {--no-profile-sync}
                {--no-seeder-sync}
                {--eod}
                {--watchlist-id=}
                {--overwrite}
                {--token=}
                {--disk=}';

            public function handle(): int
            {
                BackfillAssetHistoryJobTest::$calls[] = [
                    'tickers' => $this->argument('tickers'),
                    'historical' => (bool) $this->option('historical'),
                    'no_seeder_sync' => (bool) $this->option('no-seeder-sync'),
                    'from' => $this->option('from'),
                    'to' => $this->option('to'),
                ];

                return self::SUCCESS;
            }
        });
    }

    private function tokenHealth(bool $ok): StockbitTokenHealth
    {
        $health = Mockery::mock(StockbitTokenHealth::class);
        $health->shouldReceive('preflight')->andReturn(
            $ok
                ? ['ok' => true, 'status' => [], 'reason' => null, 'message' => 'fine']
                : ['ok' => false, 'status' => [], 'reason' => 'token_expired', 'message' => 'expired'],
        );

        return $health;
    }

    /**
     * @return array{0: BackfillAssetHistoryJob, 1: MockInterface}
     */
    private function job(string $symbol): array
    {
        $job = new BackfillAssetHistoryJob($symbol);

        // A real queue job stands behind release(), so asserting on it is how
        // "come back later" is distinguished from "did nothing".
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldIgnoreMissing();
        $job->setJob($queueJob);

        return [$job, $queueJob];
    }

    public function test_it_asks_for_the_whole_history_rather_than_one_session(): void
    {
        Asset::create(['symbol' => 'CUAN', 'name' => 'CUAN']);

        [$job] = $this->job('cuan');
        $job->handle($this->tokenHealth(true));

        $this->assertCount(1, self::$calls);
        $this->assertSame(['CUAN'], self::$calls[0]['tickers']);
        $this->assertTrue(self::$calls[0]['historical']);
        // The one assertion that matters: no --from is what makes the scraper
        // sync the profile and start at the IPO date.
        $this->assertNull(self::$calls[0]['from']);
        // No calendar rows here, so --to falls back to the scraper's default
        // rather than the run giving up. See the tests below for the end date.
        $this->assertNull(self::$calls[0]['to']);
        // That profile must not be written back into the version-controlled
        // seeder directory: a symbol added here never has one committed yet,
        // so this is the call that would always attempt it, and on a deployed
        // box the failed write used to fail the whole backfill.
        $this->assertTrue(self::$calls[0]['no_seeder_sync']);
    }

    private function tradingDay(string $date, bool $traded = true): void
    {
        TradingCalendarDay::create([
            'date' => $date,
            'is_trading_day' => $traded,
            'is_weekend' => false,
            'is_holiday' => ! $traded,
        ]);
    }

    /**
     * The end date is the last session the calendar confirms, not the clock.
     *
     * Added at 11:00 WIB on Thursday, before Thursday is confirmed, the
     * history ends at Wednesday. Left to "now", Thursday's half-session bar
     * would be stored -- and if the evening collector then missed the ticker,
     * it would stay: the nightly recovery looks for sessions with no bar, and
     * that one has a bar.
     */
    public function test_history_ends_at_the_last_confirmed_trading_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 11:00', 'Asia/Jakarta'));
        config(['automation.timezone' => 'Asia/Jakarta']);

        try {
            $this->tradingDay('2026-08-25');
            $this->tradingDay('2026-08-26');

            Asset::create(['symbol' => 'CUAN', 'name' => 'CUAN']);

            [$job] = $this->job('CUAN');
            $job->handle($this->tokenHealth(true));

            $this->assertNull(self::$calls[0]['from'], 'Still from the IPO date.');
            $this->assertSame('2026-08-26', self::$calls[0]['to']);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * After the close, once the day is confirmed, the history includes it.
     */
    public function test_a_confirmed_today_is_included(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 19:00', 'Asia/Jakarta'));
        config(['automation.timezone' => 'Asia/Jakarta']);

        try {
            $this->tradingDay('2026-08-26');
            $this->tradingDay('2026-08-27');

            Asset::create(['symbol' => 'CUAN', 'name' => 'CUAN']);

            [$job] = $this->job('CUAN');
            $job->handle($this->tokenHealth(true));

            $this->assertSame('2026-08-27', self::$calls[0]['to']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_a_dead_token_defers_the_backfill_instead_of_spending_it(): void
    {
        Asset::create(['symbol' => 'CUAN', 'name' => 'CUAN']);

        [$job, $queueJob] = $this->job('CUAN');
        $queueJob->shouldReceive('release')->once();

        $job->handle($this->tokenHealth(false));

        $this->assertSame([], self::$calls);
    }

    public function test_it_waits_rather_than_competing_with_a_running_collector(): void
    {
        Asset::create(['symbol' => 'CUAN', 'name' => 'CUAN']);

        $held = Cache::lock('automation:stockbit-bulk', 60);
        $this->assertTrue($held->get());

        [$job, $queueJob] = $this->job('CUAN');
        $queueJob->shouldReceive('release')->once();

        try {
            $job->handle($this->tokenHealth(true));
        } finally {
            $held->release();
        }

        $this->assertSame([], self::$calls);
    }

    public function test_the_lock_is_released_for_the_next_job(): void
    {
        Asset::create(['symbol' => 'CUAN', 'name' => 'CUAN']);

        [$job] = $this->job('CUAN');
        $job->handle($this->tokenHealth(true));

        $next = Cache::lock('automation:stockbit-bulk', 60);

        $this->assertTrue($next->get(), 'the shared lock must not be left held after a backfill');

        $next->release();
    }

    public function test_a_symbol_that_no_longer_exists_is_skipped(): void
    {
        [$job] = $this->job('GONE');
        $job->handle($this->tokenHealth(true));

        $this->assertSame([], self::$calls);
    }
}
