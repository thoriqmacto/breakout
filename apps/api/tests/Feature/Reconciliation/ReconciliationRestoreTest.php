<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Asset;
use App\Models\BandarDetectorSummary;
use App\Models\BrokerSummaryEntry;
use App\Models\BrokerSummaryWindow;
use App\Models\Price;
use App\Services\Reconciliation\ReconciliationRestorer;
use App\Services\Reconciliation\ReconciliationService;
use App\Services\Reconciliation\ReconciliationStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The disaster-recovery contract: reconciliation plus code rebuilds the state.
 *
 * The reconstruction is asserted against a database that has been emptied, so
 * a test cannot pass on rows that were never deleted.
 */
class ReconciliationRestoreTest extends TestCase
{
    use RefreshDatabase;

    private string $seedDir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seedDir = sys_get_temp_dir().'/breakout-restore-'.bin2hex(random_bytes(4));
        mkdir($this->seedDir, 0777, true);

        config([
            'csv.seed_dir' => $this->seedDir,
            'reconciliation.local_disk' => 'local',
            'reconciliation.mirror_disk' => null,
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

    private function seedUniverse(): Asset
    {
        $asset = Asset::create([
            'symbol' => 'AAA',
            'name' => 'Asset AAA',
            'sector' => 'Energy',
            'sync_price' => true,
            'sync_broker_summary' => true,
        ]);

        foreach (['2026-09-01' => 1000.25, '2026-09-02' => 1010.5, '2026-09-03' => 1020.75] as $date => $close) {
            Price::create([
                'asset_id' => $asset->id,
                'date' => $date,
                'open' => $close - 5,
                'high' => $close + 5,
                'low' => $close - 10,
                'close' => $close,
                'volume' => 1_234_500,
            ]);

            DB::table('trading_days')->insertOrIgnore(['date' => $date]);
        }

        // One genuine daily window and one range aggregate, so the round trip
        // has to preserve the difference.
        $this->window($asset, '2026-09-03', '2026-09-03', 'Acc');
        $this->window($asset, '2026-09-01', '2026-09-03', 'Dist');

        return $asset;
    }

    private function window(Asset $asset, string $from, string $to, string $accdist): void
    {
        $window = BrokerSummaryWindow::create([
            'asset_id' => $asset->id,
            'from_date' => $from,
            'to_date' => $to,
            'transaction_type' => 'TRANSACTION_TYPE_NET',
            'returned_buyer_count' => 10,
            'returned_seller_count' => 9,
            'total_buyer' => 12,
            'total_seller' => 11,
            'source_filename' => sprintf('broker_summary/AAA_%s_%s_TRANSACTION_TYPE_NET.json', $from, $to),
            'source_hash' => hash('sha256', $from.$to),
            'imported_at' => now(),
        ]);

        $window->entries()->createMany([
            ['broker_code' => 'YP', 'side' => 'buy', 'broker_type' => 'Lokal', 'frequency' => 100, 'source_date' => $to, 'net_lot' => 500, 'net_value' => 5_000_000.5, 'gross_volume' => 50_000, 'gross_value' => 50_000_000.25, 'average_price' => 1000.5],
            ['broker_code' => 'CC', 'side' => 'sell', 'broker_type' => 'Asing', 'frequency' => 80, 'source_date' => $to, 'net_lot' => -300, 'net_value' => -3_000_000.75, 'gross_volume' => 30_000, 'gross_value' => 30_000_000.5, 'average_price' => 999.25],
        ]);

        BandarDetectorSummary::create([
            'asset_id' => $asset->id,
            'broker_summary_window_id' => $window->id,
            'from_date' => $from,
            'to_date' => $to,
            'transaction_type' => 'TRANSACTION_TYPE_NET',
            'broker_accdist' => $accdist,
            'number_broker_buysell' => 3,
            'total_buyer' => 12,
            'total_seller' => 11,
            'value' => 9_876_543_210.5,
            'volume' => 1_000_000,
            'average_price' => 1010.75,
            'metrics_json' => ['avg' => ['amount' => 1234.5]],
        ]);
    }

    private function wipeCanonicalState(): void
    {
        BandarDetectorSummary::query()->delete();
        BrokerSummaryEntry::query()->delete();
        BrokerSummaryWindow::query()->delete();
        Price::query()->delete();
        Asset::query()->delete();

        foreach (glob($this->seedDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function test_it_restores_bars_windows_entries_and_detectors(): void
    {
        $this->seedUniverse();
        app(ReconciliationService::class)->run();

        $this->wipeCanonicalState();
        $this->assertSame(0, Price::query()->count());

        $result = app(ReconciliationRestorer::class)->restore();

        $this->assertTrue($result['ok']);
        $this->assertSame(['AAA'], $result['restored']);

        $asset = Asset::where('symbol', 'AAA')->firstOrFail();

        $this->assertSame(3, Price::where('asset_id', $asset->id)->count());
        $this->assertEqualsWithDelta(
            1020.75,
            (float) Price::where('asset_id', $asset->id)->orderByDesc('date')->value('close'),
            0.0001,
        );

        $this->assertSame(2, BrokerSummaryWindow::where('asset_id', $asset->id)->count());
        $this->assertSame(4, BrokerSummaryEntry::query()->count());
        $this->assertSame(2, BandarDetectorSummary::where('asset_id', $asset->id)->count());

        $detector = BandarDetectorSummary::where('asset_id', $asset->id)
            ->where('from_date', '2026-09-03')
            ->firstOrFail();

        $this->assertSame('Acc', $detector->broker_accdist);
        $this->assertEqualsWithDelta(9_876_543_210.5, (float) $detector->value, 0.01);
        $this->assertSame(['avg' => ['amount' => 1234.5]], $detector->metrics_json);
    }

    /**
     * A restore that turned the three-day aggregate into three daily windows
     * would corrupt every flow statistic downstream.
     */
    public function test_a_range_aggregate_is_restored_as_an_aggregate(): void
    {
        $this->seedUniverse();
        app(ReconciliationService::class)->run();
        $this->wipeCanonicalState();

        app(ReconciliationRestorer::class)->restore();

        $asset = Asset::where('symbol', 'AAA')->firstOrFail();

        $aggregate = BrokerSummaryWindow::where('asset_id', $asset->id)
            ->whereColumn('from_date', '!=', 'to_date')
            ->get();

        $this->assertCount(1, $aggregate);
        $this->assertSame('2026-09-01', $aggregate[0]->from_date->toDateString());
        $this->assertSame('2026-09-03', $aggregate[0]->to_date->toDateString());

        $this->assertSame(
            1,
            BrokerSummaryWindow::where('asset_id', $asset->id)->whereColumn('from_date', '=', 'to_date')->count(),
        );
    }

    public function test_it_rewrites_the_historical_seed_csv(): void
    {
        $this->seedUniverse();
        app(ReconciliationService::class)->run();
        $this->wipeCanonicalState();

        app(ReconciliationRestorer::class)->restore();

        $path = $this->seedDir.'/AAA.csv';

        $this->assertFileExists($path);

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path))));

        $this->assertSame('Date,Open,High,Low,Close,Volume', $lines[0]);
        $this->assertCount(4, $lines);
        // CsvBars writes d/m/Y, and the restore must not invent its own format.
        $this->assertStringStartsWith('01/09/2026,', $lines[1]);
        $this->assertStringStartsWith('03/09/2026,', $lines[3]);
    }

    public function test_restoring_twice_is_idempotent(): void
    {
        $this->seedUniverse();
        app(ReconciliationService::class)->run();
        $this->wipeCanonicalState();

        app(ReconciliationRestorer::class)->restore();
        $firstCounts = [Price::query()->count(), BrokerSummaryWindow::query()->count(), BrokerSummaryEntry::query()->count()];

        app(ReconciliationRestorer::class)->restore();
        $secondCounts = [Price::query()->count(), BrokerSummaryWindow::query()->count(), BrokerSummaryEntry::query()->count()];

        $this->assertSame($firstCounts, $secondCounts, 'A second restore duplicated rows.');
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->seedUniverse();
        app(ReconciliationService::class)->run();
        $this->wipeCanonicalState();

        $result = app(ReconciliationRestorer::class)->restore(['dry_run' => true]);

        $this->assertTrue($result['ok']);
        $this->assertSame(3, $result['assets']['AAA']['ohlcv_rows']);
        $this->assertSame(1, $result['assets']['AAA']['aggregate_windows']);
        $this->assertSame(0, Price::query()->count(), 'A dry run wrote rows.');
        $this->assertFileDoesNotExist($this->seedDir.'/AAA.csv');
    }

    public function test_corrupt_json_fails_that_asset_rather_than_restoring_partially(): void
    {
        $this->seedUniverse();
        app(ReconciliationService::class)->run();
        $this->wipeCanonicalState();

        $store = app(ReconciliationStore::class);
        Storage::disk('local')->put($store->assetPath('AAA'), '{"schema_version": 1, "symbol": "AAA"');

        $result = app(ReconciliationRestorer::class)->restore();

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('AAA', $result['failed']);
        $this->assertStringContainsString('not valid JSON', $result['failed']['AAA']);
        $this->assertSame(0, Price::query()->count(), 'A corrupt document restored data anyway.');
    }

    public function test_an_unsupported_schema_version_is_rejected_clearly(): void
    {
        $this->seedUniverse();
        app(ReconciliationService::class)->run();

        $store = app(ReconciliationStore::class);
        $document = $store->readAsset('AAA');
        $document['schema_version'] = 99;
        Storage::disk('local')->put($store->assetPath('AAA'), $store->encode($document));

        $this->wipeCanonicalState();

        $result = app(ReconciliationRestorer::class)->restore();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Schema version 99 is not supported', $result['failed']['AAA']);
        $this->assertSame(0, Price::query()->count());
    }

    public function test_a_document_that_no_longer_matches_the_manifest_hash_is_rejected(): void
    {
        $this->seedUniverse();
        app(ReconciliationService::class)->run();

        $store = app(ReconciliationStore::class);
        $document = $store->readAsset('AAA');
        // Valid JSON, valid schema, wrong bytes: only the manifest hash can
        // catch this.
        $document['asset']['name'] = 'Tampered';
        Storage::disk('local')->put($store->assetPath('AAA'), $store->encode($document));

        $this->wipeCanonicalState();

        $result = app(ReconciliationRestorer::class)->restore();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('does not match the hash recorded in the manifest', $result['failed']['AAA']);
    }
}
