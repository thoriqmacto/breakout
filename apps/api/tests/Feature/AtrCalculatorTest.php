<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Price;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AtrCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_atr_requires_authentication(): void
    {
        $asset = Asset::create(['symbol' => 'AAA', 'name' => 'Asset AAA']);

        $this->getJson("/api/v1/assets/{$asset->id}/atr")->assertUnauthorized();
    }

    public function test_it_computes_daily_atr(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $asset = Asset::create(['symbol' => 'AAA', 'name' => 'Asset AAA']);

        Price::create([
            'asset_id' => $asset->id,
            'date' => '2024-01-01',
            'open' => 10,
            'high' => 12,
            'low' => 8,
            'close' => 11,
            'volume' => 1000,
        ]);

        Price::create([
            'asset_id' => $asset->id,
            'date' => '2024-01-02',
            'open' => 11,
            'high' => 13,
            'low' => 9,
            'close' => 12,
            'volume' => 1100,
        ]);

        Price::create([
            'asset_id' => $asset->id,
            'date' => '2024-01-03',
            'open' => 12,
            'high' => 14,
            'low' => 10,
            'close' => 13,
            'volume' => 1200,
        ]);

        $response = $this->getJson("/api/v1/assets/{$asset->id}/atr?period=2&interval=daily");

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.symbol', 'AAA')
            ->assertJsonPath('data.interval', 'daily')
            ->assertJsonPath('data.period', 2)
            ->assertJsonPath('data.atr', 4);
    }

    public function test_it_computes_weekly_atr_by_symbol(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $asset = Asset::create(['symbol' => 'BBB', 'name' => 'Asset BBB']);

        $prices = [
            ['2024-01-01', 10, 12, 8, 11],
            ['2024-01-02', 11, 13, 9, 12],
            ['2024-01-03', 12, 14, 10, 13],
            ['2024-01-04', 13, 15, 11, 14],
            ['2024-01-05', 14, 16, 12, 15],
            ['2024-01-08', 15, 18, 14, 17],
            ['2024-01-09', 16, 19, 15, 18],
            ['2024-01-10', 17, 20, 16, 19],
            ['2024-01-11', 18, 21, 17, 20],
            ['2024-01-12', 19, 22, 18, 21],
        ];

        foreach ($prices as [$date, $open, $high, $low, $close]) {
            Price::create([
                'asset_id' => $asset->id,
                'date' => $date,
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume' => 1000,
            ]);
        }

        $response = $this->getJson('/api/v1/assets/BBB/atr?period=2&interval=weekly');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.interval', 'weekly')
            ->assertJsonPath('data.period', 2)
            ->assertJsonPath('data.atr', 7)
            ->assertJsonPath('data.bar_count', 2);
    }
}
