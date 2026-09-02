<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Asset;
use App\Models\BandarDetectorSummary;
use App\Models\BrokerSummaryWindow;
use App\Models\Price;
use App\Services\Reconciliation\ReconciliationService;
use App\Services\Reconciliation\ReconciliationStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The incremental contract: rebuild what changed, and nothing else.
 *
 * These are the properties the whole layer rests on. If an unchanged asset is
 * rewritten, every night re-uploads the universe; if a changed one is skipped,
 * the recovery copy is quietly stale at the moment it matters most.
 */
class ReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $seedDir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seedDir = sys_get_temp_dir().'/breakout-recon-'.bin2hex(random_bytes(4));
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

    private function asset(string $symbol = 'AAA'): Asset
    {
        return Asset::create([
            'symbol' => $symbol,
            'name' => 'Asset '.$symbol,
            'sector' => 'Energy',
            'sync_price' => true,
            'sync_broker_summary' => true,
        ]);
    }

    private function bar(Asset $asset, string $date, float $close, float $volume = 1_000_000): void
    {
        Price::create([
            'asset_id' => $asset->id,
            'date' => $date,
            'open' => $close - 5,
            'high' => $close + 5,
            'low' => $close - 10,
            'close' => $close,
            'volume' => $volume,
        ]);

        DB::table('trading_days')->insertOrIgnore(['date' => $date]);
    }

    private function window(Asset $asset, string $from, string $to, string $accdist = 'Acc', string $hash = 'abc'): BrokerSummaryWindow
    {
        $window = BrokerSummaryWindow::create([
            'asset_id' => $asset->id,
            'from_date' => $from,
            'to_date' => $to,
            'transaction_type' => 'TRANSACTION_TYPE_NET',
            'returned_buyer_count' => 10,
            'returned_seller_count' => 10,
            'total_buyer' => 12,
            'total_seller' => 11,
            'source_filename' => sprintf('broker_summary/%s_%s_%s_TRANSACTION_TYPE_NET.json', $asset->symbol, $from, $to),
            'source_hash' => $hash,
            'imported_at' => now(),
        ]);

        BandarDetectorSummary::create([
            'asset_id' => $asset->id,
            'broker_summary_window_id' => $window->id,
            'from_date' => $from,
            'to_date' => $to,
            'transaction_type' => 'TRANSACTION_TYPE_NET',
            'broker_accdist' => $accdist,
            'number_broker_buysell' => 5,
            'total_buyer' => 12,
            'total_seller' => 11,
            'value' => 5_000_000_000,
            'volume' => 1_000_000,
            'average_price' => 1000.5,
            'metrics_json' => ['avg' => ['amount' => 1000]],
        ]);

        return $window;
    }

    /**
     * The raw file a window was imported from. Its absence is a legitimate
     * warning on its own, so a test about session gaps has to keep it present
     * or it measures the wrong condition.
     */
    private function archived(BrokerSummaryWindow $window): void
    {
        Storage::disk('local')->put((string) $window->source_filename, '{}');
    }

    private function seedCsv(string $symbol): void
    {
        file_put_contents($this->seedDir.'/'.$symbol.'.csv', "Date,Open,High,Low,Close,Volume\n");
    }

    private function service(): ReconciliationService
    {
        return app(ReconciliationService::class);
    }

    private function store(): ReconciliationStore
    {
        return app(ReconciliationStore::class);
    }

    public function test_it_writes_a_document_and_a_manifest(): void
    {
        $asset = $this->asset();
        $this->bar($asset, '2026-09-01', 1000);
        $this->window($asset, '2026-09-01', '2026-09-01');

        $result = $this->service()->run();

        $this->assertSame(['AAA'], $result['assets_changed']);
        $this->assertSame(1, $result['assets_checked']);

        $document = $this->store()->readAsset('AAA');

        $this->assertSame('AAA', $document['symbol']);
        $this->assertSame(1, $document['schema_version']);
        $this->assertCount(1, $document['ohlcv']);
        $this->assertCount(1, $document['broker_summary']['windows']);
        $this->assertCount(1, $document['broker_summary']['daily_flow']);

        $manifest = $this->store()->readManifest();
        $this->assertSame(1, $manifest['summary']['asset_count']);
        $this->assertArrayHasKey('AAA', $manifest['assets']);
    }

    /**
     * The property the nightly job depends on.
     */
    public function test_a_second_run_with_no_new_data_rewrites_nothing(): void
    {
        $asset = $this->asset();
        $this->bar($asset, '2026-09-01', 1000);
        $this->window($asset, '2026-09-01', '2026-09-01');

        $first = $this->service()->run();
        $hashAfterFirst = $this->store()->hashOf($this->store()->assetPath('AAA'));

        $second = $this->service()->run();

        $this->assertSame(['AAA'], $first['assets_changed']);
        $this->assertSame([], $second['assets_changed'], 'An unchanged asset was rebuilt.');
        $this->assertSame(['AAA'], $second['assets_skipped']);
        $this->assertSame($hashAfterFirst, $this->store()->hashOf($this->store()->assetPath('AAA')));
        $this->assertSame($first['manifest_hash'], $second['manifest_hash']);
    }

    public function test_a_new_bar_changes_the_fingerprint_and_the_document(): void
    {
        $asset = $this->asset();
        $this->bar($asset, '2026-09-01', 1000);
        $this->window($asset, '2026-09-01', '2026-09-01');

        $this->service()->run();
        $before = $this->store()->hashOf($this->store()->assetPath('AAA'));

        $this->bar($asset, '2026-09-02', 1010);
        $result = $this->service()->run();

        $this->assertSame(['AAA'], $result['assets_changed']);
        $this->assertNotSame($before, $this->store()->hashOf($this->store()->assetPath('AAA')));
        $this->assertCount(2, $this->store()->readAsset('AAA')['ohlcv']);
    }

    /**
     * A corrected close leaves the row count and date range untouched, which
     * is exactly the change a naive extent-only fingerprint would miss.
     */
    public function test_a_corrected_close_changes_the_fingerprint(): void
    {
        $asset = $this->asset();
        $this->bar($asset, '2026-09-01', 1000);
        $this->window($asset, '2026-09-01', '2026-09-01');

        $this->service()->run();
        $before = $this->store()->hashOf($this->store()->assetPath('AAA'));

        Price::query()->where('asset_id', $asset->id)->update(['close' => 1234.5]);

        $result = $this->service()->run();

        $this->assertSame(['AAA'], $result['assets_changed']);
        $this->assertNotSame($before, $this->store()->hashOf($this->store()->assetPath('AAA')));
    }

    public function test_a_new_broker_window_changes_the_fingerprint_and_the_document(): void
    {
        $asset = $this->asset();
        $this->bar($asset, '2026-09-01', 1000);
        $this->window($asset, '2026-09-01', '2026-09-01');

        $this->service()->run();
        $before = $this->store()->hashOf($this->store()->assetPath('AAA'));

        $this->bar($asset, '2026-09-02', 1010);
        $this->window($asset, '2026-09-02', '2026-09-02', 'Dist', 'def');

        $result = $this->service()->run();

        $this->assertSame(['AAA'], $result['assets_changed']);
        $this->assertNotSame($before, $this->store()->hashOf($this->store()->assetPath('AAA')));
        $this->assertCount(2, $this->store()->readAsset('AAA')['broker_summary']['daily_flow']);
    }

    /**
     * A re-import that changed the payload without changing the range: the
     * window identity is the same, so only source_hash can reveal it.
     */
    public function test_a_reimported_window_payload_changes_the_fingerprint(): void
    {
        $asset = $this->asset();
        $this->bar($asset, '2026-09-01', 1000);
        $this->window($asset, '2026-09-01', '2026-09-01', 'Acc', 'hash-one');

        $this->service()->run();
        $before = $this->store()->hashOf($this->store()->assetPath('AAA'));

        BrokerSummaryWindow::query()->where('asset_id', $asset->id)->update(['source_hash' => 'hash-two']);

        $result = $this->service()->run();

        $this->assertSame(['AAA'], $result['assets_changed']);
        $this->assertNotSame($before, $this->store()->hashOf($this->store()->assetPath('AAA')));
    }

    public function test_generation_is_deterministic(): void
    {
        $asset = $this->asset();

        foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $index => $date) {
            $this->bar($asset, $date, 1000 + $index * 10);
            $this->window($asset, $date, $date, $index % 2 === 0 ? 'Acc' : 'Dist', 'h'.$index);
        }

        $this->service()->run();
        $first = $this->store()->read($this->store()->assetPath('AAA'));

        // Force a rebuild from the same inputs; the bytes must not move.
        $this->service()->run(['force' => true]);
        $second = $this->store()->read($this->store()->assetPath('AAA'));

        $document = $this->store()->decode($second);
        unset($document['generated_at']);
        $firstDocument = $this->store()->decode($first);
        unset($firstDocument['generated_at']);

        $this->assertSame(
            json_encode($firstDocument),
            json_encode($document),
            'The same inputs produced a different document.',
        );
    }

    public function test_ohlcv_is_ordered_by_date_ascending(): void
    {
        $asset = $this->asset();

        foreach (['2026-09-03', '2026-09-01', '2026-09-02'] as $date) {
            $this->bar($asset, $date, 1000);
        }

        $this->service()->run();

        $dates = array_column($this->store()->readAsset('AAA')['ohlcv'], 'date');

        $this->assertSame(['2026-09-01', '2026-09-02', '2026-09-03'], $dates);
    }

    /**
     * The rule the sprint exists to protect: an aggregate is an aggregate.
     */
    public function test_an_aggregate_window_never_becomes_a_daily_observation(): void
    {
        $asset = $this->asset();
        $this->bar($asset, '2026-09-01', 1000);
        $this->bar($asset, '2026-09-02', 1010);
        $this->bar($asset, '2026-09-03', 1020);

        // One three-day aggregate and one genuine single day.
        $this->window($asset, '2026-09-01', '2026-09-03', 'Acc', 'agg');
        $this->window($asset, '2026-09-03', '2026-09-03', 'Dist', 'day');

        $this->service()->run();
        $document = $this->store()->readAsset('AAA');

        $this->assertCount(2, $document['broker_summary']['windows']);
        $this->assertCount(
            1,
            $document['broker_summary']['daily_flow'],
            'A range aggregate leaked into the daily flow series.',
        );
        $this->assertSame('2026-09-03', $document['broker_summary']['daily_flow'][0]['date']);
        $this->assertSame(-1, $document['broker_summary']['daily_flow'][0]['accdist_score']);

        $coverage = $document['coverage']['broker_summary'];
        $this->assertSame(1, $coverage['single_day_window_count']);
        $this->assertSame(1, $coverage['aggregate_window_count']);
    }

    /**
     * A weekend is not a gap. Neither is a holiday, nor a suspension.
     *
     * The missing-session check is anchored on the asset's own bars: if there
     * is no bar for a date, there is nothing to be missing a broker summary
     * for. Reporting Saturday as an integrity failure would flag every asset
     * every week and train the reader to ignore the field.
     */
    public function test_a_weekend_or_holiday_is_never_reported_as_a_missing_session(): void
    {
        $asset = $this->asset();

        // Thursday, Friday, then Monday -- the weekend has no bar at all, and
        // Wednesday stands in for a mid-week holiday.
        foreach (['2026-08-27', '2026-08-28', '2026-08-31'] as $date) {
            $this->bar($asset, $date, 1000);
            $this->archived($this->window($asset, $date, $date, 'Acc', 'h-'.$date));
        }

        $this->seedCsv('AAA');

        // The exchange calendar knows about the weekend; the asset does not
        // trade it, so it must not surface as a gap either way.
        DB::table('trading_days')->insertOrIgnore([['date' => '2026-08-29'], ['date' => '2026-08-30']]);

        $this->service()->run(['symbols' => ['AAA']]);

        $document = $this->store()->readAsset('AAA');

        $this->assertSame([], $document['integrity']['missing_broker_sessions']);
        $this->assertSame(0, $document['integrity']['missing_broker_session_count']);
        $this->assertSame('healthy', $document['integrity']['status']);
    }

    /**
     * The counterpart: a session the asset actually traded, inside the daily
     * collection window, with no single-day summary, is a real gap.
     */
    public function test_a_traded_session_with_no_daily_summary_is_reported(): void
    {
        $asset = $this->asset();

        foreach (['2026-08-27', '2026-08-28', '2026-08-31'] as $date) {
            $this->bar($asset, $date, 1000);
        }

        // Friday is skipped: a bar exists, the days either side are covered,
        // and no single-day window covers it.
        $this->archived($this->window($asset, '2026-08-27', '2026-08-27', 'Acc', 'h-1'));
        $this->archived($this->window($asset, '2026-08-31', '2026-08-31', 'Acc', 'h-2'));

        $this->seedCsv('AAA');

        $this->service()->run(['symbols' => ['AAA']]);

        $document = $this->store()->readAsset('AAA');

        $this->assertSame(['2026-08-28'], $document['integrity']['missing_broker_sessions']);
        $this->assertSame('warning', $document['integrity']['status']);
    }

    public function test_flow_balance_reports_how_many_sessions_it_had(): void
    {
        $asset = $this->asset();

        foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $date) {
            $this->bar($asset, $date, 1000);
            $this->window($asset, $date, $date, 'Acc', 'h'.$date);
        }

        $this->service()->run();
        $insight = $this->store()->readAsset('AAA')['insight'];

        $this->assertSame(3, $insight['flow_balance_5d']);
        $this->assertSame(3, $insight['available_daily_sessions_5d'], 'Three of five sessions must not read as five.');
        $this->assertSame(3, $insight['available_daily_sessions_20d']);
    }
}
