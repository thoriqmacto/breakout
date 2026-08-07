<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Price;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LatestPricesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_authentication(): void
    {
        $asset = Asset::create(['symbol' => 'AAA', 'name' => 'Asset AAA']);

        $this->getJson("/api/v1/assets/{$asset->id}/latest-price")->assertUnauthorized();
    }

    public function test_it_returns_latest_price_for_requested_asset(): void
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

        $response = $this->getJson("/api/v1/assets/{$asset1->id}/latest-price");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.asset_id', $asset1->id)
            ->assertJsonPath('data.close', 12)
            ->assertJsonMissing(['asset_id' => $asset2->id]);
    }

    public function test_asset_sync_fetches_missing_data_when_outdated(): void
    {
        $seedDir = database_path('seeders/data/test-sync');
        if (! is_dir($seedDir)) {
            mkdir($seedDir, 0755, true);
        }
        file_put_contents($seedDir.'/AAA.csv', "date,open,high,low,close,volume\n2024-01-01,1,1,1,1,100\n");
        config(['csv.seed_dir' => $seedDir]);
        Asset::create(['symbol' => 'AAA', 'name' => 'Asset AAA']);

        $pyDir = resource_path('python/csv');
        if (! is_dir($pyDir)) {
            mkdir($pyDir, 0755, true);
        }
        file_put_contents($pyDir.'/AAA_PY.csv', "date,open,high,low,close,volume\n2024-01-02,1,1,1,1,100\n2024-01-03,1,1,1,1,100\n");

        config(['python.bin' => '/bin/echo']);

        foreach (['2024-01-01', '2024-01-02', '2024-01-03'] as $date) {
            \DB::table('trading_days')->insert([
                'date' => $date,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Drive the non-interactive refresh path: detect outdated data and
        // pull the missing days from the python/yfinance fallback CSV.
        $this->artisan('asset:sync', [
            '--check-python' => true,
            '--run-python' => true,
            '--import-csv' => true,
            '--continue' => true,
            '--chk-date' => '2024-01-03',
        ])->assertExitCode(0);

        $this->assertDatabaseCount('price_bars', 3);
        $this->assertDatabaseHas('price_bars', ['date' => '2024-01-03']);
    }

    public function test_asset_sync_eod_option_uses_today_date(): void
    {
        Carbon::setTestNow('2024-01-03');

        $seedDir = database_path('seeders/data/test-eod');
        if (! is_dir($seedDir)) {
            mkdir($seedDir, 0755, true);
        }
        file_put_contents($seedDir.'/AAA.csv', "date,open,high,low,close,volume\n2024-01-01,1,1,1,1,100\n");
        config(['csv.seed_dir' => $seedDir]);
        Asset::create(['symbol' => 'AAA', 'name' => 'Asset AAA']);

        $pyDir = resource_path('python/csv');
        if (! is_dir($pyDir)) {
            mkdir($pyDir, 0755, true);
        }
        file_put_contents($pyDir.'/AAA_PY.csv', "date,open,high,low,close,volume\n2024-01-02,1,1,1,1,100\n2024-01-03,1,1,1,1,100\n");

        config(['python.bin' => '/bin/echo']);

        \DB::table('trading_days')->insert([
            'date' => '2024-01-03',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('asset:sync', ['--eod' => true])
            ->assertExitCode(0);

        $this->assertDatabaseCount('price_bars', 3);
        $this->assertDatabaseHas('price_bars', ['date' => '2024-01-03']);
    }

    public function test_asset_sync_skips_assets_with_price_sync_disabled(): void
    {
        $seedDir = database_path('seeders/data/test-disabled-sync');
        if (! is_dir($seedDir)) {
            mkdir($seedDir, 0755, true);
        }
        file_put_contents($seedDir.'/AAA.csv', "date,open,high,low,close,volume\n2024-01-01,1,1,1,1,100\n");
        config(['csv.seed_dir' => $seedDir]);

        Asset::create([
            'symbol' => 'AAA',
            'name' => 'Asset AAA',
            'sync_price' => false,
        ]);

        $this->artisan('asset:sync', ['--continue' => true, '--chk-date' => '2024-01-02'])
            ->assertExitCode(0);

        $this->assertDatabaseCount('price_bars', 0);
    }

    public function test_asset_sync_historical_date_reupserts_specific_day_from_csv(): void
    {
        $seedDir = database_path('seeders/data/test-historical-date');
        if (! is_dir($seedDir)) {
            mkdir($seedDir, 0755, true);
        }

        file_put_contents(
            $seedDir.'/AAA.csv',
            "date,open,high,low,close,volume\n2024-01-02,11,12,10,15,1000\n"
        );
        config(['csv.seed_dir' => $seedDir]);

        $asset = Asset::create(['symbol' => 'AAA', 'name' => 'Asset AAA']);
        Price::create([
            'asset_id' => $asset->id,
            'date' => '2024-01-02',
            'open' => 1,
            'high' => 1,
            'low' => 1,
            'close' => 1,
            'volume' => 1,
        ]);

        $this->artisan('asset:sync', ['--historical-date' => '2024-01-02'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('price_bars', [
            'asset_id' => $asset->id,
            'date' => '2024-01-02',
            'open' => 11,
            'high' => 12,
            'low' => 10,
            'close' => 15,
            'volume' => 1000,
        ]);
    }

    public function test_asset_sync_historical_date_rejects_invalid_date(): void
    {
        Asset::create(['symbol' => 'AAA', 'name' => 'Asset AAA']);

        $this->artisan('asset:sync', ['--historical-date' => 'bad-date'])
            ->assertExitCode(1);
    }
}
