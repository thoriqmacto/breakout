<?php

namespace Tests\Feature;

use App\Services\BackupStatus;
use App\Services\ContentHasher;
use App\Services\GoogleDriveHealth;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A bulk checksum hit must remove the per-file remote work entirely.
 *
 * That is the whole point of the fast path: with it, comparing 52 files costs
 * one listing plus one metadata query per folder. Without it, every file is
 * downloaded to be hashed -- 89 seconds in production, past the gateway
 * timeout and a 504.
 */
class BackupStatusBulkChecksumTest extends TestCase
{
    private string $seedDir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('gdrive');

        $this->seedDir = sys_get_temp_dir().'/breakout-bulk-'.bin2hex(random_bytes(4));
        mkdir($this->seedDir, 0755, true);

        config([
            'csv.seed_dir' => $this->seedDir,
            'csv.mirror_path' => 'seeds/historical',
            'stockbit.save_dir' => 'broker_summary',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->seedDir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->seedDir);

        parent::tearDown();
    }

    /**
     * A hasher whose bulk lookup can be primed, counting the per-file calls
     * the fast path is supposed to make unnecessary.
     */
    private function countingHasher(array $bulk): ContentHasher
    {
        return new class($bulk) extends ContentHasher
        {
            public int $remoteCalls = 0;

            public function __construct(private array $bulk) {}

            public function directoryChecksums(Filesystem $disk, string $directory): array
            {
                return $this->bulk[$directory] ?? [];
            }

            public function remote(Filesystem $disk, string $path): ?string
            {
                $this->remoteCalls++;

                return parent::remote($disk, $path);
            }
        };
    }

    private function report(ContentHasher $hasher): array
    {
        return (new BackupStatus($hasher, app(GoogleDriveHealth::class)))->report();
    }

    private function historical(array $report): array
    {
        foreach ($report['collections'] as $collection) {
            if ($collection['key'] === 'historical') {
                return $collection;
            }
        }

        $this->fail('The historical collection is missing.');
    }

    public function test_a_bulk_hit_avoids_every_per_file_remote_read(): void
    {
        $contents = "Date,Close\n2026-08-26,9000\n";

        foreach (['BBCA', 'BBRI', 'TLKM'] as $symbol) {
            file_put_contents($this->seedDir."/{$symbol}.csv", $contents);
            Storage::disk('gdrive')->put("seeds/historical/{$symbol}.csv", $contents);
        }

        $hasher = $this->countingHasher([
            'seeds/historical' => [
                'BBCA.csv' => md5($contents),
                'BBRI.csv' => md5($contents),
                'TLKM.csv' => md5($contents),
            ],
        ]);

        $collection = $this->historical($this->report($hasher));

        $this->assertSame(3, $collection['counts']['synced']);
        $this->assertSame(
            0,
            $hasher->remoteCalls,
            'The bulk checksums were available, but files were still fetched one by one.',
        );
    }

    /**
     * The fast path is an optimisation, never a shortcut past correctness: a
     * checksum that differs still means not synced.
     */
    public function test_a_bulk_checksum_that_differs_is_not_synced(): void
    {
        file_put_contents($this->seedDir.'/BBCA.csv', "Date,Close\n2026-08-26,9000\n");
        Storage::disk('gdrive')->put('seeds/historical/BBCA.csv', "Date,Close\n2026-08-25,9000\n");

        $hasher = $this->countingHasher([
            'seeds/historical' => ['BBCA.csv' => md5('something else entirely')],
        ]);

        $collection = $this->historical($this->report($hasher));

        $this->assertSame(0, $collection['counts']['synced']);
        $this->assertSame(0, $hasher->remoteCalls);
    }

    /**
     * A file the bulk lookup did not cover -- a Google-native document, say --
     * still gets hashed individually rather than being assumed identical.
     */
    public function test_a_file_missing_from_the_bulk_map_falls_back_to_hashing(): void
    {
        $contents = "Date,Close\n2026-08-26,9000\n";
        file_put_contents($this->seedDir.'/BBCA.csv', $contents);
        Storage::disk('gdrive')->put('seeds/historical/BBCA.csv', $contents);

        $hasher = $this->countingHasher(['seeds/historical' => []]);

        $collection = $this->historical($this->report($hasher));

        $this->assertSame(1, $hasher->remoteCalls, 'The fallback did not run for an uncovered file.');
        $this->assertSame(1, $collection['counts']['synced']);
    }

    /**
     * Different sizes settle it without any hash, bulk or otherwise.
     */
    public function test_differing_sizes_short_circuit_before_any_hashing(): void
    {
        file_put_contents($this->seedDir.'/BBCA.csv', "Date,Close\n2026-08-26,9000\n2026-08-27,9100\n");
        Storage::disk('gdrive')->put('seeds/historical/BBCA.csv', "Date,Close\n2026-08-26,9000\n");

        $hasher = $this->countingHasher(['seeds/historical' => ['BBCA.csv' => md5('irrelevant')]]);

        $collection = $this->historical($this->report($hasher));

        $this->assertSame(0, $collection['counts']['synced']);
        $this->assertSame(0, $hasher->remoteCalls);
    }

    /**
     * Equal size is not equality. This is the case the size short-circuit must
     * not be allowed to swallow.
     */
    public function test_equal_sizes_still_require_the_hash(): void
    {
        file_put_contents($this->seedDir.'/BBCA.csv', 'abc123');
        Storage::disk('gdrive')->put('seeds/historical/BBCA.csv', 'xyz789');

        $hasher = $this->countingHasher(['seeds/historical' => ['BBCA.csv' => md5('xyz789')]]);

        $collection = $this->historical($this->report($hasher));

        $this->assertSame(0, $collection['counts']['synced'], 'Equal size was treated as equality.');
    }
}
