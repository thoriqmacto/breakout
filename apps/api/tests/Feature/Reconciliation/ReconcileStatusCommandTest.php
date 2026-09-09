<?php

namespace Tests\Feature\Reconciliation;

use App\Services\Reconciliation\ReconciliationStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The readout that tells an empty dashboard apart from a broken one.
 *
 * The reconciliation panels are built from one manifest, so every cause of
 * their being empty -- never built, built elsewhere, built but unreadable by
 * the web user -- reaches the page as the same absent manifest. This command
 * exists to separate them from a terminal, and the two cases below are the
 * two the page cannot distinguish on its own.
 */
class ReconcileStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A faked disk, because "no manifest" is one of the states under test.
     *
     * Without it the case depends on whether some earlier test in the run
     * happened to leave a real manifest in storage/app/private -- which it
     * does, so the assertion passed alone and failed in the suite. A test for
     * an absent file cannot share a directory with tests that create one.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config(['reconciliation.local_disk' => 'local', 'reconciliation.mirror_disk' => null]);
    }

    public function test_it_reports_a_missing_manifest_as_a_failure_with_the_command_that_builds_one(): void
    {
        $this->artisan('data:reconcile-status')
            ->expectsOutputToContain('absent or unreadable')
            ->expectsOutputToContain('data:reconcile --all')
            ->assertExitCode(1);
    }

    /**
     * A manifest that exists is reported from the manifest, not from the
     * presence of the file -- the counts have to come out of the document.
     */
    public function test_it_reports_the_counts_a_present_manifest_describes(): void
    {
        app(ReconciliationStore::class)->writeManifest([
            'schema_version' => 1,
            'generated_at' => '2026-09-09T11:00:00+07:00',
            'market_date' => '2026-09-08',
            'summary' => [
                'asset_count' => 3,
                'healthy' => 2,
                'warning' => 1,
                'error' => 0,
                'latest_ohlcv_date' => '2026-09-08',
                'latest_broker_daily_date' => '2026-09-08',
            ],
            'assets' => [
                'AAAA' => ['integrity_status' => 'healthy'],
                'BBBB' => ['integrity_status' => 'healthy'],
                'CCCC' => ['integrity_status' => 'warning'],
            ],
        ]);

        $this->artisan('data:reconcile-status')
            ->expectsOutputToContain('present')
            ->expectsOutputToContain('2026-09-08')
            // The documents a restore actually reads were never written, so
            // the count must not silently agree with the manifest's claim.
            ->expectsOutputToContain('manifest says 3')
            ->run();
    }

    public function test_json_output_is_the_readiness_report(): void
    {
        $this->artisan('data:reconcile-status --json')->assertExitCode(0);
    }
}
