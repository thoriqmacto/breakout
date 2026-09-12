<?php

namespace Tests\Feature\Indexes;

use App\Jobs\BackfillAssetHistoryJob;
use App\Models\Asset;
use App\Services\Automation\StockbitTokenHealth;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertNull(self::$calls[0]['to']);
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
