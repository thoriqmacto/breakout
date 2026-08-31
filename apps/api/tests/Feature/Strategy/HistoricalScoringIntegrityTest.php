<?php

namespace Tests\Feature\Strategy;

use App\Models\Asset;
use App\Models\Metric;
use App\Models\Price;
use App\Models\WatchlistScore;
use App\Services\Strategy\WatchlistRanker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A score for a past session must describe that session.
 *
 * The ranker used to take ATR14 and the 55-week high from the `metrics` table,
 * which holds one row per asset describing the latest session regardless of
 * what is being scored. Backfilling March therefore stopped March's trades
 * against September's volatility and September's highs.
 */
class HistoricalScoringIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asset = Asset::create(['symbol' => 'AAA', 'name' => 'Asset AAA']);
    }

    /**
     * A calm ramp, then a violent one. The two regimes have very different
     * ATRs, which is what makes reading the wrong one visible.
     */
    private function seedBars(): void
    {
        $cursor = Carbon::parse('2026-01-05');
        $close = 1000.0;

        for ($i = 0; $i < 120; $i++) {
            while ($cursor->dayOfWeekIso >= 6) {
                $cursor->addDay();
            }

            $volatile = $i >= 60;
            $spread = $volatile ? 200.0 : 10.0;
            $step = $volatile ? 80.0 : 2.0;

            Price::create([
                'asset_id' => $this->asset->id,
                'date' => $cursor->toDateString(),
                'open' => $close,
                'high' => $close + $spread,
                'low' => $close - $spread,
                'close' => $close,
                'volume' => 10_000_000,
            ]);

            $close += $step;
            $cursor->addDay();
        }
    }

    private function nthSession(int $offset): Carbon
    {
        return Carbon::parse((string) Price::query()
            ->where('asset_id', $this->asset->id)
            ->orderBy('date')
            ->skip($offset)
            ->take(1)
            ->value('date'));
    }

    private function seedFeatures(string $symbol, string $date, int $pbas = 80, bool $strong = true): void
    {
        DB::table('features_daily')->insert([
            'symbol' => $symbol,
            'date' => $date,
            'breakout20' => $strong,
            'close_pos' => $strong ? 0.9 : 0.2,
            'vol_ratio_20' => $strong ? 2.0 : 0.5,
            'turnover_value' => 50_000_000_000,
            'active_broker_count' => 20,
            'pbas' => $pbas,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_historical_score_ignores_the_latest_metrics_row(): void
    {
        $this->seedBars();

        $calmDate = $this->nthSession(59);
        $this->seedFeatures('AAA', $calmDate->toDateString());

        // A latest-session cache row describing the violent regime. If the
        // ranker reads it, the calm day inherits an ATR twenty times too wide
        // and a stop nowhere near the price.
        Metric::create([
            'asset_id' => $this->asset->id,
            'symbol' => 'AAA',
            'name' => 'Asset AAA',
            'close' => 9999,
            'atr14' => 400,
            'high55' => 9999,
            'uptrend' => true,
            'bars' => 120,
        ]);

        $row = collect(app(WatchlistRanker::class)->rank($calmDate)['rows'])->firstWhere('symbol', 'AAA');

        $this->assertNotNull($row);

        // The close is the calm session's, not the cache's 9999.
        $this->assertLessThan(2000.0, $row['close']);

        // The stop sits within a calm session's reach of that close. With the
        // cached ATR of 400 it would be 800 below.
        $this->assertNotNull($row['invalidation_level']);
        $this->assertLessThan(100.0, $row['close'] - $row['invalidation_level']);
    }

    public function test_the_same_scan_date_scores_identically_with_and_without_later_bars(): void
    {
        $this->seedBars();

        $scanDate = $this->nthSession(79);
        $this->seedFeatures('AAA', $scanDate->toDateString());

        $withFuture = app(WatchlistRanker::class)->rank($scanDate);

        Price::query()
            ->where('asset_id', $this->asset->id)
            ->whereDate('date', '>', $scanDate->toDateString())
            ->delete();

        $withoutFuture = app(WatchlistRanker::class)->rank($scanDate);

        $strip = static fn (array $result): array => array_map(static function (array $row): array {
            unset($row['snapshot']);

            return $row;
        }, $result['rows']);

        $this->assertSame($strip($withFuture), $strip($withoutFuture));
    }

    public function test_every_evaluated_asset_is_persisted_even_when_the_caller_asks_for_a_few(): void
    {
        $this->seedBars();

        $scanDate = $this->nthSession(79);
        $this->seedFeatures('AAA', $scanDate->toDateString());

        $other = Asset::create(['symbol' => 'BBB', 'name' => 'Asset BBB']);
        foreach (Price::query()->where('asset_id', $this->asset->id)->orderBy('date')->get() as $bar) {
            Price::create([
                'asset_id' => $other->id,
                'date' => $bar->date->toDateString(),
                'open' => $bar->open,
                'high' => $bar->high,
                'low' => $bar->low,
                'close' => $bar->close,
                'volume' => $bar->volume,
            ]);
        }
        $this->seedFeatures('BBB', $scanDate->toDateString(), 10, false);

        $result = app(WatchlistRanker::class)->rank($scanDate, ['top' => 1]);

        // The caller sees one row; research keeps both. Persisting only the
        // displayed slice makes every later statistic a claim about an
        // already-selected population while reading like one about the
        // universe.
        $this->assertCount(1, $result['rows']);
        $this->assertSame(2, $result['scored']);
        $this->assertSame(2, WatchlistScore::query()->where('scan_date', $scanDate->toDateString())->count());
    }
}
