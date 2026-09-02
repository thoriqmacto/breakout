<?php

namespace Tests\Feature\Strategy;

use App\Models\Asset;
use App\Models\Price;
use App\Models\StrategySignalOutcome;
use App\Models\WatchlistScore;
use App\Services\Strategy\OutcomeProbabilityService;
use App\Services\Strategy\SetupBucket;
use App\Services\Strategy\SignalOutcomeEvaluator;
use App\Services\Strategy\StrategyProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The historical engine, and the property the whole design exists to protect.
 *
 * A backtest that reads forward data during signal generation reports an edge
 * that cannot be traded. It is also almost invisible: the leak is distributed
 * evenly across every bucket and shows up as a strategy that looks mildly
 * profitable and trades unprofitably. So it is asserted directly -- change
 * the future and the past must not move.
 */
class SignalOutcomeEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    /** @var array<int, string> */
    private array $sessions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->asset = Asset::create(['symbol' => 'AAA', 'name' => 'Asset AAA', 'sector' => 'Energy']);
    }

    private function profile(array $overrides = []): StrategyProfile
    {
        return StrategyProfile::fromArray(array_merge([
            'version' => 'test-v2',
            'broker_windows' => [3, 5, 10, 20],
            'broker_regime_windows' => [5, 10, 20],
            'trail_activation_gain_pct' => 5.0,
            'trailing_distance_pct' => 2.0,
            'minimum_locked_profit_pct' => 3.0,
            'max_holding_sessions' => 20,
            'outcome_horizons' => [1, 3, 5, 10, 20],
            'minimum_probability_sample' => 3,
            'max_initial_risk_pct' => 20.0,
            'costs' => ['buy_fee_pct' => 0.15, 'sell_fee_pct' => 0.25, 'slippage_pct' => 0.1, 'round_to_tick' => false],
        ], $overrides));
    }

    /**
     * A steady advance, then a flat plateau the signal sits on, then a
     * forward window each test rewrites.
     *
     * @return array<int, string>
     */
    private function seedSessions(int $count = 70, float $start = 1000.0, float $step = 5.0): array
    {
        $cursor = Carbon::parse('2026-01-05');
        $close = $start;
        $dates = [];

        while (count($dates) < $count) {
            if ($cursor->dayOfWeekIso >= 6) {
                $cursor->addDay();

                continue;
            }

            Price::create([
                'asset_id' => $this->asset->id,
                'date' => $cursor->toDateString(),
                'open' => $close - 2,
                'high' => $close + 3,
                'low' => $close - 5,
                'close' => $close,
                'volume' => 10_000_000,
            ]);

            DB::table('trading_days')->insertOrIgnore(['date' => $cursor->toDateString()]);
            $dates[] = $cursor->toDateString();
            $close += $step;
            $cursor->addDay();
        }

        $this->sessions = $dates;

        return $dates;
    }

    /**
     * Overwrite every session after $afterIndex with a given shape.
     */
    private function rewriteForward(int $afterIndex, callable $shape): void
    {
        foreach (array_slice($this->sessions, $afterIndex + 1) as $offset => $date) {
            $bar = $shape($offset);

            Price::query()
                ->where('asset_id', $this->asset->id)
                ->whereDate('date', $date)
                ->update($bar);
        }
    }

    /**
     * Make the session after the signal trade decisively through the trigger.
     *
     * A steady five-rupiah advance does not clear one IDX tick above the
     * signal high, so without this the fixture produces no fill and every
     * assertion about outcomes would pass vacuously.
     */
    private function seedEntrySession(int $signalIndex): void
    {
        $signalClose = (float) Price::query()
            ->where('asset_id', $this->asset->id)
            ->whereDate('date', $this->sessions[$signalIndex])
            ->value('close');

        Price::query()
            ->where('asset_id', $this->asset->id)
            ->whereDate('date', $this->sessions[$signalIndex + 1])
            ->update([
                'open' => $signalClose + 10,
                'high' => $signalClose + 60,
                'low' => $signalClose + 5,
                'close' => $signalClose + 50,
            ]);
    }

    private function seedBrokerFlow(string $endDate, array $netByWindow): void
    {
        foreach ($netByWindow as $days => $net) {
            DB::table('broker_accumulation_windows')->updateOrInsert(
                ['asset_id' => $this->asset->id, 'end_date' => $endDate, 'window_days' => $days],
                [
                    'avg_net_norm' => $net,
                    'top3_net_norm' => $net * 4,
                    'top5_net_norm' => $net * 4,
                    'foreign_net_norm' => 0,
                    'local_net_norm' => 0,
                    'accdist_score' => $net > 0 ? 1 : -1,
                    'broker_count' => 20,
                    'value' => 50_000_000_000,
                    'volume' => 1_000_000,
                    'covered_days' => $days,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function seedScore(string $date): void
    {
        WatchlistScore::create([
            'scan_date' => $date,
            'symbol' => 'AAA',
            'asset_id' => $this->asset->id,
            'version' => 'v1',
            'close' => 1000,
            'score_total' => 88.0,
            'score_bas' => 90.0,
            'score_bcs' => 85.0,
            'lf_pass' => true,
            'rrf_pass' => true,
            'risk_reward' => 3.0,
            'top_brokers' => [],
            'reasons' => [],
        ]);
    }

    private function evaluate(StrategyProfile $profile, string $from, string $to): array
    {
        return app(SignalOutcomeEvaluator::class)->evaluate(
            Carbon::parse($from),
            Carbon::parse($to),
            $profile,
            ['AAA'],
            'v1',
        );
    }

    /**
     * The leak test. Everything about the signal is computed from data at or
     * before the signal session; everything about the outcome from data
     * strictly after the entry. Rewriting the second must not disturb the
     * first.
     */
    public function test_changing_future_bars_does_not_alter_a_past_signal(): void
    {
        $dates = $this->seedSessions();
        $signalIndex = 49;
        $signal = $dates[$signalIndex];

        $this->seedBrokerFlow($signal, [3 => 0.02, 5 => 0.02, 10 => 0.02, 20 => 0.02]);
        $this->seedScore($signal);
        $this->seedEntrySession($signalIndex);

        $profile = $this->profile();

        // Pass one: the forward window continues the advance.
        $this->evaluate($profile, $signal, $signal);
        $before = StrategySignalOutcome::query()->firstOrFail();

        $signalFields = [
            'setup_bucket' => $before->setup_bucket,
            'broker_regime' => $before->broker_regime,
            'broker_persistence_ratio' => $before->broker_persistence_ratio,
            'execution_score' => $before->execution_score,
            'breakout20' => $before->breakout20,
            'breakout55' => $before->breakout55,
            'vol_ratio_20' => $before->vol_ratio_20,
            'close_pos' => $before->close_pos,
            'atr14' => $before->atr14,
            'trigger_price' => $before->trigger_price,
            'entry_price' => $before->entry_price,
            'initial_stop' => $before->initial_stop,
            'initial_risk_pct' => $before->initial_risk_pct,
        ];

        // Pass two: the future is replaced with a collapse. Only sessions
        // *after the entry* are touched, so the fill itself is untouched too.
        $this->rewriteForward($signalIndex + 1, static fn (int $offset): array => [
            'open' => 500 - $offset,
            'high' => 505 - $offset,
            'low' => 480 - $offset,
            'close' => 490 - $offset,
        ]);

        $this->evaluate($profile, $signal, $signal);
        $after = StrategySignalOutcome::query()->firstOrFail();

        foreach ($signalFields as $field => $value) {
            $this->assertEquals(
                $value,
                $after->{$field},
                sprintf('"%s" moved when only future bars changed, which means the signal read them.', $field),
            );
        }

        // And the forward half did change, so the test is not passing for
        // want of the evaluator having run at all.
        $this->assertNotEquals($before->net_return_pct, $after->net_return_pct);
        $this->assertTrue($after->hit_stop_before_5pct);
    }

    public function test_a_signal_whose_next_session_never_reaches_the_trigger_produces_no_trade(): void
    {
        $dates = $this->seedSessions();
        $signalIndex = 49;
        $signal = $dates[$signalIndex];

        $this->seedBrokerFlow($signal, [3 => 0.02, 5 => 0.02, 10 => 0.02, 20 => 0.02]);
        $this->seedScore($signal);

        // The next session opens and stays well below the trigger.
        $this->rewriteForward($signalIndex, static fn (int $offset): array => [
            'open' => 900, 'high' => 905, 'low' => 880, 'close' => 890,
        ]);

        $report = $this->evaluate($this->profile(), $signal, $signal);

        $this->assertSame(1, $report['signals']);
        $this->assertSame(1, $report['not_triggered']);
        $this->assertSame(0, $report['persisted']);
        $this->assertSame(0, StrategySignalOutcome::query()->count());
    }

    public function test_outcomes_are_written_per_profile_version(): void
    {
        $dates = $this->seedSessions();
        $signal = $dates[49];

        $this->seedBrokerFlow($signal, [3 => 0.02, 5 => 0.02, 10 => 0.02, 20 => 0.02]);
        $this->seedScore($signal);
        $this->seedEntrySession(49);

        $this->evaluate($this->profile(['version' => 'a']), $signal, $signal);
        $this->evaluate($this->profile(['version' => 'b']), $signal, $signal);

        $this->assertSame(2, StrategySignalOutcome::query()->count());
        $this->assertSame(
            ['a', 'b'],
            StrategySignalOutcome::query()->orderBy('strategy_version')->pluck('strategy_version')->all(),
        );
    }

    public function test_rerunning_the_same_range_overwrites_rather_than_duplicating(): void
    {
        $dates = $this->seedSessions();
        $signal = $dates[49];

        $this->seedBrokerFlow($signal, [3 => 0.02, 5 => 0.02, 10 => 0.02, 20 => 0.02]);
        $this->seedScore($signal);
        $this->seedEntrySession(49);

        $profile = $this->profile();
        $this->evaluate($profile, $signal, $signal);
        $this->evaluate($profile, $signal, $signal);

        $this->assertSame(1, StrategySignalOutcome::query()->count());
    }

    /**
     * The sample-size guard, through the service the workspace calls.
     */
    public function test_the_probability_is_withheld_until_the_sample_is_large_enough(): void
    {
        $profile = $this->profile(['minimum_probability_sample' => 5]);
        $bucket = new SetupBucket('ACC2', 'B20', 'VH', 'RL');

        $this->writeOutcomes($bucket->key(), $profile->version, hits: 2, misses: 2);

        $thin = app(OutcomeProbabilityService::class)->forBucket($bucket, $profile);
        $this->assertSame(OutcomeProbabilityService::INSUFFICIENT_SAMPLE, $thin['status']);
        $this->assertNull($thin['probability_hit_5_before_stop']);
        $this->assertSame(4, $thin['sample_size']);

        $this->writeOutcomes($bucket->key(), $profile->version, hits: 4, misses: 0, offset: 10);

        $enough = app(OutcomeProbabilityService::class)->forBucket($bucket, $profile);
        $this->assertSame('OK', $enough['status']);
        $this->assertSame(8, $enough['sample_size']);
        $this->assertEqualsWithDelta(0.75, $enough['probability_hit_5_before_stop'], 0.0001);
    }

    /**
     * Unresolved trades are excluded, never counted as misses -- otherwise
     * every probability is dragged down at the recent end of the data.
     */
    public function test_unresolved_outcomes_are_excluded_from_the_statistics(): void
    {
        $profile = $this->profile(['minimum_probability_sample' => 3]);
        $bucket = new SetupBucket('ACC2', 'B20', 'VH', 'RL');

        $this->writeOutcomes($bucket->key(), $profile->version, hits: 3, misses: 0);
        $this->writeOutcomes($bucket->key(), $profile->version, hits: 0, misses: 5, offset: 20, resolved: false);

        $result = app(OutcomeProbabilityService::class)->forBucket($bucket, $profile);

        $this->assertSame(3, $result['sample_size']);
        $this->assertEqualsWithDelta(1.0, $result['probability_hit_5_before_stop'], 0.0001);
    }

    /**
     * Scoring a historical date must not consult outcomes that had not
     * happened by then -- look-ahead laundered through a statistic is still
     * look-ahead.
     */
    public function test_the_probability_lookup_respects_the_as_of_date(): void
    {
        $profile = $this->profile(['minimum_probability_sample' => 3]);
        $bucket = new SetupBucket('ACC2', 'B20', 'VH', 'RL');

        $this->writeOutcomes($bucket->key(), $profile->version, hits: 4, misses: 0, offset: 200);

        $asOfBefore = app(OutcomeProbabilityService::class)
            ->forBucket($bucket, $profile, Carbon::parse('2026-01-01'));

        $this->assertSame(OutcomeProbabilityService::INSUFFICIENT_SAMPLE, $asOfBefore['status']);
        $this->assertSame(0, $asOfBefore['sample_size']);

        $asOfAfter = app(OutcomeProbabilityService::class)
            ->forBucket($bucket, $profile, Carbon::parse('2030-01-01'));

        $this->assertSame('OK', $asOfAfter['status']);
        $this->assertSame(4, $asOfAfter['sample_size']);
    }

    /**
     * A thin exact bucket widens to the coarse one rather than reporting
     * nothing, and says that it did.
     */
    public function test_a_thin_exact_bucket_falls_back_to_a_wider_comparable_population(): void
    {
        $profile = $this->profile(['minimum_probability_sample' => 4]);
        $bucket = new SetupBucket('ACC2', 'B20', 'VH', 'RL');

        // Two in the exact bucket, four more sharing only regime and breakout.
        $this->writeOutcomes($bucket->key(), $profile->version, hits: 2, misses: 0);
        $this->writeOutcomes('ACC2|B20|VM|RM', $profile->version, hits: 2, misses: 2, offset: 30);

        $result = app(OutcomeProbabilityService::class)->forBucket($bucket, $profile);

        $this->assertSame('OK', $result['status']);
        $this->assertSame(OutcomeProbabilityService::MATCH_COARSE, $result['match']);
        $this->assertSame(6, $result['sample_size']);
        $this->assertSame(2, $result['exact_sample_size']);
    }

    private function writeOutcomes(string $bucket, string $version, int $hits, int $misses, int $offset = 0, bool $resolved = true): void
    {
        $date = Carbon::parse('2026-01-05')->addDays($offset);

        for ($i = 0; $i < $hits + $misses; $i++) {
            StrategySignalOutcome::create([
                'asset_id' => $this->asset->id,
                'symbol' => 'AAA',
                'signal_date' => $date->copy()->addDays($i)->toDateString(),
                'strategy_version' => $version,
                'setup_bucket' => $bucket,
                'reached_5pct_before_stop' => $i < $hits,
                'hit_stop_before_5pct' => $i >= $hits,
                'hit_5pct' => $i < $hits,
                'days_to_5pct' => $i < $hits ? 6 : null,
                'mae_5d' => -1.7,
                'mfe_5d' => 6.2,
                'net_return_pct' => $i < $hits ? 4.8 : -2.4,
                'hold_sessions' => 6,
                'resolved' => $resolved,
            ]);
        }
    }
}
