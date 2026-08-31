<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Metric;
use App\Models\Price;
use App\Models\User;
use App\Services\Analysis\AssetTechnicalSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The CLI and the web API must not be able to disagree.
 *
 * They used to. `asset:metrics` ranked on ROC13 in the second position while
 * `GET /v1/assets/metrics` ranked on PBAS there, and both printed the result in
 * a column headed "Rank" -- so the same asset carried two different ranks
 * depending on which surface you opened, with nothing on either screen saying
 * so. These tests pin the single canonical answer.
 */
class MetricConsistencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, float>  $closes
     */
    private function seedAsset(string $symbol, array $closes, int $volumeBase = 1000): Asset
    {
        $asset = Asset::create(['symbol' => $symbol, 'name' => 'Asset '.$symbol]);
        $start = Carbon::parse('2024-01-01');

        foreach ($closes as $i => $close) {
            Price::create([
                'asset_id' => $asset->id,
                'date' => $start->copy()->addDays($i)->toDateString(),
                'open' => $close,
                'high' => $close + 1,
                'low' => $close - 1,
                'close' => $close,
                'volume' => $volumeBase + $i,
            ]);
        }

        return $asset;
    }

    public function test_the_cli_the_service_and_the_api_report_the_same_figures(): void
    {
        $asset = $this->seedAsset('AAA', range(1, 120));

        $snapshot = app(AssetTechnicalSnapshotService::class)->snapshotForAssetAsOf($asset);
        $this->assertNotNull($snapshot);

        // The CLI prints what the service computed.
        Artisan::call('asset:metrics', ['--sym' => 'AAA']);
        $cli = Artisan::output();

        $this->assertStringContainsString((string) round($snapshot->close, 2), $cli);
        $this->assertStringContainsString((string) round((float) $snapshot->atr14), $cli);
        $this->assertStringContainsString((string) round((float) $snapshot->high55w), $cli);

        // The API persists and serves the same numbers.
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/assets/metrics/update')->assertOk();

        $row = $this->getJson('/api/v1/assets/metrics')
            ->assertOk()
            ->json('data.metrics.0');

        $this->assertSame('AAA', $row['symbol']);
        $this->assertEqualsWithDelta(round($snapshot->close, 2), (float) $row['close'], 0.0001);
        $this->assertEqualsWithDelta(round((float) $snapshot->atr14), (float) $row['atr14'], 0.0001);
        $this->assertEqualsWithDelta(round((float) $snapshot->high20w), (float) $row['high20'], 0.0001);
        $this->assertEqualsWithDelta(round((float) $snapshot->high55w), (float) $row['high55'], 0.0001);
        $this->assertEqualsWithDelta(round((float) $snapshot->roc13, 2), (float) $row['roc13'], 0.0001);
        $this->assertEqualsWithDelta(round((float) $snapshot->volRatio20, 4), (float) $row['vol_vs_avg20'], 0.0001);
        $this->assertSame($snapshot->uptrend, (bool) $row['uptrend']);
    }

    public function test_the_cli_and_the_api_rank_the_same_universe_identically(): void
    {
        // Deliberately built so PBAS would reorder them if it were still in the
        // structural key: the weakest structural name gets the best PBAS.
        $this->seedAsset('AAA', range(1, 120));
        $this->seedAsset('BBB', array_merge(range(1, 119), [60.0]));
        $this->seedAsset('CCC', array_merge(range(200, 82, -1), [120.0]));

        foreach ([['AAA', 10], ['BBB', 50], ['CCC', 99]] as [$symbol, $pbas]) {
            DB::table('features_daily')->insert([
                'symbol' => $symbol,
                'date' => Carbon::parse('2024-01-01')->addDays(119)->toDateString(),
                'pbas' => $pbas,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Artisan::call('asset:metrics', ['--all' => true]);
        $cli = Artisan::output();

        $cliOrder = [];
        foreach (['AAA', 'BBB', 'CCC'] as $symbol) {
            $cliOrder[$symbol] = strpos($cli, $symbol);
        }
        asort($cliOrder);
        $cliOrder = array_keys($cliOrder);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/assets/metrics/update')->assertOk();

        $apiOrder = collect($this->getJson('/api/v1/assets/metrics')->assertOk()->json('data.metrics'))
            ->pluck('symbol')
            ->all();

        $this->assertSame($cliOrder, $apiOrder);

        // And PBAS, high on the structurally weakest name, did not move it.
        $this->assertSame('CCC', end($apiOrder));
    }

    public function test_structural_rank_is_exposed_alongside_the_legacy_rank_key(): void
    {
        $this->seedAsset('AAA', range(1, 120));

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/assets/metrics/update')->assertOk();

        $row = $this->getJson('/api/v1/assets/metrics')->assertOk()->json('data.metrics.0');

        $this->assertSame(1, $row['rank']);
        $this->assertSame(1, $row['structural_rank']);
    }

    public function test_the_metrics_cache_drops_assets_that_lost_their_price_history(): void
    {
        $asset = $this->seedAsset('AAA', range(1, 120));

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/assets/metrics/update')->assertOk();
        $this->assertSame(1, Metric::query()->count());

        Price::query()->where('asset_id', $asset->id)->delete();
        $this->postJson('/api/v1/assets/metrics/update')->assertOk();

        $this->assertSame(0, Metric::query()->count());
    }
}
