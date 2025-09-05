<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Price;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LatestPricesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_latest_price_for_all_assets(): void
    {
        $asset1 = Asset::create(['symbol' => 'AAA', 'name' => 'Asset AAA']);
        $asset2 = Asset::create(['symbol' => 'BBB', 'name' => 'Asset BBB']);

        Price::create([
            'asset_id' => $asset1->id,
            'date' => '2024-01-01',
            'open' => 10,
            'high' => 12,
            'low' => 9,
            'close' => 11,
            'volume' => 1000,
        ]);
        Price::create([
            'asset_id' => $asset1->id,
            'date' => '2024-01-02',
            'open' => 11,
            'high' => 13,
            'low' => 10,
            'close' => 12,
            'volume' => 1100,
        ]);
        Price::create([
            'asset_id' => $asset2->id,
            'date' => '2024-01-01',
            'open' => 20,
            'high' => 22,
            'low' => 19,
            'close' => 21,
            'volume' => 2000,
        ]);
        Price::create([
            'asset_id' => $asset2->id,
            'date' => '2024-01-03',
            'open' => 22,
            'high' => 23,
            'low' => 21,
            'close' => 22,
            'volume' => 2100,
        ]);

        $response = $this->getJson('/api/v1/assets/latest-prices');

        $response->assertStatus(200)
            ->assertJsonCount(2)
            ->assertJsonFragment(['asset_id' => $asset1->id, 'close' => 12])
            ->assertJsonFragment(['asset_id' => $asset2->id, 'close' => 22]);
    }
}
