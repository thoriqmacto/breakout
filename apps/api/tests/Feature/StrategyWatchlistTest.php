<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\BrokerSummaryEntry;
use App\Models\BrokerSummaryWindow;
use App\Models\Metric;
use App\Models\Price;
use App\Models\User;
use App\Models\WatchlistScore;
use App\Services\Strategy\BrokerAccumulationAggregator;
use App\Services\Strategy\WatchlistRanker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StrategyWatchlistTest extends TestCase
{
    use RefreshDatabase;

    private string $scanDate = '2026-04-15';

    public function test_aggregator_writes_idempotent_window_rows(): void
    {
        $asset = Asset::create(['symbol' => 'BBCA', 'name' => 'BCA', 'sector' => 'Banks']);
        $this->seedBrokerFacts($asset->id, [
            ['date' => '2026-04-13', 'broker' => 'AA', 'buy' => 1_000_000, 'sell' => 200_000],
            ['date' => '2026-04-14', 'broker' => 'AA', 'buy' => 800_000, 'sell' => 100_000],
            ['date' => '2026-04-15', 'broker' => 'AA', 'buy' => 600_000, 'sell' => 150_000],
            ['date' => '2026-04-15', 'broker' => 'BB', 'buy' => 200_000, 'sell' => 600_000],
        ]);

        $aggregator = app(BrokerAccumulationAggregator::class);
        $first = $aggregator->rollup(Carbon::parse($this->scanDate), [3, 5]);

        $this->assertSame(2, $first['rows_written']);
        $this->assertSame(1, DB::table('broker_accumulation_windows')
            ->where('asset_id', $asset->id)
            ->whereDate('end_date', $this->scanDate)
            ->where('window_days', 3)
            ->count());

        // Re-run is idempotent: same row count, no errors.
        $second = $aggregator->rollup(Carbon::parse($this->scanDate), [3, 5]);
        $this->assertSame(2, $second['rows_written']);
        $this->assertSame(1, DB::table('broker_accumulation_windows')
            ->where('asset_id', $asset->id)
            ->whereDate('end_date', $this->scanDate)
            ->where('window_days', 3)
            ->count());
    }

    public function test_ranker_persists_rows_with_explainable_payload(): void
    {
        $asset = $this->seedFullAsset('BBCA', sector: 'Banks');

        $ranker = app(WatchlistRanker::class);
        $result = $ranker->rank(Carbon::parse($this->scanDate), [
            'min_turnover' => 100_000,
            'min_brokers' => 1,
            'min_rr' => 0.5,
            'top' => 10,
        ]);

        $this->assertSame(1, $result['evaluated']);
        $this->assertSame(1, $result['persisted']);
        $this->assertCount(1, $result['rows']);

        $row = $result['rows'][0];
        $this->assertSame('BBCA', $row['symbol']);
        $this->assertGreaterThan(0.0, $row['score_total']);
        $this->assertNotEmpty($row['reasons']);
        $this->assertNotEmpty($row['top_brokers']);

        $persisted = WatchlistScore::query()
            ->where('symbol', 'BBCA')
            ->where('scan_date', $this->scanDate)
            ->first();

        $this->assertNotNull($persisted);
        $this->assertSame((int) $asset->id, (int) $persisted->asset_id);
        $this->assertEquals($row['score_total'], (float) $persisted->score_total);
        $this->assertIsArray($persisted->reasons);
    }

    public function test_endpoint_returns_persisted_rows_for_default_latest_date(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $asset = $this->seedFullAsset('BBCA', sector: 'Banks');

        Artisan::call('strategy:rollup-broker-accumulation', [
            '--date' => $this->scanDate,
            '--windows' => '3,5',
        ]);
        Artisan::call('strategy:rank-watchlist', [
            '--date' => $this->scanDate,
            '--min-turnover' => 100_000,
            '--min-brokers' => 1,
            '--min-rr' => 0.5,
            '--top' => 10,
        ]);

        $response = $this->getJson('/api/v1/strategy/watchlist');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame($this->scanDate, $data['scan_date']);
        $this->assertSame('v1', $data['version']);
        $this->assertNotEmpty($data['rows']);
        $this->assertSame('BBCA', $data['rows'][0]['symbol']);
        $this->assertArrayHasKey('disclaimer', $data);
    }

    public function test_endpoint_filters_by_min_score_and_symbol(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $bbca = $this->seedFullAsset('BBCA');
        $tlkm = $this->seedFullAsset('TLKM', sector: 'Telco');

        Artisan::call('strategy:rollup-broker-accumulation', [
            '--date' => $this->scanDate,
            '--windows' => '3,5',
        ]);
        Artisan::call('strategy:rank-watchlist', [
            '--date' => $this->scanDate,
            '--min-turnover' => 100_000,
            '--min-brokers' => 1,
            '--min-rr' => 0.5,
            '--top' => 10,
        ]);

        $response = $this->getJson('/api/v1/strategy/watchlist?symbol=BBCA');
        $response->assertOk();
        $rows = $response->json('data.rows');
        $this->assertCount(1, $rows);
        $this->assertSame('BBCA', $rows[0]['symbol']);

        $highBar = $this->getJson('/api/v1/strategy/watchlist?min_score=99.99');
        $highBar->assertOk();
        $this->assertSame([], $highBar->json('data.rows'));
    }

    public function test_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/strategy/watchlist')
            ->assertStatus(401);
    }

    private function seedFullAsset(string $symbol, ?string $sector = null): Asset
    {
        $asset = Asset::create([
            'symbol' => $symbol,
            'name' => $symbol,
            'sector' => $sector,
        ]);

        // Recent price bars to build a swing low and the latest close.
        $priceData = [
            ['date' => '2026-04-08', 'open' => 9_900, 'high' => 10_100, 'low' => 9_800, 'close' => 10_000, 'volume' => 1_000_000],
            ['date' => '2026-04-09', 'open' => 9_950, 'high' => 10_200, 'low' => 9_850, 'close' => 10_100, 'volume' => 1_100_000],
            ['date' => '2026-04-10', 'open' => 10_100, 'high' => 10_300, 'low' => 10_000, 'close' => 10_200, 'volume' => 1_200_000],
            ['date' => '2026-04-13', 'open' => 10_200, 'high' => 10_400, 'low' => 10_100, 'close' => 10_300, 'volume' => 1_300_000],
            ['date' => '2026-04-14', 'open' => 10_300, 'high' => 10_500, 'low' => 10_200, 'close' => 10_400, 'volume' => 1_500_000],
            ['date' => '2026-04-15', 'open' => 10_400, 'high' => 10_700, 'low' => 10_350, 'close' => 10_650, 'volume' => 2_000_000],
        ];
        foreach ($priceData as $bar) {
            Price::create(['asset_id' => $asset->id] + $bar);
        }

        Metric::create([
            'asset_id' => $asset->id,
            'symbol' => $symbol,
            'name' => $symbol,
            'close' => 10_650,
            'high20' => 10_500,
            'high55' => 11_500,
            'atr14' => 200,
            'roc13' => 0.05,
            'avg_vol20' => 1_500_000,
            'vol_vs_avg20' => 1.33,
            'close_vs_high20' => 1.4,
            'close_vs_high55' => -1.0,
            'uptrend' => 1,
            'bars' => 100,
            'pbas' => 75,
            'bavg' => 10_000,
        ]);

        // features_daily seed for the scan date.
        DB::table('features_daily')->insert([
            'symbol' => $symbol,
            'date' => $this->scanDate,
            'ret_1' => 0.024,
            'range_pct' => 0.034,
            'close_pos' => 0.85,
            'body_to_range' => 0.7,
            'vol_ratio_20' => 1.6,
            'atr_pct' => 0.02,
            'close_vs_ma20' => 0.04,
            'ma20_slope' => 0.01,
            'breakout20' => true,
            'compression' => false,
            'has_broker' => true,
            'turnover_value' => 200_000_000,
            'turnover_volume' => 2_000_000,
            'accdist_score' => 1,
            'avg_net_norm' => 0.05,
            'avg5_net_norm' => 0.04,
            'top1_net_norm' => 0.04,
            'top3_net_norm' => 0.05,
            'top5_net_norm' => 0.05,
            'top10_net_norm' => 0.05,
            'buyer_count' => 10,
            'seller_count' => 5,
            'active_broker_count' => 12,
            'buyer_hhi' => 0.2,
            'seller_hhi' => 0.4,
            'top1_sell_share' => 0.3,
            'top3_sell_share' => 0.6,
            'top5_sell_share' => 0.7,
            'net_to_gross_ratio' => 0.2,
            'foreign_net_norm' => 0.03,
            'local_net_norm' => 0.02,
            'gov_net_norm' => 0.0,
            'absorption_flag' => true,
            'dist_breakdown' => false,
            'stealth_acc' => false,
            'bandar_dist_hard' => false,
            'valid_long_setup' => true,
            'pbas' => 80,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedBrokerFacts($asset->id, [
            ['date' => '2026-04-13', 'broker' => 'AA', 'buy' => 80_000_000, 'sell' => 20_000_000, 'type' => 'foreign'],
            ['date' => '2026-04-14', 'broker' => 'AA', 'buy' => 70_000_000, 'sell' => 30_000_000, 'type' => 'foreign'],
            ['date' => '2026-04-15', 'broker' => 'AA', 'buy' => 90_000_000, 'sell' => 10_000_000, 'type' => 'foreign'],
            ['date' => '2026-04-15', 'broker' => 'BB', 'buy' => 60_000_000, 'sell' => 40_000_000, 'type' => 'local'],
            ['date' => '2026-04-15', 'broker' => 'CC', 'buy' => 30_000_000, 'sell' => 25_000_000, 'type' => 'local'],
            ['date' => '2026-04-15', 'broker' => 'DD', 'buy' => 20_000_000, 'sell' => 15_000_000, 'type' => 'local'],
            ['date' => '2026-04-15', 'broker' => 'EE', 'buy' => 10_000_000, 'sell' => 5_000_000, 'type' => 'local'],
        ]);

        return $asset;
    }

    /**
     * Seed daily broker data the way the importer stores it: one single-day
     * window per date, holding one entry per broker.
     *
     * A day is just a window with from_date === to_date, so this is the same
     * fixture as before expressed in the model that replaced
     * broker_summary_facts -- which now only receives genuinely single-day
     * imports and so no longer feeds the strategy services on its own.
     *
     * @param  array<int, array{date: string, broker: string, buy: int, sell: int, type?: string}>  $rows
     */
    private function seedBrokerFacts(int $assetId, array $rows): void
    {
        foreach ($rows as $r) {
            $window = BrokerSummaryWindow::firstOrCreate([
                'asset_id' => $assetId,
                'from_date' => $r['date'],
                'to_date' => $r['date'],
                'transaction_type' => 'TRANSACTION_TYPE_NET',
            ]);

            $net = $r['buy'] - $r['sell'];

            $window->entries()->create([
                'broker_code' => $r['broker'],
                // Both of Stockbit's lists carry net positions, so the sign is
                // what says which one a broker came from.
                'side' => $net >= 0 ? BrokerSummaryEntry::SIDE_BUY : BrokerSummaryEntry::SIDE_SELL,
                'broker_type' => $r['type'] ?? null,
                'source_date' => $r['date'],
                'net_lot' => 0,
                'net_value' => $net,
                'gross_volume' => $r['buy'] + $r['sell'],
                'gross_value' => $r['buy'] + $r['sell'],
            ]);
        }
    }
}
