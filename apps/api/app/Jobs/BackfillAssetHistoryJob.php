<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Services\Automation\StockbitTokenHealth;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Fetch an asset's whole price history, once, after it is first tracked.
 *
 * The daily collector asks for exactly one session -- it passes --from and
 * --to pinned to the market date -- so a symbol added today would otherwise
 * accumulate one bar an evening and take a year to become usable. ROC 13w
 * needs 66 bars and the 55-week high needs 275.
 *
 * `stockbit:scrape --historical` with no --from is the path that already
 * exists for this: it syncs the profile, reads the IPO date off it, and walks
 * from there a year at a time. That is how the assets this installation
 * already holds were built, so a symbol added through the index panel ends up
 * with the same history rather than a shorter one of its own kind.
 *
 * Queued because it is minutes of API calls, not milliseconds, and an HTTP
 * request must not hold that open.
 */
class BackfillAssetHistoryJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Retried, unlike the scheduled-task job, because the common failure is a
     * dead Stockbit token that the hourly renewal fixes. Re-running is safe:
     * the scraper merges into the seed CSV and upserts only the dates the
     * database is missing, so a second pass over the same range writes
     * nothing new.
     */
    public int $tries = 3;

    public int $timeout = 7200;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [600, 1800];
    }

    public function __construct(public readonly string $symbol) {}

    /**
     * One backfill per symbol in flight, however many times it is added.
     */
    public function uniqueId(): string
    {
        return strtoupper(trim($this->symbol));
    }

    public function uniqueFor(): int
    {
        return 7200;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // The database queue hands a job to another worker once retry_after
        // elapses, whether or not the first one is still running, so a long
        // backfill can be delivered twice. This blocks the copy and puts it
        // back rather than running two scrapes of one symbol at once.
        return [(new WithoutOverlapping($this->uniqueId()))->releaseAfter(300)->expireAfter($this->timeout)];
    }

    public function handle(StockbitTokenHealth $tokenHealth): void
    {
        $symbol = strtoupper(trim($this->symbol));

        // The asset can be gone by the time this runs -- added and removed
        // again, or never created because the track request was refused.
        if (! Asset::query()->where('symbol', $symbol)->exists()) {
            return;
        }

        $preflight = $tokenHealth->preflight();

        if (! $preflight['ok']) {
            // Not a failure: the hourly renewal exists precisely for this, and
            // starting a long scrape on a dead token spends the attempt
            // discovering it. Come back when the token has been replaced.
            Log::info('Backfill deferred: Stockbit token not usable.', [
                'symbol' => $symbol,
                'reason' => $preflight['reason'],
            ]);

            $this->release(900);

            return;
        }

        // The same lock the scheduled bulk scrapes take, so a backfill cannot
        // run alongside the 18:00 collectors and halve everyone's throughput.
        $lock = Cache::lock(
            'automation:stockbit-bulk',
            max(60, (int) config('automation.locks.stockbit_seconds', 7200)),
        );

        if (! $lock->get()) {
            Log::info('Backfill deferred: another bulk Stockbit job holds the lock.', ['symbol' => $symbol]);

            $this->release(600);

            return;
        }

        $output = new BufferedOutput;

        try {
            // No --from on purpose: that is what makes the scraper sync the
            // profile, read the IPO date off it and start there.
            $exitCode = Artisan::call('stockbit:scrape', [
                'tickers' => [$symbol],
                '--historical' => true,
            ], $output);

            Log::info('Backfilled price history.', [
                'symbol' => $symbol,
                'exit_code' => $exitCode,
                'bars' => Asset::query()->where('symbol', $symbol)->first()?->prices()->count(),
            ]);
        } finally {
            try {
                $lock->release();
            } catch (\Throwable) {
                // A lock whose TTL expired mid-run cannot be released, and
                // failing the backfill over it would be worse than the leak.
            }
        }
    }
}
