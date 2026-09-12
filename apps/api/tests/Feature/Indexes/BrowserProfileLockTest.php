<?php

namespace Tests\Feature\Indexes;

use App\Services\Indexes\IndexCatalogReader;
use App\Services\Indexes\IndexCatalogReadException;
use App\Support\BrowserProfileLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two jobs, one profile directory.
 *
 * Chromium holds a profile exclusively, so the hourly token renewal and the
 * daily catalogue read cannot both be in it. Without the lock the second one
 * to launch fails on a SingletonLock error that reads like a broken install;
 * with it, one waits and the other says plainly that the profile was busy.
 *
 * The important half is that the reader checks the lock *before* it starts a
 * browser: a run that has already lost the race should cost nothing.
 */
class BrowserProfileLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_read_refuses_early_when_the_profile_is_busy(): void
    {
        config()->set('market_indexes.browser.profile_dir', '/tmp/does-not-need-to-exist');
        // The wait is what the operator tunes; the test only needs the branch.
        config()->set('market_indexes.browser.profile_wait_seconds', 0);

        $held = BrowserProfileLock::make(60);

        $this->assertTrue($held->get());

        $startedAt = microtime(true);

        try {
            app(IndexCatalogReader::class)->read('https://example.test/indeks/test70');

            $this->fail('the reader should have refused while the profile was held');
        } catch (IndexCatalogReadException $exception) {
            $this->assertSame(IndexCatalogReadException::PROFILE_BUSY, $exception->reason);
        } finally {
            $held->release();
        }

        // No browser was launched: the whole attempt cost the wait window and
        // nothing else. Generous bound -- the point is "did not run a page
        // load", not a benchmark.
        $this->assertLessThan(5, microtime(true) - $startedAt);
    }

    public function test_a_busy_profile_is_transient_rather_than_something_to_fix(): void
    {
        $exception = new IndexCatalogReadException(IndexCatalogReadException::PROFILE_BUSY, 'busy');

        // It must not raise a warning on the dashboard: the next run gets it,
        // and an alert nobody needs to act on trains people to ignore alerts.
        $this->assertFalse($exception->needsAttention());
    }

    public function test_the_lock_is_released_for_the_other_job(): void
    {
        $lock = BrowserProfileLock::make(60);

        $this->assertTrue(BrowserProfileLock::acquire($lock, 0));

        BrowserProfileLock::release($lock);

        $next = BrowserProfileLock::make(60);

        $this->assertTrue($next->get(), 'the renewal must be able to take the profile after a read');

        $next->release();
    }

    public function test_releasing_a_lost_lock_is_not_an_error(): void
    {
        // A lock whose TTL expired mid-run cannot be released, and failing the
        // work over that would be worse than the leak it prevents.
        BrowserProfileLock::release(null);

        $lock = BrowserProfileLock::make(60);
        $lock->get();
        $lock->release();

        BrowserProfileLock::release($lock);

        $this->assertTrue(true);
    }
}
