<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Asset;
use App\Models\Price;
use App\Services\Reconciliation\ReconciliationMirror;
use App\Services\Reconciliation\ReconciliationService;
use App\Services\Reconciliation\ReconciliationStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The commit marker.
 *
 * A remote manifest that names a hash no remote document has would send a
 * disaster recovery down a path it cannot finish, at the one moment there is
 * nothing else to fall back on. These assert that it cannot happen.
 */
class ReconciliationMirrorTest extends TestCase
{
    use RefreshDatabase;

    private string $seedDir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('gdrive');

        $this->seedDir = sys_get_temp_dir().'/breakout-mirror-'.bin2hex(random_bytes(4));
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

    private function seedAssets(string ...$symbols): void
    {
        foreach ($symbols as $symbol) {
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
        }

        DB::table('trading_days')->insertOrIgnore(['date' => '2026-09-01']);

        app(ReconciliationService::class)->run();
    }

    private function mirror(): ReconciliationMirror
    {
        $mirror = app(ReconciliationMirror::class);
        $mirror->setSleeper(static fn () => null);

        return $mirror;
    }

    public function test_it_uploads_documents_then_the_manifest(): void
    {
        $this->seedAssets('AAA', 'BBB');

        $result = $this->mirror()->push();

        $this->assertSame(['AAA', 'BBB'], $result['assets']['uploaded']);
        $this->assertSame('published', $result['manifest']['status']);
        $this->assertFalse($result['degraded']);

        $store = app(ReconciliationStore::class);

        Storage::disk('gdrive')->assertExists($store->assetPath('AAA'));
        Storage::disk('gdrive')->assertExists($store->assetPath('BBB'));
        Storage::disk('gdrive')->assertExists($store->manifestPath());
    }

    /**
     * The rule the whole ordering exists for.
     */
    public function test_a_failed_asset_upload_withholds_the_manifest(): void
    {
        $this->seedAssets('AAA', 'BBB');

        $store = app(ReconciliationStore::class);

        // BBB's document disappears between generation and upload -- the
        // shape a half-finished deploy or a cleaned scratch volume leaves
        // behind. Named explicitly, because a push given no symbols reads the
        // directory and would simply not see it.
        Storage::disk('local')->delete($store->assetPath('BBB'));

        $result = $this->mirror()->push(['AAA', 'BBB']);

        $this->assertArrayHasKey('BBB', $result['assets']['failed']);
        $this->assertTrue($result['degraded']);
        $this->assertSame('withheld', $result['manifest']['status']);

        Storage::disk('gdrive')->assertMissing($store->manifestPath());
    }

    /**
     * A previously published manifest must survive a later partial failure.
     */
    public function test_a_partial_failure_leaves_the_previous_remote_manifest_standing(): void
    {
        $this->seedAssets('AAA');
        $this->mirror()->push();

        $store = app(ReconciliationStore::class);
        $publishedManifest = Storage::disk('gdrive')->get($store->manifestPath());

        // A second asset arrives, and its document goes missing before upload.
        $asset = Asset::create(['symbol' => 'BBB', 'name' => 'Asset BBB', 'sync_price' => true, 'sync_broker_summary' => false]);
        Price::create(['asset_id' => $asset->id, 'date' => '2026-09-01', 'open' => 1, 'high' => 2, 'low' => 1, 'close' => 2, 'volume' => 10]);
        app(ReconciliationService::class)->run();
        Storage::disk('local')->delete($store->assetPath('BBB'));

        $result = $this->mirror()->push(['AAA', 'BBB']);

        $this->assertTrue($result['degraded']);
        $this->assertSame(
            $publishedManifest,
            Storage::disk('gdrive')->get($store->manifestPath()),
            'The remote manifest advanced despite a failed asset upload.',
        );
    }

    public function test_a_drive_failure_leaves_the_local_reconciliation_intact(): void
    {
        $this->seedAssets('AAA');

        config(['reconciliation.mirror_disk' => 'nope-not-a-disk']);

        $store = app(ReconciliationStore::class);
        $localHash = $store->hashOf($store->assetPath('AAA'));

        $result = $this->mirror()->push();

        $this->assertTrue($result['degraded']);
        $this->assertSame($localHash, $store->hashOf($store->assetPath('AAA')));
        $this->assertNotNull($store->readAsset('AAA'));
    }

    public function test_an_unchanged_document_is_not_re_uploaded(): void
    {
        $this->seedAssets('AAA');

        $this->mirror()->push();
        $second = $this->mirror()->push();

        $this->assertSame([], $second['assets']['uploaded']);
        $this->assertSame(['AAA'], $second['assets']['skipped']);
        $this->assertSame('unchanged', $second['manifest']['status']);
    }

    public function test_remote_state_reports_in_sync_only_after_comparing_hashes(): void
    {
        $this->seedAssets('AAA');

        $before = $this->mirror()->remoteState();

        $this->assertFalse($before['manifest_present']);
        $this->assertFalse($before['in_sync'], 'An unpublished manifest must never read as in sync.');

        $this->mirror()->push();
        $after = $this->mirror()->remoteState();

        $this->assertTrue($after['manifest_present']);
        $this->assertTrue($after['in_sync']);
        $this->assertSame($after['local_manifest_hash'], $after['manifest_hash']);
    }

    public function test_an_unreachable_disk_is_not_reported_as_synchronised(): void
    {
        $this->seedAssets('AAA');
        config(['reconciliation.mirror_disk' => 'nope-not-a-disk']);

        $state = $this->mirror()->remoteState();

        $this->assertFalse($state['reachable']);
        $this->assertFalse($state['in_sync']);
        $this->assertNotNull($state['message']);
    }
}
