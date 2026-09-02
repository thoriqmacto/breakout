<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Asset;
use App\Models\Price;
use App\Models\ScheduledTask;
use App\Services\Automation\RunMetadata;
use App\Services\Reconciliation\ReconciliationStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The nightly pass, and the ordering it depends on.
 */
class DataReconciliationAutomationTest extends TestCase
{
    use RefreshDatabase;

    private string $seedDir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('gdrive');

        $this->seedDir = sys_get_temp_dir().'/breakout-recon-auto-'.bin2hex(random_bytes(4));
        mkdir($this->seedDir, 0777, true);

        config([
            'csv.seed_dir' => $this->seedDir,
            'reconciliation.local_disk' => 'local',
            'reconciliation.mirror_disk' => 'gdrive',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->seedDir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->seedDir);

        parent::tearDown();
    }

    private function seedAsset(string $symbol = 'AAA'): Asset
    {
        $asset = Asset::create([
            'symbol' => $symbol,
            'name' => 'Asset '.$symbol,
            'sync_price' => true,
            'sync_broker_summary' => false,
        ]);

        Price::create([
            'asset_id' => $asset->id,
            'date' => '2026-09-01',
            'open' => 995,
            'high' => 1005,
            'low' => 990,
            'close' => 1000,
            'volume' => 1_000_000,
        ]);

        DB::table('trading_days')->insertOrIgnore(['date' => '2026-09-01']);

        // An asset with sync_price on and no seed CSV is genuinely an error
        // condition -- OHLCV could not be restored from the archive -- so the
        // fixture supplies one rather than asserting around it.
        file_put_contents(
            $this->seedDir.'/'.$symbol.'.csv',
            "Date,Open,High,Low,Close,Volume\n01/09/2026,995,1005,990,1000,1000000\n",
        );

        return $asset;
    }

    /**
     * Reconciliation must read what the collectors wrote, and must not be
     * something the analysis refresh waits on.
     */
    public function test_it_is_scheduled_between_collection_and_analysis(): void
    {
        $reconciliation = ScheduledTask::where('slug', 'daily-data-reconciliation')->first();

        $this->assertNotNull($reconciliation, 'The reconciliation task was not seeded.');
        $this->assertSame('automation:data-reconciliation', $reconciliation->command);

        $brokerSummary = ScheduledTask::where('slug', 'daily-broker-summary')->firstOrFail();
        $analysis = ScheduledTask::where('slug', 'daily-analysis-refresh')->firstOrFail();

        $this->assertGreaterThan(
            $brokerSummary->priority,
            $reconciliation->priority,
            'Reconciliation must run after broker-summary collection.',
        );
        $this->assertLessThan(
            $analysis->priority,
            $reconciliation->priority,
            'Reconciliation must run before the analysis refresh.',
        );
    }

    public function test_it_records_run_metadata(): void
    {
        $this->seedAsset();

        Artisan::call('automation:data-reconciliation');

        $metadata = app(RunMetadata::class)->all();

        $this->assertSame('data_reconciliation', $metadata['job']);
        $this->assertSame(1, $metadata['assets_checked']);
        $this->assertSame(1, $metadata['assets_changed']);
        $this->assertSame(0, $metadata['assets_failed']);
        $this->assertSame(1, $metadata['assets_healthy']);
        $this->assertSame('2026-09-01', $metadata['latest_ohlcv_date']);
        $this->assertNotNull($metadata['manifest_hash']);
        $this->assertArrayHasKey('duration_seconds', $metadata);

        $this->assertSame('published', $metadata['mirror']['status']);
        $this->assertSame(1, $metadata['mirror']['files_mirrored']);
        $this->assertFalse($metadata['mirror']['degraded']);
    }

    public function test_a_second_run_changes_nothing_and_uploads_nothing(): void
    {
        $this->seedAsset();

        Artisan::call('automation:data-reconciliation');
        Artisan::call('automation:data-reconciliation');

        $metadata = app(RunMetadata::class)->all();

        $this->assertSame(0, $metadata['assets_changed']);
        $this->assertSame(1, $metadata['assets_skipped_unchanged']);
        $this->assertSame(0, $metadata['mirror']['files_mirrored']);
        $this->assertSame(1, $metadata['mirror']['files_unchanged']);
        $this->assertSame('unchanged', $metadata['mirror']['status']);
    }

    public function test_a_mirror_failure_is_reported_as_degraded_and_leaves_local_intact(): void
    {
        $this->seedAsset();

        config(['reconciliation.mirror_disk' => 'nope-not-a-disk']);

        $exit = Artisan::call('automation:data-reconciliation');

        $metadata = app(RunMetadata::class)->all();
        $store = app(ReconciliationStore::class);

        $this->assertSame(1, $exit, 'A degraded mirror must not report success.');
        $this->assertTrue($metadata['partial']);
        $this->assertNotNull($store->readAsset('AAA'), 'The local document was damaged by a mirror failure.');
    }

    public function test_no_mirror_skips_the_upload_without_failing(): void
    {
        $this->seedAsset();

        $exit = Artisan::call('automation:data-reconciliation', ['--no-mirror' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame(['status' => 'skipped'], app(RunMetadata::class)->get('mirror'));
        Storage::disk('gdrive')->assertMissing(app(ReconciliationStore::class)->manifestPath());
    }
}
