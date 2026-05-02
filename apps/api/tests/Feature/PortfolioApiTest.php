<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\CashMovement;
use App\Models\Portfolio;
use App\Models\Position;
use App\Models\Price;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortfolioApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_only_caller_portfolios_and_legacy_unowned_ones(): void
    {
        [$alice, $bob] = [User::factory()->create(), User::factory()->create()];
        Sanctum::actingAs($alice, ['*']);

        Portfolio::create(['user_id' => $alice->id, 'name' => 'Alice 1', 'base_ccy' => 'IDR']);
        Portfolio::create(['user_id' => $bob->id, 'name' => 'Bob 1', 'base_ccy' => 'IDR']);
        Portfolio::create(['user_id' => null, 'name' => 'Legacy', 'base_ccy' => 'IDR']);

        $response = $this->getJson('/api/v1/portfolios');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        sort($names);
        $this->assertSame(['Alice 1', 'Legacy'], $names);
    }

    public function test_store_attaches_authenticated_user_id(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/portfolios', [
            'name' => 'Mine',
            'base_ccy' => 'IDR',
            'cash_balance' => 5_000_000,
        ]);

        $response->assertCreated();
        $portfolio = Portfolio::query()->latest('id')->first();
        $this->assertSame((int) $user->id, (int) $portfolio->user_id);
        $this->assertSame(5_000_000.0, (float) $portfolio->cash_balance);
    }

    public function test_show_blocks_other_users(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $portfolio = Portfolio::create(['user_id' => $bob->id, 'name' => 'Bob', 'base_ccy' => 'IDR']);

        Sanctum::actingAs($alice, ['*']);
        $this->getJson("/api/v1/portfolios/{$portfolio->id}")->assertForbidden();
    }

    public function test_summary_endpoint_returns_calculator_payload(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $portfolio = Portfolio::create([
            'user_id' => $user->id,
            'name' => 'Main',
            'base_ccy' => 'IDR',
            'cash_balance' => 1_000_000,
        ]);
        $asset = Asset::create(['symbol' => 'BBCA', 'name' => 'BCA', 'sector' => 'Banks']);
        Price::create([
            'asset_id' => $asset->id,
            'date' => '2026-04-01',
            'open' => 11_000, 'high' => 11_000, 'low' => 11_000, 'close' => 11_000,
            'volume' => 1000,
        ]);
        Position::create([
            'portfolio_id' => $portfolio->id,
            'asset_id' => $asset->id,
            'side' => 'entry',
            'qty_shares' => 100,
            'price' => 10_000,
            'fee_rate' => 0,
            'fee_value' => 0,
            'avg_price' => 10_000,
            'executed_at' => '2026-03-01',
        ]);
        CashMovement::create([
            'portfolio_id' => $portfolio->id,
            'kind' => CashMovement::KIND_DIVIDEND,
            'amount' => 50_000,
            'executed_at' => '2026-03-15',
        ]);

        $response = $this->getJson("/api/v1/portfolios/{$portfolio->id}/summary");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame((int) $portfolio->id, $data['portfolio_id']);
        $this->assertEquals(1_050_000.0, $data['cash_balance']);
        $this->assertEquals(1_100_000.0, $data['total_market_value']);
        $this->assertEquals(2_150_000.0, $data['total_equity']);
        $this->assertEquals(100_000.0, $data['unrealized_pl']);
        $this->assertCount(1, $data['holdings']);
        $this->assertSame('BBCA', $data['holdings'][0]['symbol']);
    }

    public function test_holdings_endpoint_returns_holdings_only(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $portfolio = $this->makePortfolioWithOnePosition($user);

        $response = $this->getJson("/api/v1/portfolios/{$portfolio->id}/holdings");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame((int) $portfolio->id, $data['portfolio_id']);
        $this->assertCount(1, $data['holdings']);
        $this->assertArrayNotHasKey('total_equity', $data);
    }

    public function test_allocations_endpoint_returns_breakdowns(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $portfolio = $this->makePortfolioWithOnePosition($user);

        $response = $this->getJson("/api/v1/portfolios/{$portfolio->id}/allocations");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame((int) $portfolio->id, $data['portfolio_id']);
        $this->assertNotEmpty($data['allocation_by_symbol']);
        $this->assertNotEmpty($data['allocation_by_sector']);
    }

    public function test_cash_movement_create_index_and_destroy(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $portfolio = Portfolio::create(['user_id' => $user->id, 'name' => 'X', 'base_ccy' => 'IDR']);

        $created = $this->postJson("/api/v1/portfolios/{$portfolio->id}/cash-movements", [
            'kind' => 'deposit',
            'amount' => 1_000_000,
            'executed_at' => '2026-04-01',
            'note' => 'opening',
        ])->assertCreated()->json('data');

        $this->assertSame('deposit', $created['kind']);
        $this->assertEquals(1_000_000.0, $created['amount']);
        $this->assertEquals(1_000_000.0, $created['signed_amount']);

        $list = $this->getJson("/api/v1/portfolios/{$portfolio->id}/cash-movements")->assertOk()->json('data');
        $this->assertCount(1, $list['rows']);

        $this->deleteJson("/api/v1/portfolios/{$portfolio->id}/cash-movements/{$created['id']}")
            ->assertOk();

        $this->assertSame(
            0,
            $this->getJson("/api/v1/portfolios/{$portfolio->id}/cash-movements")->json('data.rows.0') === null ? 0 : 1
        );
    }

    public function test_cash_movement_rejects_negative_amount_for_non_adjustment_kinds(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $portfolio = Portfolio::create(['user_id' => $user->id, 'name' => 'X', 'base_ccy' => 'IDR']);

        $this->postJson("/api/v1/portfolios/{$portfolio->id}/cash-movements", [
            'kind' => 'deposit',
            'amount' => -1,
            'executed_at' => '2026-04-01',
        ])->assertStatus(422);
    }

    public function test_cash_movement_allows_negative_amount_for_adjustment(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $portfolio = Portfolio::create(['user_id' => $user->id, 'name' => 'X', 'base_ccy' => 'IDR']);

        $this->postJson("/api/v1/portfolios/{$portfolio->id}/cash-movements", [
            'kind' => 'adjustment',
            'amount' => -50_000,
            'executed_at' => '2026-04-01',
            'note' => 'manual correction',
        ])->assertCreated();
    }

    private function makePortfolioWithOnePosition(User $user): Portfolio
    {
        $portfolio = Portfolio::create([
            'user_id' => $user->id,
            'name' => 'Main',
            'base_ccy' => 'IDR',
        ]);
        $asset = Asset::create(['symbol' => 'BBCA', 'name' => 'BCA', 'sector' => 'Banks']);
        Price::create([
            'asset_id' => $asset->id,
            'date' => '2026-04-01',
            'open' => 11_000, 'high' => 11_000, 'low' => 11_000, 'close' => 11_000,
            'volume' => 1000,
        ]);
        Position::create([
            'portfolio_id' => $portfolio->id,
            'asset_id' => $asset->id,
            'side' => 'entry',
            'qty_shares' => 100,
            'price' => 10_000,
            'fee_rate' => 0,
            'fee_value' => 0,
            'avg_price' => 10_000,
            'executed_at' => '2026-03-01',
        ]);

        return $portfolio;
    }
}
