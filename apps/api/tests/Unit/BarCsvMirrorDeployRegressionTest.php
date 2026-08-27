<?php

namespace Tests\Unit;

use App\Services\BarCsvMirror;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What a deploy does to the seed CSVs.
 *
 * The seed CSVs live in `database/seeders/data/historical`, which is a
 * git-tracked directory, and the deploy runs `git reset --hard <sha>` in a
 * persistent checkout. So every deploy rewrites those files back to whatever
 * was committed -- discarding every bar the scheduler has appended since --
 * and rewrites them with a *fresh mtime*.
 *
 * That fresh mtime is the dangerous part. It makes a reverted file look newer
 * than the Drive copy that actually holds more data, so the hydrate that is
 * supposed to restore it stands down, the run appends today's bar to the
 * truncated file, and the flush then overwrites the good remote copy with it.
 * The durable backup ends up poorer than before the deploy.
 */
class BarCsvMirrorDeployRegressionTest extends TestCase
{
    private string $seedDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDir = storage_path('framework/testing/deploy-'.bin2hex(random_bytes(4)));
        mkdir($this->seedDir, 0777, true);

        config([
            'csv.seed_dir' => $this->seedDir,
            'csv.mirror_path' => 'seeds/historical',
            'csv.mirror_disk' => 'mirror',
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

    /**
     * A CSV holding one bar per listed date.
     *
     * @param  array<int, string>  $dates
     */
    private function csv(array $dates): string
    {
        $rows = "Date,Open,High,Low,Close,Volume\n";

        foreach ($dates as $date) {
            $rows .= sprintf("%s,100,110,90,105,1000\n", $date);
        }

        return $rows;
    }

    private function writeLocal(string $symbol, string $contents, ?int $modifiedAt = null): void
    {
        $path = $this->seedDir.'/'.strtoupper($symbol).'.csv';
        file_put_contents($path, $contents);

        if ($modifiedAt !== null) {
            touch($path, $modifiedAt);
        }
    }

    private function localContents(string $symbol): string
    {
        return (string) file_get_contents($this->seedDir.'/'.strtoupper($symbol).'.csv');
    }

    /**
     * Put the mirror in the state a week of scheduled runs leaves it in: the
     * remote holds the full series, and the manifest records that we sent it.
     */
    private function seedMirroredState(BarCsvMirror $mirror, string $symbol, string $full): void
    {
        $this->writeLocal($symbol, $full);
        $result = $mirror->flush([$symbol], 'mirror');

        $this->assertSame([$symbol], $result['uploaded']);
    }

    public function test_a_deploy_reverts_the_local_csv_and_hydrate_must_restore_it(): void
    {
        $mirror = new BarCsvMirror;
        $mirror->setSleeper(static fn () => null);

        // A week of scraping has extended the CSV to five bars, and the last
        // flush pushed all five to Drive.
        $full = $this->csv(['01/09/2026', '02/09/2026', '03/09/2026', '04/09/2026', '05/09/2026']);
        $this->seedMirroredState($mirror, 'BBCA', $full);

        // Drive's copy was written a while ago.
        Storage::disk('mirror')->setVisibility('seeds/historical/BBCA.csv', 'public');

        // Now a deploy lands: `git reset --hard` rewrites the tracked file back
        // to the two bars that were committed, stamped with the current time.
        $committed = $this->csv(['01/09/2026', '02/09/2026']);
        $this->writeLocal('BBCA', $committed, modifiedAt: time());

        $hydrate = $mirror->hydrate(['BBCA'], 'mirror');

        // The whole point of hydrating before a run: the richer remote copy
        // must come back, whatever the local file's mtime happens to say.
        $this->assertSame(['BBCA'], $hydrate['hydrated']);
        $this->assertSame($full, $this->localContents('BBCA'));
    }

    public function test_a_reverted_local_csv_never_overwrites_the_richer_remote_copy(): void
    {
        $mirror = new BarCsvMirror;
        $mirror->setSleeper(static fn () => null);

        $full = $this->csv(['01/09/2026', '02/09/2026', '03/09/2026', '04/09/2026', '05/09/2026']);
        $this->seedMirroredState($mirror, 'BBCA', $full);

        // The deploy reverts local to the committed two bars...
        $this->writeLocal('BBCA', $this->csv(['01/09/2026', '02/09/2026']), modifiedAt: time());

        // ...the scheduled run hydrates, then appends the day's bar and flushes,
        // exactly as MirrorsSeedCsvs bookends it.
        $mirror->hydrate(['BBCA'], 'mirror');

        $extended = $this->localContents('BBCA')."08/09/2026,100,110,90,105,1000\n";
        $this->writeLocal('BBCA', $extended);
        $mirror->flush(['BBCA'], 'mirror');

        $remote = (string) Storage::disk('mirror')->get('seeds/historical/BBCA.csv');

        // Drive must still hold every bar it held before the deploy, plus the
        // new one -- not the truncated file the deploy left behind.
        $this->assertStringContainsString('03/09/2026', $remote);
        $this->assertStringContainsString('05/09/2026', $remote);
        $this->assertStringContainsString('08/09/2026', $remote);
    }

    public function test_the_seed_directory_can_live_outside_the_git_worktree(): void
    {
        // The root fix: a deploy cannot revert what it cannot reach. With
        // CSV_SEED_DIR set, the CSVs are not tracked files and `git reset
        // --hard` never touches them.
        $outside = storage_path('framework/testing/outside-'.bin2hex(random_bytes(4)));
        mkdir($outside, 0777, true);

        try {
            config(['csv.seed_dir' => $outside]);

            $mirror = new BarCsvMirror;
            file_put_contents($outside.'/BBCA.csv', $this->csv(['01/09/2026', '02/09/2026']));

            $this->assertSame($outside.'/BBCA.csv', $mirror->localPath('BBCA'));
            $this->assertSame(['BBCA'], $mirror->localSymbols());

            // And it is genuinely a different place from the repository one.
            $this->assertNotSame(database_path('seeders/data/historical'), $outside);
        } finally {
            foreach (glob($outside.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($outside);
        }
    }

    public function test_a_locally_extended_csv_still_wins_over_a_stale_remote(): void
    {
        $mirror = new BarCsvMirror;
        $mirror->setSleeper(static fn () => null);

        $this->seedMirroredState($mirror, 'BBCA', $this->csv(['01/09/2026', '02/09/2026']));

        // A run extended the file but crashed before flushing. The extra bars
        // are only local, and hydrating must not throw them away.
        $extended = $this->csv(['01/09/2026', '02/09/2026', '03/09/2026']);
        $this->writeLocal('BBCA', $extended, modifiedAt: time() - 86_400);

        $hydrate = $mirror->hydrate(['BBCA'], 'mirror');

        $this->assertSame([], $hydrate['hydrated']);
        $this->assertSame($extended, $this->localContents('BBCA'));
    }
}
