<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Asset;
use App\Models\BandarDetectorSummary;
use App\Models\BrokerSummaryWindow;
use App\Models\Price;
use App\Services\BackupStatus;
use App\Services\Reconciliation\ReconciliationMirror;
use App\Services\Reconciliation\ReconciliationService;
use App\Services\Reconciliation\ReconciliationStore;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The split between the two backup endpoints, and the reconciliation API.
 *
 * The point of the split is cost, not subject. GET /v1/backup-status answers
 * "can I recover, and is the off-server copy current?" from the manifest, in a
 * handful of reads that do not grow with the archive. The file-by-file
 * comparison still exists, still catches an altered remote copy, and is now an
 * explicit action rather than something the dashboard does while you read it.
 *
 * These tests hold both halves of that bargain: the fast path must not walk
 * the archive, and the audit must still find what only a content comparison
 * can find.
 */
class BackupReadinessApiTest extends TestCase
{
    use RefreshDatabase;

    private string $seedDir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('gdrive');

        $this->seedDir = sys_get_temp_dir().'/breakout-readiness-'.bin2hex(random_bytes(4));
        mkdir($this->seedDir, 0777, true);

        config([
            'csv.seed_dir' => $this->seedDir,
            'csv.mirror_path' => 'seeds/historical',
            'stockbit.save_dir' => 'broker_summary',
            'reconciliation.local_disk' => 'local',
            'reconciliation.mirror_disk' => 'gdrive',
            'reconciliation.flow_windows' => [5, 20],
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

    private function fetch(string $path): TestResponse
    {
        $response = $this->getJson($path);

        if ($response->status() === 401) {
            $response = $this->withoutMiddleware()->getJson($path);
        }

        return $response;
    }

    /**
     * One asset with a few sessions of genuine single-day broker data, its raw
     * files present, and a seed CSV -- the shape a healthy asset actually has.
     */
    private function seedAsset(string $symbol, array $dates, string $accdist = 'Acc'): Asset
    {
        $asset = Asset::create([
            'symbol' => $symbol,
            'name' => 'Asset '.$symbol,
            'sector' => 'Energy',
            'sync_price' => true,
            'sync_broker_summary' => true,
        ]);

        file_put_contents($this->seedDir.'/'.$symbol.'.csv', "Date,Open,High,Low,Close,Volume\n");

        foreach ($dates as $index => $date) {
            Price::create([
                'asset_id' => $asset->id,
                'date' => $date,
                'open' => 1000,
                'high' => 1010,
                'low' => 990,
                'close' => 1000 + $index,
                'volume' => 1_000_000,
            ]);

            DB::table('trading_days')->insertOrIgnore(['date' => $date]);

            $path = sprintf('broker_summary/%s_%s_%s_TRANSACTION_TYPE_NET.json', $symbol, $date, $date);

            $window = BrokerSummaryWindow::create([
                'asset_id' => $asset->id,
                'from_date' => $date,
                'to_date' => $date,
                'transaction_type' => 'TRANSACTION_TYPE_NET',
                'returned_buyer_count' => 10,
                'returned_seller_count' => 10,
                'total_buyer' => 12,
                'total_seller' => 11,
                'source_filename' => $path,
                'source_hash' => 'hash-'.$symbol.'-'.$date,
                'imported_at' => now(),
            ]);

            Storage::disk('local')->put($path, '{}');

            BandarDetectorSummary::create([
                'asset_id' => $asset->id,
                'broker_summary_window_id' => $window->id,
                'from_date' => $date,
                'to_date' => $date,
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
        }

        return $asset;
    }

    private function dates(int $count = 6): array
    {
        // Weekdays only, so no bar ever lands on a weekend.
        $dates = [];
        $cursor = now()->setDate(2026, 8, 3)->startOfDay();

        while (count($dates) < $count) {
            if ($cursor->isWeekday()) {
                $dates[] = $cursor->toDateString();
            }

            $cursor->addDay();
        }

        return $dates;
    }

    private function reconcile(): void
    {
        app(ReconciliationService::class)->run(['force' => true]);
    }

    public function test_the_fast_report_never_walks_the_raw_archive(): void
    {
        $this->seedAsset('AAA', $this->dates());
        $this->reconcile();

        // The scan is the expensive thing. Not "is it fast enough" -- whether
        // it happens at all, which is the only version of this assertion that
        // cannot rot as the archive grows.
        $this->mock(BackupStatus::class, function ($mock) {
            $mock->shouldNotReceive('report');
        });

        $response = $this->fetch('/api/v1/backup-status')->assertOk();

        $this->assertNull($response->json('data.collections'));
        $this->assertNotNull($response->json('data.readiness.status'));
        $this->assertSame(1, $response->json('data.reconciliation.asset_count'));
    }

    public function test_the_deep_report_is_available_from_the_same_endpoint(): void
    {
        $this->seedAsset('AAA', $this->dates());
        $this->reconcile();

        $response = $this->fetch('/api/v1/backup-status?deep=1')->assertOk();

        $this->assertIsArray($response->json('data.collections'));
        $this->assertNotNull($response->json('data.readiness_report.readiness.status'));
    }

    /**
     * The cost of the split, stated honestly: a Drive copy edited behind the
     * application's back is invisible to the readiness view and is exactly
     * what the audit exists to catch.
     */
    public function test_an_altered_drive_copy_is_caught_by_the_audit(): void
    {
        file_put_contents($this->seedDir.'/BBCA.csv', "Date,Close\n2026-08-28,8000\n");
        Storage::disk('gdrive')->put('seeds/historical/BBCA.csv', "Date,Close\n2026-08-28,1\n");

        $this->seedAsset('BBCA', $this->dates());
        $this->reconcile();

        // The readiness view says nothing about individual raw files, and does
        // not pretend to.
        $fast = $this->fetch('/api/v1/backup-status')->assertOk();
        $this->assertNull($fast->json('data.collections'));

        $audit = $this->fetch('/api/v1/backup-status/audit')->assertOk();

        $file = collect($audit->json('data.collections'))
            ->firstWhere('key', 'historical')['files'][0] ?? null;

        $this->assertNotNull($file);
        $this->assertSame('BBCA.csv', $file['name']);
        $this->assertNotSame(BackupStatus::SYNCED, $file['state']);
        $this->assertTrue($file['can_push'], 'The altered remote copy could not be repaired.');
    }

    public function test_readiness_is_not_ready_until_the_manifest_is_published(): void
    {
        $this->seedAsset('AAA', $this->dates());
        $this->reconcile();

        $response = $this->fetch('/api/v1/backup-status')->assertOk();

        $this->assertSame('not_ready', $response->json('data.readiness.status'));
        $this->assertFalse($response->json('data.mirror.in_sync'));

        app(ReconciliationMirror::class)->push();

        $published = $this->fetch('/api/v1/backup-status')->assertOk();

        $this->assertTrue($published->json('data.mirror.in_sync'));
        $this->assertSame('ready', $published->json('data.readiness.status'));
    }

    public function test_a_missing_manifest_is_reported_as_not_ready_rather_than_empty(): void
    {
        $response = $this->fetch('/api/v1/backup-status')->assertOk();

        $this->assertSame('not_ready', $response->json('data.readiness.status'));
        $this->assertFalse($response->json('data.reconciliation.present'));
        $this->assertNotEmpty($response->json('data.readiness.blockers'));
    }

    public function test_the_flow_snapshot_separates_ranked_symbols_from_thin_ones(): void
    {
        $dates = $this->dates();

        $this->seedAsset('AAA', $dates, 'Accumulation');
        $this->seedAsset('BBB', array_slice($dates, 0, 2), 'Accumulation');
        $this->reconcile();

        $snapshot = $this->fetch('/api/v1/backup-status')->assertOk()->json('data.flow_snapshot');

        $this->assertSame(5, $snapshot['window']);
        $this->assertSame(1, $snapshot['ranked_count']);
        $this->assertSame(['AAA'], array_column($snapshot['accumulating'], 'symbol'));
        $this->assertSame(['BBB'], array_column($snapshot['insufficient'], 'symbol'));
        $this->assertSame(2, $snapshot['insufficient'][0]['available_sessions']);
        $this->assertSame(5, $snapshot['insufficient'][0]['required_sessions']);
    }

    public function test_the_reconciliation_index_reads_only_the_manifest(): void
    {
        $this->seedAsset('AAA', $this->dates());
        $this->seedAsset('BBB', $this->dates());
        $this->reconcile();

        $response = $this->fetch('/api/v1/reconciliation')->assertOk();

        $this->assertSame(2, $response->json('data.total'));
        $this->assertSame(['AAA', 'BBB'], array_column($response->json('data.rows'), 'symbol'));
        $this->assertSame(5, $response->json('data.flow_window'));

        // A row carries what the table shows. If it needed the document, the
        // list would cost one file read per symbol.
        $row = $response->json('data.rows.0');
        $this->assertArrayHasKey('integrity_status', $row);
        $this->assertArrayHasKey('flow_balance_5d', $row);
        $this->assertArrayHasKey('available_daily_sessions_5d', $row);
        $this->assertArrayNotHasKey('ohlcv', $row);
    }

    public function test_the_index_filters_sorts_and_pages(): void
    {
        $dates = $this->dates();

        $this->seedAsset('AAA', $dates, 'Accumulation');
        $this->seedAsset('BBB', $dates, 'Distribution');
        $this->seedAsset('CCC', array_slice($dates, 0, 2), 'Accumulation');
        $this->reconcile();

        $accumulating = $this->fetch('/api/v1/reconciliation?filter=accumulating')->assertOk();
        $this->assertSame(['AAA'], array_column($accumulating->json('data.rows'), 'symbol'));

        $distributing = $this->fetch('/api/v1/reconciliation?filter=distributing')->assertOk();
        $this->assertSame(['BBB'], array_column($distributing->json('data.rows'), 'symbol'));

        // CCC has two sessions against a five-session window, so it is neither
        // accumulating nor distributing -- it is unrankable, and says so.
        $thin = $this->fetch('/api/v1/reconciliation?filter=insufficient_daily')->assertOk();
        $this->assertSame(['CCC'], array_column($thin->json('data.rows'), 'symbol'));

        $sorted = $this->fetch('/api/v1/reconciliation?sort=symbol&direction=desc')->assertOk();
        $this->assertSame(['CCC', 'BBB', 'AAA'], array_column($sorted->json('data.rows'), 'symbol'));

        $search = $this->fetch('/api/v1/reconciliation?search=BB')->assertOk();
        $this->assertSame(['BBB'], array_column($search->json('data.rows'), 'symbol'));

        $paged = $this->fetch('/api/v1/reconciliation?per_page=2&page=2')->assertOk();
        $this->assertSame(['CCC'], array_column($paged->json('data.rows'), 'symbol'));
        $this->assertSame(2, $paged->json('data.last_page'));
    }

    public function test_the_detail_endpoint_returns_bounded_slices(): void
    {
        $this->seedAsset('AAA', $this->dates(6));
        $this->reconcile();

        $response = $this->fetch('/api/v1/reconciliation/AAA?sessions=2')->assertOk();

        $this->assertSame('AAA', $response->json('data.symbol'));
        $this->assertCount(2, $response->json('data.recent_ohlcv'));
        $this->assertCount(2, $response->json('data.recent_daily_flow'));
        $this->assertCount(2, $response->json('data.recent_windows'));
        $this->assertSame(6, $response->json('data.coverage.ohlcv.rows'));

        // The windows view says what a window is rather than implying days.
        $this->assertTrue($response->json('data.recent_windows.0.is_single_day'));
        $this->assertNotNull($response->json('data.document_hash'));
    }

    public function test_an_unknown_symbol_is_a_clean_404_and_a_malformed_one_is_rejected(): void
    {
        $this->seedAsset('AAA', $this->dates());
        $this->reconcile();

        $this->fetch('/api/v1/reconciliation/ZZZZ')->assertStatus(404);

        // Never routed as one segment, so it cannot reach the controller at
        // all -- and the controller rejects anything outside the symbol
        // pattern in case a future route is looser than this one.
        $this->fetch('/api/v1/reconciliation/'.urlencode('../etc/passwd'))->assertStatus(404);
        $this->fetch('/api/v1/reconciliation/'.rawurlencode('A B'))->assertStatus(422);
        $this->fetch('/api/v1/reconciliation/'.str_repeat('A', 64))->assertStatus(422);
    }

    /**
     * Cold storage holding a manifest this server does not is a *recoverable*
     * state, and must not be reported as "cold storage is behind".
     *
     * That message points at a push, and pushing here would overwrite the one
     * good remaining copy with nothing. The honest reading is the opposite:
     * the local layer is the one that is missing, and the remote is the thing
     * to restore from.
     */
    public function test_a_missing_local_manifest_against_a_published_one_points_at_restore(): void
    {
        $this->seedAsset('AAA', $this->dates());
        $this->reconcile();

        app(ReconciliationMirror::class)->push();

        // The local layer is lost; cold storage still has it.
        Storage::disk('local')->delete('reconciliation/manifest.json');

        $response = $this->fetch('/api/v1/backup-status')->assertOk();

        $blockers = $response->json('data.readiness.blockers');
        $joined = implode(' | ', $blockers);

        $this->assertStringContainsString('cold storage', strtolower($joined));
        $this->assertStringContainsString('data:restore', $joined);

        // The misleading one must be gone: nothing local exists to be behind.
        $this->assertStringNotContainsString('cold storage is behind', $joined);

        $this->assertFalse($response->json('data.mirror.in_sync'));
        $this->assertTrue($response->json('data.mirror.manifest_present'));
        $this->assertNull($response->json('data.mirror.local_manifest_hash'));
    }

    /**
     * A write must never remove the previous document before replacing it.
     *
     * The store promises that a process dying mid-write leaves the previous
     * complete file. It deleted the destination and then renamed the
     * temporary over the gap, so a failure or a kill between the two left
     * nothing at all -- and for the manifest that means the recovery index
     * disappears while cold storage still advertises one.
     *
     * rename() already replaces atomically on POSIX, so the delete only ever
     * opened that window. Asserted on the calls rather than by forcing a
     * failure, because the failure modes that would prove it (an unwritable
     * directory) are not reproducible as root.
     */
    public function test_the_store_never_deletes_a_document_before_replacing_it(): void
    {
        $disk = \Mockery::mock(Filesystem::class);

        // The destination already holds a good document.
        $disk->shouldReceive('exists')
            ->andReturnUsing(static fn (string $path): bool => $path === 'reconciliation/manifest.json');
        $disk->shouldReceive('get')
            ->with('reconciliation/manifest.json')
            ->andReturn('{"schema_version":1,"marker":"original"}');
        $disk->shouldReceive('put')->once()->andReturn(true);
        $disk->shouldReceive('move')->once()->andReturn(true);

        // The guarantee.
        $disk->shouldNotReceive('delete');

        Storage::shouldReceive('disk')->andReturn($disk);

        app(ReconciliationStore::class)
            ->writeManifest(['schema_version' => 1, 'marker' => 'replacement']);
    }

    public function test_the_reconciliation_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/reconciliation')->assertUnauthorized();
        $this->getJson('/api/v1/reconciliation/AAA')->assertUnauthorized();
        $this->getJson('/api/v1/backup-status')->assertUnauthorized();
    }
}
