<?php

namespace Tests\Feature\Execution;

use App\Models\Asset;
use App\Models\BrokerSummaryWindow;
use App\Models\Price;
use App\Models\User;
use App\Models\WatchlistScore;
use App\Services\Execution\ExecutionCandidateService;
use App\Services\Execution\ExecutionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The workspace's contract: one list, one signal date, an explicit next
 * session, and a status that explains itself.
 */
class ExecutionCandidateTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Asset> */
    private array $assets = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'execution.min_score' => 75.0,
            'execution.min_rr' => 2.0,
            'execution.max_entry_gap_pct' => null,
        ]);
    }

    /**
     * Weekday sessions from 2026-01-05, with 2026-02-27 (a Friday) as the last
     * one, so "next trading date" has a weekend to step over.
     *
     * @return array<int, string> the session dates written
     */
    private function seedSessions(string $symbol, float $start = 1000.0, float $step = 5.0, int $count = 40): array
    {
        $asset = Asset::create(['symbol' => $symbol, 'name' => 'Asset '.$symbol, 'sector' => 'Energy']);
        $this->assets[$symbol] = $asset;

        $cursor = Carbon::parse('2026-01-05');
        $close = $start;
        $dates = [];

        while (count($dates) < $count) {
            if ($cursor->dayOfWeekIso >= 6) {
                $cursor->addDay();

                continue;
            }

            Price::create([
                'asset_id' => $asset->id,
                'date' => $cursor->toDateString(),
                'open' => $close - 2,
                'high' => $close + 3,
                'low' => $close - 5,
                'close' => $close,
                'volume' => 10_000_000,
            ]);

            $this->tradingDay($cursor->toDateString());

            $dates[] = $cursor->toDateString();
            $close += $step;
            $cursor->addDay();
        }

        // Two more sessions on the calendar only, so the workspace has a real
        // next-session answer that is not signal date + 1.
        $this->tradingDay('2026-03-02');
        $this->tradingDay('2026-03-03');

        return $dates;
    }

    /**
     * The model casts `date`, so firstOrCreate writes "Y-m-d 00:00:00" and
     * then fails to match it back on a plain "Y-m-d" lookup.
     */
    private function tradingDay(string $date): void
    {
        DB::table('trading_days')->insertOrIgnore(['date' => $date]);
    }

    private function seedFeature(string $symbol, string $date, array $overrides = []): void
    {
        DB::table('features_daily')->insert(array_merge([
            'symbol' => $symbol,
            'date' => $date,
            'breakout20' => true,
            'close_pos' => 0.9,
            'vol_ratio_20' => 2.0,
            'turnover_value' => 50_000_000_000,
            'active_broker_count' => 20,
            'pbas' => 82,
            'valid_long_setup' => true,
            'bandar_dist_hard' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * Broker coverage for the signal session.
     *
     * Without this every row is STALE_DATA at the lifecycle level, whatever
     * its setup -- freshness gates everything actionable. These tests ran for
     * a long time without it, asserting only the v1 status, which is how the
     * lifecycle filter stayed broken while the suite stayed green.
     */
    private function seedBrokerWindow(string $symbol, string $date): void
    {
        BrokerSummaryWindow::create([
            'asset_id' => $this->assets[$symbol]->id,
            'from_date' => $date,
            'to_date' => $date,
            'transaction_type' => (string) config('stockbit.defaults.transaction_type'),
        ]);
    }

    private function seedScore(string $symbol, string $date, array $overrides = []): WatchlistScore
    {
        return WatchlistScore::create(array_merge([
            'scan_date' => $date,
            'symbol' => $symbol,
            'asset_id' => $this->assets[$symbol]->id,
            'version' => 'v1',
            'close' => 1000,
            'score_total' => 88.0,
            'score_bas' => 90.0,
            'score_bcs' => 85.0,
            'lf_pass' => true,
            'rrf_pass' => true,
            'risk_reward' => 3.0,
            'invalidation_level' => 900,
            'take_profit' => 1300,
            'top_brokers' => [],
            'reasons' => [],
        ], $overrides));
    }

    private function candidates(array $options = []): array
    {
        return app(ExecutionCandidateService::class)->candidates($options);
    }

    public function test_the_next_trading_date_steps_over_the_weekend(): void
    {
        $dates = $this->seedSessions('AAA');
        $signal = end($dates);

        $this->seedFeature('AAA', $signal);
        $this->seedScore('AAA', $signal);

        $result = $this->candidates();

        $this->assertSame('2026-02-27', $result['signal_date']);
        $this->assertSame('Friday', Carbon::parse($result['signal_date'])->format('l'));

        // Not signal date + 1, which is a Saturday.
        $this->assertSame('2026-03-02', $result['next_trading_date']);
    }

    public function test_a_strong_setup_is_ready_and_says_why(): void
    {
        $dates = $this->seedSessions('AAA');
        $signal = end($dates);

        $this->seedFeature('AAA', $signal);
        $this->seedScore('AAA', $signal);

        $row = $this->candidates()['rows'][0];

        $this->assertSame(ExecutionStatus::READY, $row['execution_status']);
        $this->assertSame(1, $row['execution_rank']);
        $this->assertNotEmpty($row['status_reasons']);
    }

    public function test_the_entry_trigger_sits_above_everything_that_traded_at_the_signal(): void
    {
        $dates = $this->seedSessions('AAA');
        $signal = end($dates);

        $this->seedFeature('AAA', $signal);
        $this->seedScore('AAA', $signal);

        $row = $this->candidates()['rows'][0];

        // A signal computed from T's close cannot be filled at T's close. The
        // trigger must be a level the *next* session has to reach.
        $this->assertNotNull($row['planned_entry_trigger']);
        $this->assertGreaterThan($row['signal_close'], $row['planned_entry_trigger']);
        $this->assertGreaterThan($row['signal_high'], $row['planned_entry_trigger']);
        $this->assertNotSame('', $row['planned_entry_reason']);
    }

    public function test_a_setup_that_only_clears_the_minimum_at_the_close_is_not_ready(): void
    {
        $dates = $this->seedSessions('AAA');
        $signal = end($dates);

        $this->seedFeature('AAA', $signal);

        // The watchlist's own R/R, measured at the close, passes its filter.
        $this->seedScore('AAA', $signal, ['rrf_pass' => true, 'risk_reward' => 2.4]);

        // But the target is only just above the trigger, so the plan's R/R at
        // the price actually paid is far below the minimum.
        config(['execution.min_rr' => 50.0]);

        $row = $this->candidates()['rows'][0];

        $this->assertSame(ExecutionStatus::WATCH, $row['execution_status']);
        $this->assertNotNull($row['planned_risk_reward']);
        $this->assertLessThan(50.0, $row['planned_risk_reward']);
        $this->assertStringContainsString('entry trigger', implode(' ', $row['status_reasons']));
    }

    public function test_hard_distribution_is_avoided(): void
    {
        $dates = $this->seedSessions('AAA');
        $signal = end($dates);

        $this->seedFeature('AAA', $signal, ['bandar_dist_hard' => true, 'valid_long_setup' => false]);
        $this->seedScore('AAA', $signal);

        $row = $this->candidates()['rows'][0];

        $this->assertSame(ExecutionStatus::AVOID, $row['execution_status']);
        $this->assertStringContainsString('distribution', implode(' ', $row['status_reasons']));
    }

    public function test_a_signal_behind_the_latest_session_is_stale(): void
    {
        $dates = $this->seedSessions('AAA');
        $signal = $dates[count($dates) - 3];

        $this->seedFeature('AAA', $signal);
        $this->seedScore('AAA', $signal);

        $row = $this->candidates(['date' => $signal])['rows'][0];

        $this->assertSame(ExecutionStatus::STALE, $row['execution_status']);
        $this->assertFalse($this->candidates(['date' => $signal])['freshness']['signal_is_latest_session']);
    }

    public function test_below_threshold_scores_watch_rather_than_ready(): void
    {
        $dates = $this->seedSessions('AAA');
        $signal = end($dates);

        $this->seedFeature('AAA', $signal);
        $this->seedScore('AAA', $signal, ['score_total' => 60.0]);

        $row = $this->candidates()['rows'][0];

        $this->assertSame(ExecutionStatus::WATCH, $row['execution_status']);
        $this->assertStringContainsString('below the 75.0 threshold', implode(' ', $row['status_reasons']));
    }

    public function test_rank_movement_is_reported_against_the_previous_session(): void
    {
        $dates = $this->seedSessions('AAA');
        $this->seedSessions('BBB', 500.0, 4.0);

        $previous = $dates[count($dates) - 2];
        $signal = end($dates);

        foreach (['AAA', 'BBB'] as $symbol) {
            $this->seedFeature($symbol, $signal);
        }

        // Yesterday BBB led; today AAA does.
        $this->seedScore('AAA', $previous, ['score_total' => 70.0]);
        $this->seedScore('BBB', $previous, ['score_total' => 90.0]);
        $this->seedScore('AAA', $signal, ['score_total' => 95.0]);
        $this->seedScore('BBB', $signal, ['score_total' => 80.0]);

        $rows = collect($this->candidates()['rows'])->keyBy('symbol');

        $this->assertSame(1, $rows['AAA']['execution_rank']);
        $this->assertSame(2, $rows['AAA']['previous_execution_rank']);
        $this->assertSame(1, $rows['AAA']['execution_rank_change_1d']);
        $this->assertEqualsWithDelta(25.0, $rows['AAA']['execution_score_change_1d'], 0.0001);
    }

    public function test_the_endpoint_returns_the_composed_payload(): void
    {
        $dates = $this->seedSessions('AAA');
        $signal = end($dates);

        $this->seedFeature('AAA', $signal);
        $this->seedScore('AAA', $signal);
        $this->seedBrokerWindow('AAA', $signal);

        Sanctum::actingAs(User::factory()->create());

        $payload = $this->getJson('/api/v1/execution/candidates')->assertOk()->json('data');

        $this->assertSame('2026-02-27', $payload['signal_date']);
        $this->assertSame('2026-03-02', $payload['next_trading_date']);
        // Counted on the status the interface shows. This asserted
        // counts['READY'] -- the v1 status -- while the table rendered the
        // lifecycle one, so the card could read 1 above a row labelled
        // something else entirely.
        $this->assertSame('TRIGGERED', $payload['rows'][0]['lifecycle_status']);
        $this->assertSame(1, $payload['counts']['TRIGGERED']);
        $this->assertNotEmpty($payload['disclaimer']);
        $this->assertArrayHasKey('latest_price_date', $payload['freshness']);
        $this->assertSame('AAA', $payload['rows'][0]['symbol']);
        $this->assertArrayHasKey('structural_rank', $payload['rows'][0]);
        $this->assertArrayHasKey('execution_rank', $payload['rows'][0]);
    }

    /**
     * Filtering by a status returns rows that show that status.
     *
     * Obvious enough to have gone unasserted, and it was false: the filter
     * matched the v1 `execution_status` while the interface displayed
     * `lifecycle_status`. Selecting READY returned a row labelled AVOID --
     * both fields correct, describing different things -- and the seven
     * lifecycle-only statuses could never match anything at all.
     */
    public function test_the_status_filter_selects_the_status_the_rows_display(): void
    {
        $dates = $this->seedSessions('AAA');
        $this->seedSessions('BBB', 500.0, 4.0);
        $signal = end($dates);

        $this->seedFeature('AAA', $signal);
        $this->seedFeature('BBB', $signal, ['bandar_dist_hard' => true, 'valid_long_setup' => false]);
        $this->seedScore('AAA', $signal);
        $this->seedScore('BBB', $signal);
        $this->seedBrokerWindow('AAA', $signal);
        $this->seedBrokerWindow('BBB', $signal);

        $all = $this->candidates();
        $this->assertSame(2, $all['counts']['TOTAL']);

        // Grouped from the data rather than hardcoded, so the assertion is
        // about the relationship between filter, counts and rows -- not about
        // which status this fixture happens to produce.
        $bySt = [];

        foreach ($all['rows'] as $row) {
            $bySt[$row['lifecycle_status']][] = $row['symbol'];
        }

        $this->assertNotEmpty($bySt);

        foreach ($bySt as $status => $symbols) {
            $filtered = $this->candidates(['statuses' => [$status]]);

            $this->assertCount(count($symbols), $filtered['rows'], sprintf('filtering %s returned the wrong number of rows', $status));

            // Nothing comes back wearing a label the caller did not ask for.
            foreach ($filtered['rows'] as $row) {
                $this->assertSame($status, $row['lifecycle_status']);
            }

            // The card and the list agree about how many there are.
            $this->assertSame(count($symbols), $filtered['counts'][$status]);

            // Counts describe the whole evaluated list, not the filtered view.
            $this->assertSame(2, $filtered['counts']['TOTAL']);
        }

        // At least one of those was a lifecycle-only status -- one the v1
        // field can never hold, so the old filter returned an empty list for
        // it however many rows were sitting in that state.
        $this->assertNotEmpty(
            array_intersect(array_keys($bySt), [
                ExecutionStatus::ARMED,
                ExecutionStatus::TRIGGERED,
                ExecutionStatus::NO_CHASE,
                ExecutionStatus::HOLD,
                ExecutionStatus::TRAILING,
                ExecutionStatus::EXIT,
                ExecutionStatus::STALE_DATA,
            ]),
            'the fixture no longer exercises a lifecycle-only status',
        );
    }

    /**
     * The score filter reads the score the interface prints, not its v1 twin.
     */
    public function test_the_score_filter_uses_the_displayed_v2_score(): void
    {
        $dates = $this->seedSessions('AAA');
        $signal = end($dates);

        $this->seedFeature('AAA', $signal);
        $this->seedScore('AAA', $signal);
        $this->seedBrokerWindow('AAA', $signal);

        $row = $this->candidates()['rows'][0];

        $v2 = (float) $row['execution_score_v2'];
        $v1 = (float) $row['execution_score'];

        $this->assertNotEqualsWithDelta($v1, $v2, 0.0001, 'fixture cannot tell the two scores apart');

        // A floor just under the v2 score keeps the row; just over drops it.
        $this->assertCount(1, $this->candidates(['min_score' => $v2 - 0.5])['rows']);
        $this->assertCount(0, $this->candidates(['min_score' => $v2 + 0.5])['rows']);
    }

    public function test_an_empty_universe_answers_honestly(): void
    {
        $payload = $this->candidates();

        $this->assertNull($payload['signal_date']);
        $this->assertSame([], $payload['rows']);
        $this->assertSame(0, $payload['counts']['TOTAL']);
    }
}
