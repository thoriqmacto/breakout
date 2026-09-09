<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Asset;
use App\Models\BrokerSummaryWindow;
use App\Models\Price;
use App\Services\Reconciliation\AssetReconciler;
use App\Services\Reconciliation\ReconciliationReadiness;
use App\Services\Reconciliation\ReconciliationStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The raw archive is looked for on the disk it is actually kept on.
 *
 * `rawExists()` hardcoded `Storage::disk('local')` while every other consumer
 * of this archive -- the importer, the archive mirror, the scrape, the
 * rebuild command -- reads `stockbit.save_disk`. On a deployment with
 * SB_SAVE_DISK=gdrive the files live on Drive and the local directory is
 * empty by design, so the lookup found nothing and reconciliation reported
 * that every broker window of every asset referenced a file lost from the
 * archive: 55 assets, 55 warnings, not one of them true.
 *
 * A false warning on every asset is worse than no warning at all. It puts the
 * recovery layer permanently in "degraded", which is the state an operator
 * stops reading.
 */
class RawArchiveDiskTest extends TestCase
{
    use RefreshDatabase;

    private const FILE = 'broker_summary/AAA_2026-09-08_2026-09-08_TRANSACTION_TYPE_NET.json';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('archive');

        config([
            'stockbit.save_disk' => 'archive',
            'stockbit.save_dir' => 'broker_summary',
            'reconciliation.local_disk' => 'local',
        ]);
    }

    private function assetWithWindow(): Asset
    {
        $asset = Asset::create([
            'symbol' => 'AAA',
            'name' => 'Asset AAA',
            'sector' => 'Energy',
            'sync_price' => true,
            'sync_broker_summary' => true,
        ]);

        Price::create([
            'asset_id' => $asset->id,
            'date' => '2026-09-08',
            'open' => 100, 'high' => 110, 'low' => 95, 'close' => 105,
            'volume' => 1_000_000,
        ]);
        DB::table('trading_days')->insertOrIgnore(['date' => '2026-09-08']);

        BrokerSummaryWindow::create([
            'asset_id' => $asset->id,
            'from_date' => '2026-09-08',
            'to_date' => '2026-09-08',
            'transaction_type' => 'TRANSACTION_TYPE_NET',
            'returned_buyer_count' => 10,
            'returned_seller_count' => 10,
            'total_buyer' => 12,
            'total_seller' => 11,
            'source_filename' => self::FILE,
            'source_hash' => 'abc',
            'imported_at' => now(),
        ]);

        return $asset;
    }

    /**
     * @return array<int, string>
     */
    private function warningsFor(Asset $asset): array
    {
        return app(AssetReconciler::class)->build($asset, 'fingerprint')['integrity']['warnings'];
    }

    public function test_a_file_on_the_configured_archive_disk_is_not_reported_missing(): void
    {
        $asset = $this->assetWithWindow();

        // Present on the archive disk, absent from "local" -- the production
        // arrangement exactly.
        Storage::disk('archive')->put(self::FILE, '{}');

        $this->assertSame([], array_values(array_filter(
            $this->warningsFor($asset),
            static fn (string $warning): bool => str_contains($warning, 'no longer in the archive'),
        )));
    }

    /**
     * The check still has teeth: a genuinely absent file is still reported.
     */
    public function test_a_file_absent_from_the_archive_is_still_reported(): void
    {
        $asset = $this->assetWithWindow();

        Storage::disk('archive')->put('broker_summary/SOMETHING_ELSE.json', '{}');

        $warnings = implode(' ', $this->warningsFor($asset));

        $this->assertStringContainsString('no longer in the archive', $warnings);
    }

    /**
     * An archive that cannot be read at all accuses nobody.
     *
     * Unreachable and empty are different findings and the difference matters:
     * an empty archive is a true report about the data, an unresolvable disk
     * is a report about the configuration, and printing the second as the
     * first is what made 55 assets look corrupted at once.
     */
    public function test_an_unreadable_archive_does_not_report_files_as_missing(): void
    {
        $asset = $this->assetWithWindow();

        config(['stockbit.save_disk' => 'a-disk-that-does-not-exist']);

        $warnings = implode(' ', $this->warningsFor($asset));

        $this->assertStringNotContainsString('no longer in the archive', $warnings);
    }

    /**
     * "Could not check" is recorded, not quietly rendered as "healthy".
     *
     * Suppressing the false accusation was only half the job. An asset whose
     * raw coverage was never verified still reads healthy from its status
     * alone, so the fact that the archive was unreadable has to travel with
     * the document -- otherwise a Drive outage silently turns the check off
     * and the dashboard reports the recovery layer as sound.
     */
    public function test_an_unreadable_archive_is_recorded_as_unchecked(): void
    {
        $asset = $this->assetWithWindow();

        config(['stockbit.save_disk' => 'a-disk-that-does-not-exist']);

        $integrity = app(AssetReconciler::class)->build($asset, 'fingerprint')['integrity'];

        $this->assertFalse($integrity['raw_archive_checked']);
    }

    public function test_a_readable_archive_is_recorded_as_checked(): void
    {
        $asset = $this->assetWithWindow();

        Storage::disk('archive')->put(self::FILE, '{}');

        $integrity = app(AssetReconciler::class)->build($asset, 'fingerprint')['integrity'];

        $this->assertTrue($integrity['raw_archive_checked']);
    }

    /**
     * A file sitting on the local disk does not count when the archive is
     * configured elsewhere -- otherwise the check passes for the wrong reason.
     */
    /**
     * The readiness report says it once, for the whole fleet.
     *
     * One unreadable disk is one fact about the archive, not one fact per
     * asset. Reporting it per asset is exactly how it looked like 55
     * corrupted assets the first time.
     */
    public function test_readiness_warns_once_that_coverage_is_unverified(): void
    {
        app(ReconciliationStore::class)->writeManifest([
            'schema_version' => 1,
            'summary' => [
                'asset_count' => 55,
                'healthy' => 55,
                'warning' => 0,
                'error' => 0,
                'raw_archive_unchecked' => 55,
            ],
            'assets' => [],
        ]);

        $warnings = app(ReconciliationReadiness::class)->report()['readiness']['warnings'];

        $unverified = array_values(array_filter(
            $warnings,
            static fn (string $warning): bool => str_contains($warning, 'unverified rather than confirmed'),
        ));

        $this->assertCount(1, $unverified);
        $this->assertStringContainsString('55 asset(s)', $unverified[0]);
    }

    public function test_the_local_disk_is_not_consulted_when_the_archive_is_elsewhere(): void
    {
        $asset = $this->assetWithWindow();

        Storage::disk('local')->put(self::FILE, '{}');

        $warnings = implode(' ', $this->warningsFor($asset));

        $this->assertStringContainsString('no longer in the archive', $warnings);
    }
}
