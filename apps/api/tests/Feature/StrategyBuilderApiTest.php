<?php

namespace Tests\Feature;

use App\Jobs\RunStrategyJob;
use App\Models\Asset;
use App\Models\Strategy;
use App\Models\StrategyRun;
use App\Models\User;
use App\Services\Strategy\StrategyRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StrategyBuilderApiTest extends TestCase
{
    use RefreshDatabase;

    private function rules(): array
    {
        return [
            'op' => 'and',
            'rules' => [
                ['field' => 'features.vol_ratio_20', 'operator' => 'gte', 'value' => 1.5],
                ['field' => 'features.breakout20', 'operator' => 'is_true'],
            ],
        ];
    }

    private function makeStrategy(User $owner, string $visibility = Strategy::VISIBILITY_PRIVATE): Strategy
    {
        return Strategy::create([
            'user_id' => $owner->id,
            'name' => 'Volume breakout',
            'visibility' => $visibility,
            'rules' => $this->rules(),
        ]);
    }

    public function test_schema_lists_fields_and_operators(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/strategies/schema')->assertOk();

        $fields = $response->json('data.fields');
        $this->assertNotEmpty($fields);
        $this->assertContains('features.vol_ratio_20', array_column($fields, 'key'));
        $this->assertContains('metrics.close', array_column($fields, 'key'));
        $this->assertArrayHasKey('gte', $response->json('data.operators'));
    }

    public function test_user_can_create_and_read_back_a_strategy(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $created = $this->postJson('/api/v1/strategies', [
            'name' => 'Volume breakout',
            'description' => 'Volume expansion into a 20 day breakout.',
            'visibility' => 'private',
            'rules' => $this->rules(),
        ])->assertStatus(201);

        $id = $created->json('data.strategy.id');

        $this->getJson("/api/v1/strategies/{$id}")
            ->assertOk()
            ->assertJsonPath('data.strategy.name', 'Volume breakout')
            ->assertJsonPath('data.strategy.is_owner', true)
            ->assertJsonPath('data.strategy.rules.op', 'and');
    }

    public function test_invalid_rules_are_rejected_with_a_field_level_message(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/strategies', [
            'name' => 'Bad',
            'rules' => ['field' => 'features.nope', 'operator' => 'gt', 'value' => 1],
        ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_private_strategy_is_invisible_to_other_users(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $strategy = $this->makeStrategy($owner);

        Sanctum::actingAs($stranger);

        $this->getJson("/api/v1/strategies/{$strategy->id}")->assertStatus(404);
        $this->getJson('/api/v1/strategies')
            ->assertOk()
            ->assertJsonCount(0, 'data.strategies');
    }

    public function test_public_strategy_is_readable_by_others_but_not_editable(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $strategy = $this->makeStrategy($owner, Strategy::VISIBILITY_PUBLIC);

        Sanctum::actingAs($stranger);

        $this->getJson("/api/v1/strategies/{$strategy->id}")
            ->assertOk()
            ->assertJsonPath('data.strategy.is_owner', false);

        $this->patchJson("/api/v1/strategies/{$strategy->id}", ['name' => 'Hijacked'])
            ->assertStatus(403);

        $this->deleteJson("/api/v1/strategies/{$strategy->id}")->assertStatus(403);

        $this->assertSame('Volume breakout', $strategy->refresh()->name);
    }

    public function test_owner_can_update_and_delete_their_strategy(): void
    {
        $owner = User::factory()->create();
        $strategy = $this->makeStrategy($owner);

        Sanctum::actingAs($owner);

        $this->patchJson("/api/v1/strategies/{$strategy->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.strategy.name', 'Renamed');

        $this->deleteJson("/api/v1/strategies/{$strategy->id}")->assertOk();
        $this->assertDatabaseMissing('strategies', ['id' => $strategy->id]);
    }

    /**
     * The copy flow is how a non-owner adapts a public strategy: they get a
     * private strategy of their own that points back at the original.
     */
    public function test_public_strategy_can_be_copied_and_the_copy_is_editable(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $original = $this->makeStrategy($owner, Strategy::VISIBILITY_PUBLIC);

        Sanctum::actingAs($stranger);

        $copyId = $this->postJson("/api/v1/strategies/{$original->id}/copy")
            ->assertStatus(201)
            ->assertJsonPath('data.strategy.is_owner', true)
            ->assertJsonPath('data.strategy.visibility', 'private')
            ->assertJsonPath('data.strategy.copied_from_id', $original->id)
            ->json('data.strategy.id');

        $this->patchJson("/api/v1/strategies/{$copyId}", ['name' => 'My version'])
            ->assertOk()
            ->assertJsonPath('data.strategy.name', 'My version');

        // The original is untouched by edits to the copy.
        $this->assertSame('Volume breakout', $original->refresh()->name);
    }

    public function test_private_strategy_cannot_be_copied_by_a_stranger(): void
    {
        $owner = User::factory()->create();
        $strategy = $this->makeStrategy($owner);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/strategies/{$strategy->id}/copy")->assertStatus(404);
    }

    public function test_run_is_queued_rather_than_executed_in_the_request(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $strategy = $this->makeStrategy($owner);
        $this->seedFeatureRow('BBCA', '2026-04-15', volRatio: 2.0, breakout: 1);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/strategies/{$strategy->id}/run")
            ->assertStatus(202)
            ->assertJsonPath('data.run.status', StrategyRun::STATUS_QUEUED)
            ->assertJsonPath('data.run.scan_date', '2026-04-15');

        Queue::assertPushed(RunStrategyJob::class);
    }

    public function test_run_without_feature_data_reports_why(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $strategy = $this->makeStrategy($owner);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/strategies/{$strategy->id}/run")->assertStatus(422);
        Queue::assertNothingPushed();
    }

    /**
     * End to end through the real runner: only the symbol satisfying both
     * conditions is persisted, and the match carries its explanation.
     */
    public function test_runner_persists_only_matching_symbols_with_explanations(): void
    {
        $owner = User::factory()->create();
        $strategy = $this->makeStrategy($owner);

        Asset::create(['symbol' => 'BBCA', 'name' => 'BCA']);
        Asset::create(['symbol' => 'TLKM', 'name' => 'Telkom']);

        $this->seedFeatureRow('BBCA', '2026-04-15', volRatio: 2.0, breakout: 1);   // matches
        $this->seedFeatureRow('TLKM', '2026-04-15', volRatio: 2.0, breakout: 0);   // fails breakout
        $this->seedFeatureRow('BBCA', '2026-04-14', volRatio: 9.9, breakout: 1);   // wrong date

        $run = StrategyRun::create([
            'strategy_id' => $strategy->id,
            'scan_date' => '2026-04-15',
            'status' => StrategyRun::STATUS_QUEUED,
        ]);

        app(StrategyRunner::class)->run($strategy, $run);

        $run->refresh();
        $this->assertSame(StrategyRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(2, $run->evaluated_count);
        $this->assertSame(1, $run->matched_count);

        $match = $run->matches()->first();
        $this->assertSame('BBCA', $match->symbol);
        $this->assertCount(2, $match->explanation);
        $this->assertArrayHasKey('features.vol_ratio_20', $match->facts);

        // The card reads these mirrored columns rather than a subquery.
        $strategy->refresh();
        $this->assertSame(StrategyRun::STATUS_COMPLETED, $strategy->last_run_status);
        $this->assertSame(1, $strategy->last_match_count);
        $this->assertNotNull($strategy->last_run_at);
    }

    public function test_run_matches_endpoint_returns_the_explanation_trace(): void
    {
        $owner = User::factory()->create();
        $strategy = $this->makeStrategy($owner);

        Asset::create(['symbol' => 'BBCA', 'name' => 'BCA']);
        $this->seedFeatureRow('BBCA', '2026-04-15', volRatio: 2.0, breakout: 1);

        $run = StrategyRun::create([
            'strategy_id' => $strategy->id,
            'scan_date' => '2026-04-15',
            'status' => StrategyRun::STATUS_QUEUED,
        ]);
        app(StrategyRunner::class)->run($strategy, $run);

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/strategies/{$strategy->id}/runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.run.matched_count', 1)
            ->assertJsonPath('data.matches.0.symbol', 'BBCA')
            ->assertJsonPath('data.matches.0.explanation.0.passed', true);
    }

    public function test_strategy_index_scopes_are_honoured(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->makeStrategy($owner, Strategy::VISIBILITY_PRIVATE);
        $this->makeStrategy($other, Strategy::VISIBILITY_PUBLIC);
        $this->makeStrategy($other, Strategy::VISIBILITY_PRIVATE);

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/strategies?scope=mine')
            ->assertOk()->assertJsonCount(1, 'data.strategies');

        $this->getJson('/api/v1/strategies?scope=public')
            ->assertOk()->assertJsonCount(1, 'data.strategies');

        // all = own private + everyone's public, never another user's private.
        $this->getJson('/api/v1/strategies?scope=all')
            ->assertOk()->assertJsonCount(2, 'data.strategies');
    }

    private function seedFeatureRow(string $symbol, string $date, float $volRatio, int $breakout): void
    {
        DB::table('features_daily')->insert([
            'symbol' => $symbol,
            'date' => $date,
            'vol_ratio_20' => $volRatio,
            'breakout20' => $breakout,
            'has_broker' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
