<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Broksum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BrokerSummaryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/broker-summaries?symbol=AAA')
            ->assertUnauthorized();
    }

    public function test_it_returns_broker_summaries_for_symbol(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $asset = Asset::create(['symbol' => 'AAA', 'name' => 'Asset AAA']);
        $other = Asset::create(['symbol' => 'BBB', 'name' => 'Asset BBB']);

        Broksum::create([
            'asset_id' => $asset->id,
            'date' => '2024-04-01',
            'broker' => 'AB',
            'net_value' => 600,
            'buy_value' => 1000,
            'buy_avg_price' => 10.5,
            'sell_value' => 400,
        ]);

        Broksum::create([
            'asset_id' => $asset->id,
            'date' => '2024-04-03',
            'broker' => 'CD',
            'net_value' => -100,
            'buy_value' => 700,
            'buy_avg_price' => 9.25,
            'sell_value' => 800,
        ]);

        Broksum::create([
            'asset_id' => $other->id,
            'date' => '2024-04-03',
            'broker' => 'CD',
            'net_value' => 100,
            'buy_value' => 500,
            'buy_avg_price' => 8.5,
            'sell_value' => 400,
        ]);

        $response = $this->getJson('/api/v1/broker-summaries?symbol=AAA&from=2024-04-02&broker=CD&limit=5');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.symbol', 'AAA')
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.broker', 'CD')
            // JSON round-trips whole-number floats as PHP ints; assert as int to match PHP 8.4 decoding.
            ->assertJsonPath('data.rows.0.net_value', -100)
            ->assertJsonPath('data.rows.0.buy_avg_price', 9.25);
    }
}
