<?php

namespace Tests\Unit;

use App\Services\BarCsvMirror;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class BarCsvMirrorTest extends TestCase
{
    private string $seedDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDir = storage_path('framework/testing/seed-'.bin2hex(random_bytes(4)));
        mkdir($this->seedDir, 0777, true);

        config([
            'csv.seed_dir' => $this->seedDir,
            'csv.mirror_path' => 'seeds/historical',
            'csv.mirror_disk' => null,
        ]);

        @unlink(storage_path('app/bar-csv-mirror.json'));

        Storage::fake('mirror');
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

    public function test_mirroring_is_disabled_by_default(): void
    {
        $mirror = new BarCsvMirror;

        $this->assertFalse($mirror->enabled());
        $this->assertNull($mirror->resolveDisk());

        $this->writeLocal('BBCA', "Date,Open\n01/01/2024,100\n");

        $hydrate = $mirror->hydrate(['BBCA']);
        $flush = $mirror->flush(['BBCA']);

        $this->assertNull($hydrate['disk']);
        $this->assertNull($flush['disk']);
        $this->assertSame([], $flush['uploaded']);

        // No mirror disk means no remote writes at all.
        $this->assertSame([], Storage::disk('mirror')->allFiles());
    }

    public function test_an_explicit_disk_overrides_the_disabled_default(): void
    {
        $mirror = new BarCsvMirror;

        $this->assertSame('mirror', $mirror->resolveDisk('mirror'));
        $this->assertTrue($mirror->enabled('mirror'));
    }

    public function test_configured_disk_is_used_when_no_override_is_given(): void
    {
        config(['csv.mirror_disk' => 'mirror']);

        $this->assertSame('mirror', (new BarCsvMirror)->resolveDisk());
    }

    public function test_flush_uploads_local_csvs_to_the_mirror(): void
    {
        $contents = "Date,Open,High,Low,Close,Volume\n02/01/2024,100,110,90,105,1000\n";
        $this->writeLocal('BBCA', $contents);

        $result = (new BarCsvMirror)->flush(['BBCA'], 'mirror');

        $this->assertSame(['BBCA'], $result['uploaded']);
        Storage::disk('mirror')->assertExists('seeds/historical/BBCA.csv');
        $this->assertSame($contents, Storage::disk('mirror')->get('seeds/historical/BBCA.csv'));
    }

    public function test_flush_without_symbols_uploads_every_local_csv(): void
    {
        $this->writeLocal('BBCA', "Date,Open\n02/01/2024,100\n");
        $this->writeLocal('TLKM', "Date,Open\n02/01/2024,200\n");

        $result = (new BarCsvMirror)->flush([], 'mirror');

        $this->assertSame(['BBCA', 'TLKM'], $result['uploaded']);
        Storage::disk('mirror')->assertExists('seeds/historical/BBCA.csv');
        Storage::disk('mirror')->assertExists('seeds/historical/TLKM.csv');
    }

    public function test_flush_skips_unchanged_files_on_a_second_run(): void
    {
        $this->writeLocal('BBCA', "Date,Open\n02/01/2024,100\n");

        $mirror = new BarCsvMirror;
        $this->assertSame(['BBCA'], $mirror->flush(['BBCA'], 'mirror')['uploaded']);

        $second = (new BarCsvMirror)->flush(['BBCA'], 'mirror');

        $this->assertSame([], $second['uploaded']);
        $this->assertSame(['BBCA'], $second['skipped']);
    }

    public function test_flush_re_uploads_after_the_local_file_changes(): void
    {
        $this->writeLocal('BBCA', "Date,Open\n02/01/2024,100\n");
        (new BarCsvMirror)->flush(['BBCA'], 'mirror');

        $this->writeLocal('BBCA', "Date,Open\n02/01/2024,100\n03/01/2024,101\n");
        $result = (new BarCsvMirror)->flush(['BBCA'], 'mirror');

        $this->assertSame(['BBCA'], $result['uploaded']);
        $this->assertStringContainsString('03/01/2024', Storage::disk('mirror')->get('seeds/historical/BBCA.csv'));
    }

    public function test_flush_skips_symbols_with_no_local_file(): void
    {
        $result = (new BarCsvMirror)->flush(['NOPE'], 'mirror');

        $this->assertSame([], $result['uploaded']);
        $this->assertSame(['NOPE'], $result['skipped']);
        Storage::disk('mirror')->assertMissing('seeds/historical/NOPE.csv');
    }

    public function test_hydrate_downloads_when_the_local_file_is_missing(): void
    {
        $contents = "Date,Open,High,Low,Close,Volume\n02/01/2024,100,110,90,105,1000\n";
        Storage::disk('mirror')->put('seeds/historical/BBCA.csv', $contents);

        $result = (new BarCsvMirror)->hydrate(['BBCA'], 'mirror');

        $this->assertSame(['BBCA'], $result['hydrated']);
        $this->assertFileExists($this->seedDir.'/BBCA.csv');
        $this->assertSame($contents, file_get_contents($this->seedDir.'/BBCA.csv'));
    }

    public function test_hydrate_without_symbols_pulls_everything_on_the_mirror(): void
    {
        Storage::disk('mirror')->put('seeds/historical/BBCA.csv', "Date,Open\n02/01/2024,100\n");
        Storage::disk('mirror')->put('seeds/historical/TLKM.csv', "Date,Open\n02/01/2024,200\n");

        $result = (new BarCsvMirror)->hydrate([], 'mirror');

        $this->assertSame(['BBCA', 'TLKM'], $result['hydrated']);
        $this->assertFileExists($this->seedDir.'/BBCA.csv');
        $this->assertFileExists($this->seedDir.'/TLKM.csv');
    }

    public function test_hydrate_skips_silently_when_the_remote_is_absent(): void
    {
        $result = (new BarCsvMirror)->hydrate(['GHOST'], 'mirror');

        $this->assertSame([], $result['hydrated']);
        $this->assertSame(['GHOST'], $result['skipped']);
        $this->assertFileDoesNotExist($this->seedDir.'/GHOST.csv');
    }

    public function test_hydrate_keeps_a_local_file_covering_the_same_bars_as_the_remote(): void
    {
        // Same single bar on both sides, differing only in value. Coverage is
        // equal, so the local working copy is kept -- the run is about to
        // extend it and flush() sends it back. A remote edited outside this
        // application is not something the mirror can detect either way; see
        // the note in flush().
        $local = "Date,Open\n02/01/2024,999\n";
        Storage::disk('mirror')->put('seeds/historical/BBCA.csv', "Date,Open\n02/01/2024,100\n");
        $this->writeLocal('BBCA', $local);
        touch($this->seedDir.'/BBCA.csv', time() + 600);

        $result = (new BarCsvMirror)->hydrate(['BBCA'], 'mirror');

        $this->assertSame([], $result['hydrated']);
        $this->assertSame(['BBCA'], $result['skipped']);
        $this->assertSame($local, file_get_contents($this->seedDir.'/BBCA.csv'));
    }

    public function test_hydrate_overwrites_a_local_file_holding_fewer_bars_than_the_remote(): void
    {
        // Deliberately stamped *newer* than the remote: a deploy's
        // `git reset --hard` rewrites a tracked CSV with a fresh mtime, so the
        // timestamp cannot be what decides this. Bar coverage can.
        $remote = "Date,Open\n02/01/2024,100\n03/01/2024,101\n04/01/2024,102\n";
        Storage::disk('mirror')->put('seeds/historical/BBCA.csv', $remote);
        $this->writeLocal('BBCA', "Date,Open\n02/01/2024,100\n");
        touch($this->seedDir.'/BBCA.csv', time() + 600);

        $result = (new BarCsvMirror)->hydrate(['BBCA'], 'mirror');

        $this->assertSame(['BBCA'], $result['hydrated']);
        $this->assertSame($remote, file_get_contents($this->seedDir.'/BBCA.csv'));
    }

    public function test_hydrate_keeps_a_local_file_holding_more_bars_than_the_remote(): void
    {
        // The mirror image, and the reason coverage beats mtime in both
        // directions: a run that extended the CSV and died before flushing
        // must not have its extra bars pulled out from under it.
        $local = "Date,Open\n02/01/2024,100\n03/01/2024,101\n";
        Storage::disk('mirror')->put('seeds/historical/BBCA.csv', "Date,Open\n02/01/2024,100\n");
        $this->writeLocal('BBCA', $local);
        touch($this->seedDir.'/BBCA.csv', time() - 86400);

        $result = (new BarCsvMirror)->hydrate(['BBCA'], 'mirror');

        $this->assertSame([], $result['hydrated']);
        $this->assertSame($local, file_get_contents($this->seedDir.'/BBCA.csv'));
    }

    public function test_force_hydrate_ignores_bar_coverage(): void
    {
        // Disaster recovery: pull the mirror down whatever local holds.
        $remote = "Date,Open\n02/01/2024,100\n";
        Storage::disk('mirror')->put('seeds/historical/BBCA.csv', $remote);
        $this->writeLocal('BBCA', "Date,Open\n02/01/2024,999\n03/01/2024,101\n");

        $result = (new BarCsvMirror)->hydrate(['BBCA'], 'mirror', force: true);

        $this->assertSame(['BBCA'], $result['hydrated']);
        $this->assertSame($remote, file_get_contents($this->seedDir.'/BBCA.csv'));
    }

    public function test_hydrated_files_are_not_immediately_re_uploaded(): void
    {
        Storage::disk('mirror')->put('seeds/historical/BBCA.csv', "Date,Open\n02/01/2024,100\n");

        $mirror = new BarCsvMirror;
        $mirror->hydrate(['BBCA'], 'mirror');
        $result = $mirror->flush(['BBCA'], 'mirror');

        $this->assertSame([], $result['uploaded']);
        $this->assertSame(['BBCA'], $result['skipped']);
    }

    public function test_a_flushed_symbol_is_not_re_downloaded_on_the_next_run(): void
    {
        // flush() stamps the remote mtime to now, leaving every remote file
        // looking newer than its local counterpart. Without the hash check that
        // would re-download the entire mirror on every single run.
        $this->writeLocal('BBCA', "Date,Open\n02/01/2024,100\n");
        touch($this->seedDir.'/BBCA.csv', time() - 86400);

        (new BarCsvMirror)->flush(['BBCA'], 'mirror');

        $result = (new BarCsvMirror)->hydrate(['BBCA'], 'mirror');

        $this->assertSame([], $result['hydrated']);
        $this->assertSame(['BBCA'], $result['skipped']);
    }

    public function test_force_hydrates_even_when_local_and_remote_agree(): void
    {
        $remote = "Date,Open\n02/01/2024,100\n";
        Storage::disk('mirror')->put('seeds/historical/BBCA.csv', $remote);

        $mirror = new BarCsvMirror;
        $mirror->hydrate(['BBCA'], 'mirror');

        // Local drifts out of band; --force restores the mirror's copy.
        $this->writeLocal('BBCA', "Date,Open\n02/01/2024,999\n");
        touch($this->seedDir.'/BBCA.csv', time() + 600);

        $result = (new BarCsvMirror)->hydrate(['BBCA'], 'mirror', true);

        $this->assertSame(['BBCA'], $result['hydrated']);
        $this->assertSame($remote, file_get_contents($this->seedDir.'/BBCA.csv'));
    }

    public function test_a_locally_modified_csv_is_not_clobbered_by_hydrate(): void
    {
        $this->writeLocal('BBCA', "Date,Open\n02/01/2024,100\n");
        (new BarCsvMirror)->flush(['BBCA'], 'mirror');

        $edited = "Date,Open\n02/01/2024,100\n03/01/2024,101\n";
        $this->writeLocal('BBCA', $edited);

        $result = (new BarCsvMirror)->hydrate(['BBCA'], 'mirror');

        $this->assertSame([], $result['hydrated']);
        $this->assertSame($edited, file_get_contents($this->seedDir.'/BBCA.csv'));

        // And the local change still reaches the mirror afterwards.
        $this->assertSame(['BBCA'], (new BarCsvMirror)->flush(['BBCA'], 'mirror')['uploaded']);
    }

    public function test_forget_makes_the_next_flush_re_upload(): void
    {
        $this->writeLocal('BBCA', "Date,Open\n02/01/2024,100\n");

        $mirror = new BarCsvMirror;
        $mirror->flush(['BBCA'], 'mirror');
        $this->assertSame([], $mirror->flush(['BBCA'], 'mirror')['uploaded']);

        $mirror->forget(['BBCA']);

        $this->assertSame(['BBCA'], $mirror->flush(['BBCA'], 'mirror')['uploaded']);
    }

    public function test_forget_without_symbols_clears_everything(): void
    {
        $this->writeLocal('BBCA', "Date,Open\n02/01/2024,100\n");
        $this->writeLocal('TLKM', "Date,Open\n02/01/2024,200\n");

        $mirror = new BarCsvMirror;
        $mirror->flush([], 'mirror');
        $mirror->forget();

        $this->assertSame(['BBCA', 'TLKM'], $mirror->flush([], 'mirror')['uploaded']);
    }

    public function test_an_unresolvable_mirror_disk_does_not_break_the_run(): void
    {
        $mirror = new BarCsvMirror;
        $this->writeLocal('BBCA', "Date,Open\n02/01/2024,100\n");

        $hydrate = $mirror->hydrate(['BBCA'], 'does-not-exist');
        $flush = $mirror->flush(['BBCA'], 'does-not-exist');

        $this->assertSame([], $hydrate['hydrated']);
        $this->assertSame([], $flush['uploaded']);
        $this->assertFileExists($this->seedDir.'/BBCA.csv');
    }

    public function test_rate_limited_uploads_are_retried_with_backoff(): void
    {
        $this->writeLocal('BBCA', "Date,Open\n02/01/2024,100\n");

        $attempts = 0;
        $delays = [];

        Storage::shouldReceive('disk')->with('mirror')->andReturn($fake = \Mockery::mock(Filesystem::class));
        $fake->shouldReceive('put')->times(3)->andReturnUsing(function () use (&$attempts) {
            $attempts++;

            if ($attempts < 3) {
                throw new RuntimeException('Google Drive: 403 rateLimitExceeded');
            }

            return true;
        });

        $mirror = new BarCsvMirror;
        $mirror->setSleeper(function (int $microseconds) use (&$delays) {
            $delays[] = $microseconds;
        });

        $result = $mirror->flush(['BBCA'], 'mirror');

        $this->assertSame(['BBCA'], $result['uploaded']);
        $this->assertSame(3, $attempts);
        $this->assertSame([500_000, 1_000_000], $delays, 'Backoff should double between retries.');
    }

    public function test_a_non_retryable_failure_is_reported_once_and_does_not_abort(): void
    {
        $this->writeLocal('BBCA', "Date,Open\n02/01/2024,100\n");

        $attempts = 0;

        Storage::shouldReceive('disk')->with('mirror')->andReturn($fake = \Mockery::mock(Filesystem::class));
        $fake->shouldReceive('put')->andReturnUsing(function () use (&$attempts) {
            $attempts++;

            throw new RuntimeException('invalid credentials');
        });

        $mirror = new BarCsvMirror;
        $mirror->setSleeper(fn () => null);

        $result = $mirror->flush(['BBCA'], 'mirror');

        $this->assertSame(1, $attempts, 'Non-retryable errors should not be retried.');
        $this->assertSame(['BBCA'], $result['failed']);
        $this->assertSame([], $result['uploaded']);
    }

    public function test_a_failed_upload_is_retried_on_the_next_run(): void
    {
        $this->writeLocal('BBCA', "Date,Open\n02/01/2024,100\n");

        $calls = 0;

        Storage::shouldReceive('disk')->with('mirror')->andReturn($fake = \Mockery::mock(Filesystem::class));
        $fake->shouldReceive('put')->twice()->andReturnUsing(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                throw new RuntimeException('invalid credentials');
            }

            return true;
        });

        $first = new BarCsvMirror;
        $first->setSleeper(fn () => null);
        $this->assertSame(['BBCA'], $first->flush(['BBCA'], 'mirror')['failed']);

        // A failure must not be recorded as mirrored, or the retry never happens.
        $retry = (new BarCsvMirror)->flush(['BBCA'], 'mirror');

        $this->assertSame(['BBCA'], $retry['uploaded']);
        $this->assertSame(2, $calls);
    }

    public function test_paths_are_derived_from_configuration(): void
    {
        config(['csv.mirror_path' => 'archive/bars']);

        $mirror = new BarCsvMirror;

        $this->assertSame($this->seedDir.'/BBCA.csv', $mirror->localPath('bbca'));
        $this->assertSame('archive/bars/BBCA.csv', $mirror->remotePath('bbca'));
    }

    private function writeLocal(string $symbol, string $contents): void
    {
        file_put_contents($this->seedDir.'/'.strtoupper($symbol).'.csv', $contents);
    }
}
