<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssetSyncSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_sync_flags_for_assets(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $asset = Asset::create([
            'symbol' => 'BBCA',
            'name' => 'Bank Central Asia',
            'sync_price' => true,
            'sync_profile' => true,
            'sync_broker_summary' => true,
        ]);

        $response = $this->postJson('/api/v1/assets/sync-settings', [
            'assets' => [
                [
                    'id' => $asset->id,
                    'sync_price' => false,
                    'sync_profile' => false,
                    'sync_broker_summary' => true,
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.assets.0.id', $asset->id)
            ->assertJsonPath('data.assets.0.sync_price', false)
            ->assertJsonPath('data.assets.0.sync_profile', false)
            ->assertJsonPath('data.assets.0.sync_broker_summary', true);

        $this->assertDatabaseHas('assets', [
            'id' => $asset->id,
            'sync_price' => false,
            'sync_profile' => false,
            'sync_broker_summary' => true,
        ]);
    }
}
