<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\Price;
use App\Models\TradingDay;
use App\Services\Assets\AssetCoverage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Coverage is bars measured against the calendar, not a count of bars.
 *
 * Fifty bars is complete for an asset listed ten weeks ago and a hole for one
 * listed two years ago. The Assets page used to show the count alone, and the
 * Trading Days page knew the sessions but nothing about any one asset, so the
 * comparison was made by eye across two pages.
 */
class AssetCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function sessions(string ...$dates): void
    {
        foreach ($dates as $index => $date) {
            TradingDay::create(['date' => $date, 'close' => 7000 + $index]);
        }
    }

    private function asset(string $symbol): Asset
    {
        return Asset::create(['symbol' => $symbol, 'name' => $symbol.' Tbk']);
    }

    private function bars(Asset $asset, string ...$dates): void
    {
        foreach ($dates as $date) {
            Price::create([
                'asset_id' => $asset->id,
                'date' => $date,
                'open' => 100,
                'high' => 110,
                'low' => 95,
                'close' => 105,
                'volume' => 1000,
            ]);
        }
    }

    public function test_an_asset_holding_every_session_in_its_own_span_is_complete(): void
    {
        $this->sessions('2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10');

        $asset = $this->asset('AAA');
        $this->bars($asset, '2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10');

        $coverage = app(AssetCoverage::class)->forAsset($asset->id);

        $this->assertSame(4, $coverage['bars']);
        $this->assertSame(4, $coverage['sessions_expected']);
        $this->assertSame(0, $coverage['sessions_missing']);
        $this->assertSame(0, $coverage['sessions_behind']);
        $this->assertTrue($coverage['complete']);
    }

    /**
     * The number the bar count cannot express.
     */
    public function test_a_session_missing_from_the_middle_is_counted(): void
    {
        $this->sessions('2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10');

        $asset = $this->asset('BBB');
        $this->bars($asset, '2026-09-07', '2026-09-09', '2026-09-10');

        $coverage = app(AssetCoverage::class)->forAsset($asset->id);

        $this->assertSame(3, $coverage['bars']);
        $this->assertSame(4, $coverage['sessions_expected']);
        $this->assertSame(1, $coverage['sessions_missing']);
        $this->assertFalse($coverage['complete']);
        // Its last bar is the newest session, so it is not behind -- only holed.
        $this->assertSame(0, $coverage['sessions_behind']);
    }

    /**
     * An asset listed last week is not missing the years before it existed.
     *
     * The window is per asset for exactly this reason: measured against the
     * whole calendar, every recent listing would report hundreds of gaps and
     * the column would be noise.
     */
    public function test_sessions_before_the_first_bar_are_not_gaps(): void
    {
        $this->sessions('2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04');

        $asset = $this->asset('CCC');
        $this->bars($asset, '2026-09-03', '2026-09-04');

        $coverage = app(AssetCoverage::class)->forAsset($asset->id);

        $this->assertSame(2, $coverage['sessions_expected']);
        $this->assertSame(0, $coverage['sessions_missing']);
        $this->assertTrue($coverage['complete']);
    }

    /**
     * A hole and a stale feed are different failures with different fixes.
     */
    public function test_a_symbol_that_stopped_updating_is_behind_rather_than_holed(): void
    {
        $this->sessions('2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10');

        $asset = $this->asset('DDD');
        $this->bars($asset, '2026-09-07', '2026-09-08');

        $coverage = app(AssetCoverage::class)->forAsset($asset->id);

        $this->assertSame(0, $coverage['sessions_missing'], 'Nothing is missing inside its own span.');
        $this->assertSame(2, $coverage['sessions_behind']);
        $this->assertTrue($coverage['complete']);
        $this->assertSame('2026-09-08', $coverage['last_bar_date']);
    }

    /**
     * A session the calendar has not confirmed is not a session to expect.
     *
     * Counting unconfirmed rows would invent the same gap in every asset at
     * once, which is how a calendar that has stopped advancing would read as
     * fifty-five broken symbols.
     */
    public function test_a_session_with_no_close_is_not_expected(): void
    {
        $this->sessions('2026-09-07', '2026-09-08');
        TradingDay::create(['date' => '2026-09-09', 'close' => null]);

        $asset = $this->asset('EEE');
        $this->bars($asset, '2026-09-07', '2026-09-08');

        $coverage = app(AssetCoverage::class)->forAsset($asset->id);

        $this->assertSame(2, $coverage['sessions_expected']);
        $this->assertSame(0, $coverage['sessions_missing']);
        $this->assertSame(0, $coverage['sessions_behind']);
    }

    /**
     * Absent, not zeroed: "nothing collected yet" is its own state.
     */
    public function test_an_asset_with_no_bars_has_no_coverage_row(): void
    {
        $this->sessions('2026-09-07');

        $asset = $this->asset('FFF');

        $this->assertNull(app(AssetCoverage::class)->forAsset($asset->id));
    }

    /**
     * A bar the calendar does not list is not negative coverage.
     */
    public function test_more_bars_than_sessions_never_reports_a_negative_gap(): void
    {
        $this->sessions('2026-09-07');

        $asset = $this->asset('GGG');
        $this->bars($asset, '2026-09-07', '2026-09-08');

        $coverage = app(AssetCoverage::class)->forAsset($asset->id);

        $this->assertSame(0, $coverage['sessions_missing']);
        $this->assertTrue($coverage['complete']);
    }

    /**
     * Every asset in two queries, whatever the size of the table.
     */
    public function test_the_whole_table_is_read_without_a_query_per_asset(): void
    {
        $this->sessions('2026-09-07', '2026-09-08');

        foreach (['HHH', 'III', 'JJJ'] as $symbol) {
            $this->bars($this->asset($symbol), '2026-09-07', '2026-09-08');
        }

        DB::enableQueryLog();

        $all = app(AssetCoverage::class)->all();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(3, $all);
        $this->assertLessThanOrEqual(2, count($queries), 'Coverage must not cost a query per asset.');
    }
}
