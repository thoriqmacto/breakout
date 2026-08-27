<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\BrokerAccumulationWindow;
use App\Models\BrokerSummaryEntry;
use App\Models\BrokerSummaryWindow;
use App\Services\Strategy\BrokerAccumulationAggregator;
use App\Services\Strategy\BrokerWindowResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Multi-day broker summaries feeding the strategy inputs.
 *
 * broker_summary_facts only receives genuinely single-day imports now, because
 * a Stockbit summary for from=2026-05-26&to=2026-08-26 is one aggregate over
 * three months and storing it as a trading day was the bug the window model
 * removed. The strategy services read windows instead, and these pin the rules
 * that makes safe:
 *
 *   - a ranged import is usable, at its own length, rather than thinning out
 *   - overlapping windows never contribute the same flow twice
 *   - nothing that ended after the date may inform it
 *   - daily data behaves exactly as it did before
 */
class BrokerWindowStrategyInputsTest extends TestCase
{
    use RefreshDatabase;

    private string $scanDate = '2026-04-15';

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asset = Asset::create(['symbol' => 'BBCA', 'name' => 'BCA']);
    }

    /**
     * @param  array<int, array{broker: string, net: int, type?: string}>  $entries
     */
    private function window(string $from, string $to, array $entries): BrokerSummaryWindow
    {
        $window = BrokerSummaryWindow::create([
            'asset_id' => $this->asset->id,
            'from_date' => $from,
            'to_date' => $to,
            'transaction_type' => 'TRANSACTION_TYPE_NET',
        ]);

        foreach ($entries as $entry) {
            $window->entries()->create([
                'broker_code' => $entry['broker'],
                'side' => $entry['net'] >= 0 ? BrokerSummaryEntry::SIDE_BUY : BrokerSummaryEntry::SIDE_SELL,
                'broker_type' => $entry['type'] ?? null,
                'net_value' => $entry['net'],
                'gross_value' => abs($entry['net']),
                'gross_volume' => abs($entry['net']),
            ]);
        }

        return $window->fresh(['entries']);
    }

    private function aggregator(): BrokerAccumulationAggregator
    {
        return app(BrokerAccumulationAggregator::class);
    }

    private function resolver(): BrokerWindowResolver
    {
        return app(BrokerWindowResolver::class);
    }

    /**
     * The point of the change: a three-month import used to produce nothing a
     * strategy could read once it stopped being written to broker_summary_facts.
     */
    public function test_a_multi_day_window_is_rolled_up_at_its_own_length(): void
    {
        $this->window('2026-01-16', '2026-04-15', [
            ['broker' => 'AA', 'net' => 90_000_000],
            ['broker' => 'BB', 'net' => -40_000_000],
        ]);

        $result = $this->aggregator()->rollup(Carbon::parse($this->scanDate), [3, 5, 10, 20]);

        $this->assertSame(1, $result['native_rows']);

        $row = BrokerAccumulationWindow::query()
            ->where('asset_id', $this->asset->id)
            ->whereNotNull('source_window_id')
            ->first();

        $this->assertNotNull($row, 'The ranged import produced no rollup at all.');
        $this->assertSame(90, $row->window_days, 'The window was not recorded at its true length.');
        $this->assertSame('2026-01-16', $row->start_date->toDateString());
        $this->assertSame($this->scanDate, $row->end_date->toDateString());
    }

    /**
     * A three-month aggregate cannot fill a 20-day rollup without claiming flow
     * for days outside it. That is the fabrication this design refuses.
     */
    public function test_a_window_longer_than_the_rollup_never_feeds_it(): void
    {
        $this->window('2026-01-16', '2026-04-15', [['broker' => 'AA', 'net' => 90_000_000]]);

        $this->aggregator()->rollup(Carbon::parse($this->scanDate), [20]);

        $this->assertSame(
            0,
            BrokerAccumulationWindow::query()->where('window_days', 20)->count(),
            'A 90-day aggregate was used to fill a 20-day rollup.',
        );
    }

    /**
     * Two windows covering the same days are the same flow reported twice.
     */
    public function test_overlapping_windows_are_not_counted_twice(): void
    {
        $this->window('2026-04-13', '2026-04-15', [['broker' => 'AA', 'net' => 30_000_000]]);
        $this->window('2026-04-14', '2026-04-15', [['broker' => 'AA', 'net' => 20_000_000]]);

        $tiling = $this->resolver()->tiling(
            $this->asset->id,
            Carbon::parse('2026-04-13'),
            Carbon::parse($this->scanDate),
            'TRANSACTION_TYPE_NET',
        );

        $this->assertCount(1, $tiling, 'Both overlapping windows were used.');
        // The longer window carries more evidence, so it wins the space.
        $this->assertSame('2026-04-13', $tiling[0]->from_date->toDateString());
        $this->assertSame(3, $this->resolver()->coveredDays($tiling));
    }

    /**
     * Non-overlapping windows tile, and the rollup says how much of its range
     * was actually covered rather than presenting a thin one as complete.
     */
    public function test_a_tiling_records_how_much_of_the_range_it_covered(): void
    {
        $this->window('2026-04-13', '2026-04-14', [['broker' => 'AA', 'net' => 10_000_000]]);
        $this->window('2026-04-15', '2026-04-15', [['broker' => 'AA', 'net' => 5_000_000]]);

        $this->aggregator()->rollup(Carbon::parse($this->scanDate), [10]);

        $row = BrokerAccumulationWindow::query()->where('window_days', 10)->first();

        $this->assertNotNull($row);
        $this->assertSame(3, $row->covered_days, 'Three days of data were reported as a full ten.');
        $this->assertSame('2026-04-06', $row->start_date->toDateString());
    }

    /**
     * Lookahead. A window running past the scan date holds trading that had not
     * happened yet, and using it silently flatters a backtest.
     */
    public function test_a_window_ending_after_the_date_never_informs_it(): void
    {
        $this->window('2026-04-10', '2026-04-30', [['broker' => 'AA', 'net' => 90_000_000]]);

        $this->assertNull(
            $this->resolver()->asOf($this->asset->id, Carbon::parse($this->scanDate), 'TRANSACTION_TYPE_NET'),
            'A window extending past the scan date was used to describe it.',
        );

        $this->aggregator()->rollup(Carbon::parse($this->scanDate), [3, 5, 10, 20]);

        $this->assertSame(0, BrokerAccumulationWindow::query()->count());
    }

    /**
     * Where a day and a range both end on the date, the day is the more precise
     * answer, so daily precision is never lost to a range that happens to exist.
     */
    public function test_as_of_prefers_the_narrower_window(): void
    {
        $this->window('2026-04-01', '2026-04-15', [['broker' => 'WIDE', 'net' => 1_000]]);
        $this->window('2026-04-15', '2026-04-15', [['broker' => 'NARROW', 'net' => 2_000]]);

        $window = $this->resolver()->asOf(
            $this->asset->id,
            Carbon::parse($this->scanDate),
            'TRANSACTION_TYPE_NET',
        );

        $this->assertNotNull($window);
        $this->assertTrue($window->isSingleDay());
        $this->assertSame('NARROW', $window->entries->first()->broker_code);
    }

    /**
     * A window that ended long ago is not the broker picture for today.
     */
    public function test_a_stale_window_is_not_used_as_the_current_picture(): void
    {
        $this->window('2026-01-05', '2026-01-05', [['broker' => 'AA', 'net' => 1_000]]);

        config(['stockbit.strategy.max_window_staleness_days' => 7]);

        $this->assertNull($this->resolver()->asOf(
            $this->asset->id,
            Carbon::parse($this->scanDate),
            'TRANSACTION_TYPE_NET',
        ));

        // The bound is configurable, not a hard rule about the data.
        config(['stockbit.strategy.max_window_staleness_days' => null]);

        $this->assertNotNull($this->resolver()->asOf(
            $this->asset->id,
            Carbon::parse($this->scanDate),
            'TRANSACTION_TYPE_NET',
        ));
    }

    /**
     * Both of Stockbit's lists hold net positions, so a broker's value is
     * already signed. Recomputing a net as buy minus sell would double it.
     */
    public function test_entry_signs_are_summed_as_given(): void
    {
        $this->window('2026-04-15', '2026-04-15', [
            ['broker' => 'AA', 'net' => 60_000_000, 'type' => 'Asing'],
            ['broker' => 'BB', 'net' => -20_000_000, 'type' => 'Lokal'],
        ]);

        $this->aggregator()->rollup(Carbon::parse($this->scanDate), [3]);

        $row = BrokerAccumulationWindow::query()->where('window_days', 3)->first();

        $this->assertNotNull($row);
        $this->assertSame(2, $row->broker_count);
        // Turnover is the size of the flow: 60m + 20m, not 40m.
        $this->assertSame(80_000_000.0, (float) $row->value);
        // Net is +40m over 2 brokers, normalised by that 80m.
        $this->assertEqualsWithDelta(0.25, (float) $row->avg_net_norm, 0.0001);
    }

    /**
     * Live payloads label brokers in Indonesian. The aggregator tested for
     * lowercase "foreign"/"local", so these two were always zero on real data.
     */
    public function test_indonesian_broker_types_reach_the_foreign_and_local_buckets(): void
    {
        $this->window('2026-04-15', '2026-04-15', [
            ['broker' => 'AA', 'net' => 60_000_000, 'type' => 'Asing'],
            ['broker' => 'BB', 'net' => -20_000_000, 'type' => 'Lokal'],
        ]);

        $this->aggregator()->rollup(Carbon::parse($this->scanDate), [3]);

        $row = BrokerAccumulationWindow::query()->where('window_days', 3)->first();

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(0.75, (float) $row->foreign_net_norm, 0.0001);
        $this->assertEqualsWithDelta(-0.25, (float) $row->local_net_norm, 0.0001);
    }

    /**
     * Single-day imports must not gain a window_days = 1 input they never had.
     */
    public function test_daily_data_produces_no_native_rows(): void
    {
        $this->window('2026-04-14', '2026-04-14', [['broker' => 'AA', 'net' => 10_000_000]]);
        $this->window('2026-04-15', '2026-04-15', [['broker' => 'AA', 'net' => 5_000_000]]);

        $result = $this->aggregator()->rollup(Carbon::parse($this->scanDate), [3, 5]);

        $this->assertSame(0, $result['native_rows']);
        $this->assertSame(0, BrokerAccumulationWindow::query()->whereNotNull('source_window_id')->count());
    }

    /**
     * A rerun upserts rather than duplicating, native rows included.
     */
    public function test_the_rollup_is_idempotent_for_ranged_imports(): void
    {
        $this->window('2026-04-01', '2026-04-15', [['broker' => 'AA', 'net' => 90_000_000]]);

        $first = $this->aggregator()->rollup(Carbon::parse($this->scanDate), [3, 5]);
        $second = $this->aggregator()->rollup(Carbon::parse($this->scanDate), [3, 5]);

        $this->assertSame($first['rows_written'], $second['rows_written']);
        $this->assertSame(1, BrokerAccumulationWindow::query()->count());
    }
}
