<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Services\AssetProfileUpdater;
use App\Services\StockbitExodusClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Exercises the seed-CSV mirror as the data-mutating commands use it.
 *
 * The regression guard is the first test: with no mirror disk configured the
 * run must not touch remote storage at all, which is the shipped default.
 */
class SeedCsvMirrorCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $seedDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDir = storage_path('framework/testing/seed-cmd-'.bin2hex(random_bytes(4)));
        mkdir($this->seedDir, 0777, true);

        config()->set('csv.seed_dir', $this->seedDir);
        config()->set('csv.mirror_path', 'seeds/historical');
        config()->set('csv.mirror_disk', null);

        config()->set('stockbit.bearer', 'test-bearer-token');
        config()->set('stockbit.save_disk', 'local');
        config()->set('stockbit.historical.period', 'HS_PERIOD_DAILY');
        config()->set('stockbit.historical.page', 1);

        @unlink(storage_path('app/bar-csv-mirror.json'));

        Storage::fake('local');
        Storage::fake('gdrive');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Carbon::setTestNow();

        foreach (glob($this->seedDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->seedDir);
        @unlink(storage_path('app/bar-csv-mirror.json'));

        parent::tearDown();
    }

    public function test_without_a_mirror_disk_the_scrape_writes_only_locally(): void
    {
        $asset = Asset::create(['symbol' => 'BBRI', 'name' => 'BBRI']);

        $this->runHistoricalScrape();

        // The CSV and the DB bars land exactly as they always have...
        $this->assertFileExists($this->seedDir.'/BBRI.csv');
        $this->assertDatabaseHas('price_bars', [
            'asset_id' => $asset->id,
            'date' => '2024-02-01',
            'close' => 105,
        ]);

        // ...and nothing was mirrored.
        $this->assertSame([], Storage::disk('gdrive')->allFiles());
        $this->assertFileDoesNotExist(storage_path('app/bar-csv-mirror.json'));
    }

    public function test_the_scrape_mirrors_the_csv_when_a_disk_is_given(): void
    {
        $asset = Asset::create(['symbol' => 'BBRI', 'name' => 'BBRI']);

        $this->runHistoricalScrape(['--disk' => 'gdrive']);

        // The CSV still lives on local disk; Drive is the durable copy.
        $this->assertFileExists($this->seedDir.'/BBRI.csv');
        Storage::disk('gdrive')->assertExists('seeds/historical/BBRI.csv');
        $this->assertSame(
            File::get($this->seedDir.'/BBRI.csv'),
            Storage::disk('gdrive')->get('seeds/historical/BBRI.csv')
        );

        // The DB stays the query layer.
        $this->assertDatabaseHas('price_bars', [
            'asset_id' => $asset->id,
            'date' => '2024-02-04',
            'close' => 155,
        ]);
    }

    public function test_the_configured_mirror_disk_is_used_without_the_option(): void
    {
        config()->set('csv.mirror_disk', 'gdrive');
        Asset::create(['symbol' => 'BBRI', 'name' => 'BBRI']);

        $this->runHistoricalScrape();

        Storage::disk('gdrive')->assertExists('seeds/historical/BBRI.csv');
    }

    public function test_a_clean_checkout_hydrates_the_csv_from_the_mirror_before_merging(): void
    {
        Asset::create(['symbol' => 'BBRI', 'name' => 'BBRI']);

        // A previous run left history on the mirror; the local checkout has none.
        Storage::disk('gdrive')->put(
            'seeds/historical/BBRI.csv',
            "Date,Open,High,Low,Close,Volume\n03/01/2024,10,11,9,10,50\n"
        );
        $this->assertFileDoesNotExist($this->seedDir.'/BBRI.csv');

        $this->runHistoricalScrape(['--disk' => 'gdrive']);

        $csv = File::get($this->seedDir.'/BBRI.csv');

        // The hydrated row survives the merge instead of being overwritten...
        $this->assertStringContainsString('03/01/2024,10,11,9,10,50', $csv);
        // ...alongside the freshly scraped ones.
        $this->assertStringContainsString('01/02/2024,100,110,90,105,1000', $csv);
        $this->assertStringContainsString('04/02/2024,150,160,140,155,3000', $csv);

        // And the merged result is pushed back.
        $this->assertSame($csv, Storage::disk('gdrive')->get('seeds/historical/BBRI.csv'));
    }

    /**
     * The JSON path needed no code change: it already writes through
     * Storage::disk(config('stockbit.save_disk')). Pointing that at the Drive
     * disk must move the payload without disturbing the DB, which stays the
     * query layer.
     */
    public function test_sb_save_disk_routes_the_historical_json_to_the_drive_disk(): void
    {
        config()->set('stockbit.save_disk', 'gdrive');
        $asset = Asset::create(['symbol' => 'BBRI', 'name' => 'BBRI']);

        $this->runHistoricalScrape(['--disk' => 'gdrive']);

        // The raw payload lands on the Drive disk...
        Storage::disk('gdrive')->assertExists('historical/BBRI_2024-02-01_2024-02-05_HS_PERIOD_DAILY.json');
        Storage::disk('local')->assertMissing('historical/BBRI_2024-02-01_2024-02-05_HS_PERIOD_DAILY.json');

        // Stored as one entry per fetched page, each the extracted result list.
        $payload = json_decode(Storage::disk('gdrive')->get('historical/BBRI_2024-02-01_2024-02-05_HS_PERIOD_DAILY.json'), true);
        $this->assertSame('2024-02-01', $payload[0][0]['date']);
        $this->assertSame('2024-02-04', $payload[0][1]['date']);

        // ...and the bars still land in the database.
        $this->assertDatabaseHas('price_bars', [
            'asset_id' => $asset->id,
            'date' => '2024-02-01',
            'close' => 105,
        ]);
        $this->assertDatabaseHas('price_bars', [
            'asset_id' => $asset->id,
            'date' => '2024-02-04',
            'close' => 155,
        ]);
    }

    public function test_csv_fix_date_format_mirrors_the_seed_directory(): void
    {
        File::put($this->seedDir.'/BBRI.csv', "Date,Open,High,Low,Close,Volume\n2024-02-01,100,110,90,105,1000\n");

        Artisan::call('csv:fix-date-format', [
            '--dir' => $this->seedDir,
            '--disk' => 'gdrive',
        ]);

        // The command normalizes the date format locally...
        $this->assertStringContainsString('01/02/2024', File::get($this->seedDir.'/BBRI.csv'));
        // ...and the normalized file is mirrored.
        Storage::disk('gdrive')->assertExists('seeds/historical/BBRI.csv');
        $this->assertStringContainsString('01/02/2024', Storage::disk('gdrive')->get('seeds/historical/BBRI.csv'));
    }

    public function test_csv_fix_date_format_leaves_the_mirror_alone_for_unrelated_directories(): void
    {
        $other = storage_path('framework/testing/other-'.bin2hex(random_bytes(4)));
        mkdir($other, 0777, true);
        File::put($other.'/NOTES.csv', "Date,Open,High,Low,Close,Volume\n2024-02-01,100,110,90,105,1000\n");
        File::put($this->seedDir.'/BBRI.csv', "Date,Open,High,Low,Close,Volume\n01/02/2024,100,110,90,105,1000\n");

        try {
            Artisan::call('csv:fix-date-format', [
                '--dir' => $other,
                '--disk' => 'gdrive',
            ]);

            $this->assertSame([], Storage::disk('gdrive')->allFiles());
        } finally {
            @unlink($other.'/NOTES.csv');
            @rmdir($other);
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function runHistoricalScrape(array $options = []): void
    {
        $mock = Mockery::mock(StockbitExodusClient::class);
        $mock->shouldReceive('setBearer')->andReturnNull();
        $mock->shouldReceive('marketDetectors')->never();
        $mock->shouldReceive('tickerProfile')->never();
        $mock->shouldReceive('historicalSummary')
            ->once()
            ->with('BBRI', 'HS_PERIOD_DAILY', '2024-02-01', '2024-02-05', null, 1)
            ->andReturn([
                'data' => [
                    'result' => [
                        ['date' => '2024-02-01', 'open' => '100', 'high' => '110', 'low' => '90', 'close' => '105', 'volume' => '1000'],
                        ['date' => '2024-02-04', 'open' => '150', 'high' => '160', 'low' => '140', 'close' => '155', 'volume' => '3000'],
                    ],
                ],
            ]);

        $this->app->instance(StockbitExodusClient::class, $mock);

        $profileUpdater = Mockery::mock(AssetProfileUpdater::class);
        $profileUpdater->shouldReceive('getIPODate')->andReturn(null);
        $this->app->instance(AssetProfileUpdater::class, $profileUpdater);

        Carbon::setTestNow('2024-02-06 10:00:00');

        Artisan::call('stockbit:scrape', array_merge([
            'tickers' => ['BBRI'],
            '--from' => '2024-02-01',
            '--to' => '2024-02-05',
            '--historical' => true,
            '--no-profile-sync' => true,
        ], $options));
    }
}
