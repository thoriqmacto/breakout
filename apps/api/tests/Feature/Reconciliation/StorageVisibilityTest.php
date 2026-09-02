<?php

namespace Tests\Feature\Reconciliation;

use App\Services\Reconciliation\ReconciliationStore;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The CLI writes it; the web process has to be able to read it.
 *
 * artisan runs as the deploy user and PHP-FPM as www-data. Flysystem creates
 * a "private" directory as 0700, so every directory the scheduler created was
 * one the API could not traverse -- and the failure is silent, because
 * Storage::exists() just returns false. A complete reconciliation layer
 * reported itself as "not built yet" on that basis while cold storage held a
 * published copy of it.
 *
 * The assertion is deliberately on the group bits rather than on an exact
 * mode: mkdir() applies the umask, so the host decides between 0775 and 0755.
 * Both are traversable by the web group. 0700 is not, under any umask.
 */
class StorageVisibilityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/breakout-visibility-'.bin2hex(random_bytes(4));

        // The real local driver, with the real permissions map, on a scratch
        // root. Storage::fake() would not exercise the configuration at all.
        config([
            'filesystems.disks.visibility_probe' => array_merge(
                config('filesystems.disks.local'),
                ['root' => $this->root],
            ),
            'reconciliation.local_disk' => 'visibility_probe',
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            exec('rm -rf '.escapeshellarg($this->root));
        }

        parent::tearDown();
    }

    private function modeOf(string $path): int
    {
        clearstatcache(true, $path);

        return fileperms($path) & 0777;
    }

    public function test_a_nested_directory_is_readable_by_the_web_group(): void
    {
        Storage::disk('visibility_probe')->put('reconciliation/assets/AAA.json', '{}');

        foreach (['reconciliation', 'reconciliation/assets'] as $directory) {
            $mode = $this->modeOf($this->root.'/'.$directory);

            // Group read and traverse: without both, a different user in the
            // group cannot open anything underneath.
            $this->assertSame(
                0o050,
                $mode & 0o050,
                sprintf('%s is %04o, which the web process cannot traverse.', $directory, $mode),
            );
        }
    }

    /** The document itself has to be group-readable too. */
    public function test_a_written_document_is_readable_by_the_web_group(): void
    {
        $store = app(ReconciliationStore::class);
        $store->writeManifest(['schema_version' => 1]);

        $mode = $this->modeOf($this->root.'/'.$store->manifestPath());

        $this->assertSame(
            0o040,
            $mode & 0o040,
            sprintf('The manifest is %04o, which the web process cannot read.', $mode),
        );
    }
}
