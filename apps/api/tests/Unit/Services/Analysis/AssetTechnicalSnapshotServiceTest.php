<?php

namespace Tests\Unit\Services\Analysis;

use App\Models\Asset;
use App\Models\Price;
use App\Services\Analysis\AssetTechnicalSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The snapshot service is the single source of every technical number in the
 * app, so the two properties that matter are that it is deterministic and that
 * it cannot see the future.
 */
class AssetTechnicalSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    private AssetTechnicalSnapshotService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AssetTechnicalSnapshotService::class);
    }

    /**
     * A deterministic ramp: close rises 5 a session, with a fixed 20-wide bar.
     */
    private function assetWithBars(string $symbol, string $from, int $sessions, float $startClose = 1000.0): Asset
    {
        $asset = Asset::create(['symbol' => $symbol, 'name' => $symbol]);

        $cursor = Carbon::parse($from);
        $close = $startClose;
        $written = 0;

        while ($written < $sessions) {
            if ($cursor->dayOfWeekIso >= 6) {
                $cursor->addDay();

                continue;
            }

            Price::create([
                'asset_id' => $asset->id,
                'date' => $cursor->toDateString(),
                'open' => $close - 5,
                'high' => $close + 10,
                'low' => $close - 10,
                'close' => $close,
                'volume' => 1_000_000,
            ]);

            $close += 5;
            $written++;
            $cursor->addDay();
        }

        return $asset;
    }

    public function test_a_snapshot_is_built_from_the_last_session_at_or_before_the_requested_date(): void
    {
        $asset = $this->assetWithBars('BBCA', '2026-01-05', 40);

        // 2026-02-28 is a Saturday; the last session is the Friday before it.
        $snapshot = $this->service->snapshotForAssetAsOf($asset, Carbon::parse('2026-02-28'));

        $this->assertNotNull($snapshot);
        $this->assertSame('2026-02-28', $snapshot->requestedAsOf);
        $this->assertSame('2026-02-27', $snapshot->asOfDate);
    }

    public function test_an_as_of_snapshot_ignores_every_later_bar(): void
    {
        $asset = $this->assetWithBars('BBCA', '2026-01-05', 120);

        $cut = Price::query()
            ->where('asset_id', $asset->id)
            ->orderBy('date')
            ->skip(79)
            ->take(1)
            ->value('date');

        $asOf = Carbon::parse((string) $cut);

        $withFuture = $this->service->snapshotForAssetAsOf($asset, $asOf);

        // Delete everything after the as-of date and recompute. If any formula
        // reached forward, these two disagree.
        Price::query()->where('asset_id', $asset->id)->whereDate('date', '>', $asOf->toDateString())->delete();

        $withoutFuture = $this->service->snapshotForAssetAsOf($asset, $asOf);

        $this->assertNotNull($withFuture);
        $this->assertNotNull($withoutFuture);
        $this->assertSame($withFuture->toArray(), $withoutFuture->toArray());
    }

    public function test_the_bounded_lookback_does_not_change_the_answer_for_a_long_history(): void
    {
        // Deeper than LOOKBACK_BARS, so the window genuinely truncates.
        $asset = $this->assetWithBars('BBCA', '2024-01-01', 400);

        $snapshot = $this->service->snapshotForAssetAsOf($asset, Carbon::parse('2026-01-01'));

        $this->assertNotNull($snapshot);
        // `bars` reports the asset's real depth, not the loaded window.
        $this->assertSame(400, $snapshot->bars);
        // Every formula still resolved, so nothing was starved by the bound.
        $this->assertNotNull($snapshot->ma150);
        $this->assertNotNull($snapshot->high55w);
        $this->assertNotNull($snapshot->roc13);
    }

    public function test_roc13_is_null_rather_than_zero_when_there_is_no_comparison_bar(): void
    {
        $asset = $this->assetWithBars('BBCA', '2026-01-05', 20);

        $snapshot = $this->service->snapshotForAssetAsOf($asset, Carbon::parse('2026-03-31'));

        // Zero would read as "flat" and rank this asset above a genuinely
        // falling one. Null says "unknown", which is the truth.
        $this->assertNull($snapshot?->roc13);
        $this->assertNotEmpty($snapshot?->warnings);
    }

    public function test_the_breakout_reference_excludes_the_session_being_measured(): void
    {
        $asset = $this->assetWithBars('BBCA', '2026-01-05', 40);

        $snapshot = $this->service->snapshotForAssetAsOf($asset, Carbon::parse('2026-02-27'));

        $this->assertNotNull($snapshot);

        // On a rising ramp the highest of the twenty sessions before this one
        // is simply the previous session's high. Today's own high must not be
        // in the reference, or every rising day would "break out" of itself.
        $previousHigh = (float) Price::query()
            ->where('asset_id', $asset->id)
            ->whereDate('date', '<', $snapshot->asOfDate)
            ->orderByDesc('date')
            ->value('high');

        $this->assertSame($previousHigh, $snapshot->priorHigh20);
        $this->assertLessThan($snapshot->high, $snapshot->priorHigh20);

        // The ramp's daily range is wider than its daily drift, so the close
        // does not clear the previous high and this is not a breakout.
        $this->assertFalse($snapshot->isBreakout20());

        // One session that closes above that reference is.
        Price::create([
            'asset_id' => $asset->id,
            'date' => '2026-03-02',
            'open' => $snapshot->close,
            'high' => $snapshot->priorHigh20 + 100,
            'low' => $snapshot->close,
            'close' => $snapshot->priorHigh20 + 50,
            'volume' => 5_000_000,
        ]);

        $after = $this->service->snapshotForAssetAsOf($asset, Carbon::parse('2026-03-02'));

        $this->assertTrue($after?->isBreakout20());
    }

    public function test_structural_rank_orders_on_trend_then_momentum(): void
    {
        $strong = $this->assetWithBars('AAAA', '2026-01-05', 120, 1000.0);
        $weak = Asset::create(['symbol' => 'ZZZZ', 'name' => 'ZZZZ']);

        // A falling series: below its own 150-day average, so not in uptrend.
        $cursor = Carbon::parse('2026-01-05');
        $close = 2000.0;
        for ($i = 0; $i < 120; $i++) {
            while ($cursor->dayOfWeekIso >= 6) {
                $cursor->addDay();
            }
            Price::create([
                'asset_id' => $weak->id,
                'date' => $cursor->toDateString(),
                'open' => $close + 5,
                'high' => $close + 10,
                'low' => $close - 10,
                'close' => $close,
                'volume' => 1_000_000,
            ]);
            $close -= 5;
            $cursor->addDay();
        }

        $snapshots = $this->service->snapshotsForAssetsAsOf([$strong, $weak], Carbon::parse('2026-07-01'));
        $ranks = $this->service->structuralRanks($snapshots);

        $this->assertSame(1, $ranks['AAAA']);
        $this->assertSame(2, $ranks['ZZZZ']);
    }

    public function test_an_asset_with_no_bar_before_the_as_of_date_has_no_snapshot(): void
    {
        $asset = $this->assetWithBars('BBCA', '2026-06-01', 10);

        $this->assertNull($this->service->snapshotForAssetAsOf($asset, Carbon::parse('2026-01-01')));
    }
}
