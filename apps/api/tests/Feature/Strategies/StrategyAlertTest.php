<?php

namespace Tests\Feature\Strategies;

use App\Models\Asset;
use App\Models\AutomationAlert;
use App\Models\Price;
use App\Models\StrategyAlert;
use App\Models\User;
use App\Services\Strategies\StrategyAlertEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Being told when a watched strategy fires.
 *
 * The evaluation uses the strategy classes the backtester walks history with,
 * given bars up to today instead of up to a point in the past. An alert with
 * its own copy of the entry rule would eventually disagree with the backtest
 * that justified subscribing to it, and the disagreement would show up as a
 * position taken on a signal the numbers never supported.
 */
class StrategyAlertTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /**
     * A series that closes above its 20-day Donchian channel on the last bar.
     */
    private function breakingOut(string $symbol): Asset
    {
        $asset = Asset::create(['symbol' => $symbol, 'name' => $symbol.' Tbk']);
        $date = Carbon::parse('2026-01-01');

        for ($i = 0; $i < 40; $i++) {
            $close = $i === 39 ? 2000 : 1000;

            Price::create([
                'asset_id' => $asset->id,
                'date' => $date->copy()->addDays($i)->toDateString(),
                'open' => $close,
                'high' => $close,
                'low' => $close,
                'close' => $close,
                'volume' => 1000,
            ]);
        }

        return $asset;
    }

    private function flat(string $symbol): Asset
    {
        $asset = Asset::create(['symbol' => $symbol, 'name' => $symbol.' Tbk']);
        $date = Carbon::parse('2026-01-01');

        for ($i = 0; $i < 40; $i++) {
            Price::create([
                'asset_id' => $asset->id,
                'date' => $date->copy()->addDays($i)->toDateString(),
                'open' => 1000, 'high' => 1000, 'low' => 1000, 'close' => 1000, 'volume' => 1000,
            ]);
        }

        return $asset;
    }

    private function subscribe(User $user, Asset $asset, string $strategy = 'DonchBO'): StrategyAlert
    {
        return StrategyAlert::create([
            'user_id' => $user->id,
            'asset_id' => $asset->id,
            'symbol' => $asset->symbol,
            'strategy' => $strategy,
            'signal' => StrategyAlert::SIGNAL_BUY,
            'enabled' => true,
        ]);
    }

    public function test_a_firing_strategy_raises_a_dashboard_alert(): void
    {
        $user = $this->user();
        $asset = $this->breakingOut('AAA');
        $subscription = $this->subscribe($user, $asset);

        $result = app(StrategyAlertEvaluator::class)->evaluate();

        $this->assertSame(1, $result['evaluated']);
        $this->assertSame(1, $result['triggered']);

        $alert = AutomationAlert::where('type', AutomationAlert::TYPE_STRATEGY_SIGNAL)
            ->where('key', $subscription->alertKey())
            ->first();

        $this->assertNotNull($alert);
        $this->assertStringContainsString('AAA', $alert->title);
        // A signal is something to decide about, not something broken.
        $this->assertSame(AutomationAlert::SEVERITY_INFO, $alert->severity);
        // And the message must not read as advice.
        $this->assertStringContainsString('not a recommendation', $alert->message);
    }

    public function test_a_quiet_strategy_raises_nothing(): void
    {
        $user = $this->user();
        $asset = $this->flat('BBB');
        $this->subscribe($user, $asset);

        $result = app(StrategyAlertEvaluator::class)->evaluate();

        $this->assertSame(0, $result['triggered']);
        $this->assertSame(0, AutomationAlert::where('type', AutomationAlert::TYPE_STRATEGY_SIGNAL)->count());
    }

    /**
     * The same session is the same event, however many times it is evaluated.
     */
    public function test_a_second_run_on_the_same_bar_does_not_fire_again(): void
    {
        $user = $this->user();
        $asset = $this->breakingOut('CCC');
        $this->subscribe($user, $asset);

        $evaluator = app(StrategyAlertEvaluator::class);

        $this->assertSame(1, $evaluator->evaluate()['triggered']);
        $this->assertSame(0, $evaluator->evaluate()['triggered'], 'A re-run must not re-raise the same signal.');
    }

    public function test_a_disabled_subscription_is_not_evaluated(): void
    {
        $user = $this->user();
        $asset = $this->breakingOut('DDD');
        $this->subscribe($user, $asset)->update(['enabled' => false]);

        $this->assertSame(0, app(StrategyAlertEvaluator::class)->evaluate()['evaluated']);
    }

    /**
     * A subscription to a strategy that no longer exists is reported, not
     * silently treated as a strategy that never fires.
     */
    public function test_an_unknown_strategy_is_counted_as_a_failure(): void
    {
        $user = $this->user();
        $asset = $this->breakingOut('EEE');
        $this->subscribe($user, $asset, 'RemovedStrategy');

        $result = app(StrategyAlertEvaluator::class)->evaluate();

        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $result['triggered']);
    }

    public function test_the_command_reports_what_fired(): void
    {
        $user = $this->user();
        $asset = $this->breakingOut('FFF');
        $this->subscribe($user, $asset);

        $this->artisan('automation:strategy-alerts')
            ->expectsOutputToContain('1 subscription(s) evaluated, 1 fired.')
            ->assertExitCode(0);
    }

    public function test_the_command_fails_when_a_subscription_names_a_missing_strategy(): void
    {
        $user = $this->user();
        $asset = $this->breakingOut('GGG');
        $this->subscribe($user, $asset, 'RemovedStrategy');

        $this->artisan('automation:strategy-alerts')->assertExitCode(1);
    }

    public function test_alerts_are_created_and_listed_for_the_owner_only(): void
    {
        $user = $this->user();
        $asset = $this->breakingOut('HHH');

        $this->postJson('/api/v1/strategy-alerts', [
            'asset_id' => $asset->id,
            'strategy' => 'DonchBO',
        ])->assertCreated()->assertJsonPath('data.alert.symbol', 'HHH');

        $this->getJson('/api/v1/strategy-alerts')
            ->assertOk()
            ->assertJsonCount(1, 'data.alerts');

        // A second user sees none of it.
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->getJson('/api/v1/strategy-alerts')
            ->assertOk()
            ->assertJsonCount(0, 'data.alerts');
    }

    public function test_subscribing_twice_updates_rather_than_duplicates(): void
    {
        $user = $this->user();
        $asset = $this->breakingOut('III');

        $this->postJson('/api/v1/strategy-alerts', ['asset_id' => $asset->id, 'strategy' => 'DonchBO'])
            ->assertCreated();
        $this->postJson('/api/v1/strategy-alerts', ['asset_id' => $asset->id, 'strategy' => 'DonchBO'])
            ->assertCreated();

        $this->assertSame(1, StrategyAlert::where('user_id', $user->id)->count());
    }

    /**
     * A strategy that cannot fire daily would be a reminder that never arrives.
     */
    public function test_a_strategy_with_no_daily_signal_cannot_be_subscribed_to(): void
    {
        $this->user();
        $asset = $this->breakingOut('JJJ');

        $this->postJson('/api/v1/strategy-alerts', [
            'asset_id' => $asset->id,
            'strategy' => 'HLSLBreakout',
        ])->assertStatus(422);
    }

    /**
     * A standing reminder must not outlive the subscription that raised it.
     */
    public function test_disabling_a_subscription_clears_its_standing_alert(): void
    {
        $user = $this->user();
        $asset = $this->breakingOut('KKK');
        $subscription = $this->subscribe($user, $asset);

        app(StrategyAlertEvaluator::class)->evaluate();

        $this->assertSame(1, AutomationAlert::where('key', $subscription->alertKey())->whereNull('resolved_at')->count());

        $this->patchJson("/api/v1/strategy-alerts/{$subscription->id}", ['enabled' => false])->assertOk();

        $this->assertSame(0, AutomationAlert::where('key', $subscription->alertKey())->whereNull('resolved_at')->count());
    }

    public function test_another_users_alert_cannot_be_touched(): void
    {
        $owner = User::factory()->create();
        $asset = $this->breakingOut('LLL');
        $subscription = $this->subscribe($owner, $asset);

        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->patchJson("/api/v1/strategy-alerts/{$subscription->id}", ['enabled' => false])->assertStatus(404);
        $this->deleteJson("/api/v1/strategy-alerts/{$subscription->id}")->assertStatus(404);

        $this->assertDatabaseHas('strategy_alerts', ['id' => $subscription->id, 'enabled' => true]);
    }
}
