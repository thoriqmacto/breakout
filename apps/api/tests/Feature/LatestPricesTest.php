<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Price;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LatestPricesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/assets/latest-prices')->assertUnauthorized();
    }

    public function test_it_returns_latest_price_for_all_assets(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

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
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['asset_id' => $asset1->id, 'close' => 12])
            ->assertJsonFragment(['asset_id' => $asset2->id, 'close' => 22]);
    }

    public function test_asset_sync_fetches_missing_data_when_outdated(): void
    {
        config(['csv.index_symbols' => ['AAA']]);
        $seedDir = database_path('seeders/data/test-sync');
        if (!is_dir($seedDir)) {
            mkdir($seedDir, 0755, true);
        }
        file_put_contents($seedDir . '/AAA.csv', "date,open,high,low,close,volume\n2024-01-01,1,1,1,1,100\n");
        config(['csv.seed_dir' => $seedDir]);

        $pyDir = resource_path('python/csv');
        if (!is_dir($pyDir)) {
            mkdir($pyDir, 0755, true);
        }
        file_put_contents($pyDir . '/AAA_PY.csv', "date,open,high,low,close,volume\n2024-01-02,1,1,1,1,100\n2024-01-03,1,1,1,1,100\n");

        config(['python.bin' => '/bin/echo']);

        $this->artisan('asset:sync')
            ->expectsConfirmation('Continue checking latest data anyway?', 'yes')
            ->expectsConfirmation('Do you have your own chk-date to compare with latest-date data?', 'yes')
            ->expectsQuestion('Enter chk-date (YYYY-MM-DD)', '2024-01-03')
            ->assertExitCode(0);

        $this->assertDatabaseCount('price_bars', 3);
        $this->assertDatabaseHas('price_bars', ['date' => '2024-01-03']);
    }

    public function test_asset_sync_eod_option_uses_today_date(): void
    {
        Carbon::setTestNow('2024-01-03');

        config(['csv.index_symbols' => ['AAA']]);
        $seedDir = database_path('seeders/data/test-eod');
        if (!is_dir($seedDir)) {
            mkdir($seedDir, 0755, true);
        }
        file_put_contents($seedDir . '/AAA.csv', "date,open,high,low,close,volume\n2024-01-01,1,1,1,1,100\n");
        config(['csv.seed_dir' => $seedDir]);

        $pyDir = resource_path('python/csv');
        if (!is_dir($pyDir)) {
            mkdir($pyDir, 0755, true);
        }
        file_put_contents($pyDir . '/AAA_PY.csv', "date,open,high,low,close,volume\n2024-01-02,1,1,1,1,100\n2024-01-03,1,1,1,1,100\n");

        config(['python.bin' => '/bin/echo']);

        $this->artisan('asset:sync', ['--eod' => true])
            ->assertExitCode(0);

        $this->assertDatabaseCount('price_bars', 3);
        $this->assertDatabaseHas('price_bars', ['date' => '2024-01-03']);
    }
}
