<?php

namespace Tests\Feature;

use App\Models\Asset;
use Database\Seeders\AssetProfileJsonSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * The seeder is what a fresh deployment builds its assets from, so an asset
 * with no profile JSON is a hole in that: it gets seeded without its IPO date,
 * free float or listing information, and costs a profile fetch on every scrape
 * afterwards. The seeder cannot fetch the profile itself -- it must not need a
 * live Stockbit token -- so it names the gap and how to close it.
 */
class AssetProfileJsonSeederTest extends TestCase
{
    use RefreshDatabase;

    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        // A database path of this test's own, so the 50-odd committed profiles
        // are neither seeded nor written to while the assertions run.
        $this->databasePath = sys_get_temp_dir().'/profile-seeder-'.uniqid();
        File::makeDirectory($this->databasePath.'/seeders/data/profiles', 0755, true);
        $this->app->useDatabasePath($this->databasePath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->databasePath);

        parent::tearDown();
    }

    public function test_it_reports_assets_with_no_profile_json(): void
    {
        $this->writeProfile('AAAA');

        Asset::create(['symbol' => 'AAAA', 'name' => 'Has a profile']);
        Asset::create(['symbol' => 'BBBB', 'name' => 'Added from the index panel']);
        Asset::create(['symbol' => 'CCCC', 'name' => 'Added from the index panel']);

        $output = $this->runSeeder();

        $this->assertStringContainsString('2 asset(s) have no profile JSON to seed from: BBBB, CCCC', $output);
        $this->assertStringContainsString('php artisan stockbit:scrape BBBB CCCC', $output);
        $this->assertStringNotContainsString('AAAA,', $output, 'A seeded asset is not a gap.');
    }

    public function test_it_says_nothing_when_every_asset_has_a_profile(): void
    {
        $this->writeProfile('AAAA');
        Asset::create(['symbol' => 'AAAA', 'name' => 'Has a profile']);

        $this->assertStringNotContainsString('no profile JSON to seed from', $this->runSeeder());
    }

    private function writeProfile(string $symbol): void
    {
        File::put(
            $this->databasePath."/seeders/data/profiles/{$symbol}_profile.json",
            json_encode(['symbol' => $symbol, 'name' => $symbol])
        );
    }

    private function runSeeder(): string
    {
        $buffer = new BufferedOutput;

        $command = new Command;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

        $seeder = $this->app->make(AssetProfileJsonSeeder::class);
        $seeder->setCommand($command);
        $seeder->run();

        return $buffer->fetch();
    }
}
