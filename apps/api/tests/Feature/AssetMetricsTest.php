<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Price;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssetMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_metrics_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/assets/metrics')->assertUnauthorized();
    }

    public function test_metrics_endpoint_returns_metrics_without_dividing_by_zero(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $asset = Asset::create([
            'symbol' => 'AAA',
            'name' => 'Asset AAA',
        ]);

        $start = CarbonImmutable::parse('2024-01-01');

        for ($i = 0; $i < 70; $i++) {
            $date = $start->addDays($i);

            Price::create([
                'asset_id' => $asset->id,
                'date' => $date->toDateString(),
                'open' => 100 + $i,
                'high' => 105 + $i,
                'low' => 95 + $i,
                'close' => $i < 5 ? 0 : 100 + $i,
                'volume' => 1000 + $i,
            ]);
        }

        // Metrics are read from a precomputed table; populate it first.
        $this->postJson('/api/v1/assets/metrics/update')->assertOk();

        $response = $this->getJson('/api/v1/assets/metrics');

        $response->assertOk()
            ->assertJsonPath('status', 'success');

        $metrics = $response->json('data.metrics');

        $this->assertNotEmpty($metrics);
        $this->assertSame($asset->id, $metrics[0]['asset_id']);
        $this->assertSame('AAA', $metrics[0]['symbol']);
        $this->assertArrayHasKey('roc13', $metrics[0]);
        $this->assertSame(0, $metrics[0]['roc13']);
    }
}
