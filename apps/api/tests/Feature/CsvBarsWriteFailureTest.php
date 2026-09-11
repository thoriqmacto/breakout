<?php

namespace Tests\Feature;

use App\Services\CsvBars;
use RuntimeException;
use Tests\TestCase;

/**
 * A CSV that cannot be written must say who could not write it.
 *
 * The production failure was:
 *
 *     fopen(/var/www/breakout-data/historical/ACES.csv.tmp):
 *     Failed to open stream: Permission denied
 *
 * — which names the file and nothing else. The data directory had been
 * established by the deploy user over weeks of manual runs; moving the
 * scheduler to www-data made every write fail, and the message pointed at
 * neither user. It is the third instance of that shape in a week.
 *
 * Both failures here are provoked in ways root cannot bypass, because the
 * suite runs as root and no file mode refuses root: mkdir into a path whose
 * parent is a regular file fails with ENOTDIR for everyone, and fopen on a
 * path that is a directory fails with EISDIR for everyone.
 */
class CsvBarsWriteFailureTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/csv-bars-'.bin2hex(random_bytes(4));

        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);

        parent::tearDown();
    }

    public function test_a_directory_that_cannot_be_created_is_reported_rather_than_ignored(): void
    {
        // A regular file where a directory is needed: mkdir fails as anyone.
        $blocker = $this->root.'/historical';
        file_put_contents($blocker, 'not a directory');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not write to');

        CsvBars::write($blocker.'/nested/ACES.csv', $this->rows());
    }

    public function test_an_unwritable_target_names_the_directory_and_the_running_user(): void
    {
        // A directory sitting where the temporary file goes: fopen fails as
        // anyone, which is the same branch the production permission fault hit.
        mkdir($this->root.'/ACES.csv.tmp');

        try {
            CsvBars::write($this->root.'/ACES.csv', $this->rows());

            $this->fail('Writing over a directory should not have succeeded.');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();

            $this->assertStringContainsString($this->root, $message);
            // The fact PHP's own message omits, and the whole reason this
            // exception exists rather than a raw warning.
            $this->assertStringContainsString($this->currentUser(), $message);
        }
    }

    /** A successful write is unchanged: same header, same order, no scratch file left. */
    public function test_a_successful_write_still_produces_the_expected_file(): void
    {
        $path = $this->root.'/nested/ACES.csv';

        CsvBars::write($path, $this->rows());

        $this->assertFileExists($path);
        $this->assertFileDoesNotExist($path.'.tmp');

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path))));

        $this->assertSame('Date,Open,High,Low,Close,Volume', $lines[0]);
        // Sorted by date regardless of insertion order.
        $this->assertStringStartsWith('08/09/2026', $lines[1]);
        $this->assertStringStartsWith('09/09/2026', $lines[2]);
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        return [
            '2026-09-09' => ['open' => 2, 'high' => 3, 'low' => 1, 'close' => 2, 'volume' => 200],
            '2026-09-08' => ['open' => 1, 'high' => 2, 'low' => 1, 'close' => 1, 'volume' => 100],
        ];
    }

    private function currentUser(): string
    {
        $entry = posix_getpwuid(posix_geteuid());

        return is_array($entry) && isset($entry['name']) ? (string) $entry['name'] : 'unknown';
    }

    private function deleteTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $this->deleteTree($path.'/'.$entry);
        }

        @rmdir($path);
    }
}
