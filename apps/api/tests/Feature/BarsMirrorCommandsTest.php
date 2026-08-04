<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BarsMirrorCommandsTest extends TestCase
{
    private string $seedDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDir = storage_path('framework/testing/seed-mirror-'.bin2hex(random_bytes(4)));
        mkdir($this->seedDir, 0777, true);

        config([
            'csv.seed_dir' => $this->seedDir,
            'csv.mirror_path' => 'seeds/historical',
            'csv.mirror_disk' => null,
        ]);

        @unlink(storage_path('app/bar-csv-mirror.json'));

        Storage::fake('gdrive');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->seedDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->seedDir);
        @unlink(storage_path('app/bar-csv-mirror.json'));

        parent::tearDown();
    }

    public function test_push_uploads_every_local_csv_and_counts_match(): void
    {
        $this->writeLocal('BBCA');
        $this->writeLocal('BBRI');
        $this->writeLocal('TLKM');

        $exit = Artisan::call('bars:mirror-push', ['--disk' => 'gdrive']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Uploaded 3', $output);
        $this->assertStringContainsString('Every local seed CSV is present on the mirror.', $output);

        foreach (['BBCA', 'BBRI', 'TLKM'] as $symbol) {
            Storage::disk('gdrive')->assertExists("seeds/historical/{$symbol}.csv");
        }

        $this->assertCount(3, Storage::disk('gdrive')->files('seeds/historical'));
    }

    public function test_push_can_be_limited_to_specific_symbols(): void
    {
        $this->writeLocal('BBCA');
        $this->writeLocal('BBRI');

        $exit = Artisan::call('bars:mirror-push', ['--disk' => 'gdrive', '--symbol' => ['BBCA']]);

        Storage::disk('gdrive')->assertExists('seeds/historical/BBCA.csv');
        Storage::disk('gdrive')->assertMissing('seeds/historical/BBRI.csv');

        // BBRI is local but not mirrored, so the count check reports a mismatch.
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not on the mirror yet: BBRI', Artisan::output());
    }

    public function test_push_is_idempotent_and_force_re_uploads(): void
    {
        $this->writeLocal('BBCA');

        Artisan::call('bars:mirror-push', ['--disk' => 'gdrive']);
        Artisan::call('bars:mirror-push', ['--disk' => 'gdrive']);

        $this->assertStringContainsString('Uploaded 0, skipped 1', Artisan::output());

        Artisan::call('bars:mirror-push', ['--disk' => 'gdrive', '--force' => true]);

        $this->assertStringContainsString('Uploaded 1', Artisan::output());
    }

    public function test_push_reports_when_there_is_nothing_to_push(): void
    {
        $exit = Artisan::call('bars:mirror-push', ['--disk' => 'gdrive']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('nothing to push', Artisan::output());
    }

    public function test_pull_restores_local_csvs_from_the_mirror(): void
    {
        Storage::disk('gdrive')->put('seeds/historical/BBCA.csv', "Date,Open\n02/01/2024,100\n");
        Storage::disk('gdrive')->put('seeds/historical/BBRI.csv', "Date,Open\n02/01/2024,200\n");

        $exit = Artisan::call('bars:mirror-pull', ['--disk' => 'gdrive']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Downloaded 2', $output);
        $this->assertStringContainsString('Every mirrored seed CSV is present locally.', $output);
        $this->assertFileExists($this->seedDir.'/BBCA.csv');
        $this->assertFileExists($this->seedDir.'/BBRI.csv');
    }

    public function test_pull_keeps_a_newer_local_csv_unless_forced(): void
    {
        Storage::disk('gdrive')->put('seeds/historical/BBCA.csv', "Date,Open\n02/01/2024,100\n");
        $this->writeLocal('BBCA', "Date,Open\n02/01/2024,999\n");
        touch($this->seedDir.'/BBCA.csv', time() + 600);

        Artisan::call('bars:mirror-pull', ['--disk' => 'gdrive']);

        $this->assertStringContainsString('999', File::get($this->seedDir.'/BBCA.csv'));

        Artisan::call('bars:mirror-pull', ['--disk' => 'gdrive', '--force' => true]);

        $this->assertStringContainsString('100', File::get($this->seedDir.'/BBCA.csv'));
        $this->assertStringNotContainsString('999', File::get($this->seedDir.'/BBCA.csv'));
    }

    public function test_pull_reports_when_the_mirror_is_empty(): void
    {
        $exit = Artisan::call('bars:mirror-pull', ['--disk' => 'gdrive']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('nothing to pull', Artisan::output());
    }

    public function test_commands_reject_an_empty_disk(): void
    {
        $this->assertSame(2, Artisan::call('bars:mirror-push', ['--disk' => '']));
        $this->assertStringContainsString('No mirror disk resolved', Artisan::output());

        $this->assertSame(2, Artisan::call('bars:mirror-pull', ['--disk' => '']));
        $this->assertStringContainsString('No mirror disk resolved', Artisan::output());
    }

    public function test_push_round_trips_through_pull(): void
    {
        $contents = "Date,Open,High,Low,Close,Volume\n02/01/2024,100,110,90,105,1000\n";
        $this->writeLocal('BBCA', $contents);

        Artisan::call('bars:mirror-push', ['--disk' => 'gdrive']);

        unlink($this->seedDir.'/BBCA.csv');
        $this->assertFileDoesNotExist($this->seedDir.'/BBCA.csv');

        Artisan::call('bars:mirror-pull', ['--disk' => 'gdrive']);

        $this->assertSame($contents, File::get($this->seedDir.'/BBCA.csv'));
    }

    private function writeLocal(string $symbol, ?string $contents = null): void
    {
        file_put_contents(
            $this->seedDir.'/'.strtoupper($symbol).'.csv',
            $contents ?? "Date,Open,High,Low,Close,Volume\n02/01/2024,100,110,90,105,1000\n"
        );
    }
}
