<?php

namespace Tests\Feature\Reconciliation;

use App\Services\Reconciliation\ReconciliationStore;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * A refused write has to say why, where, and as whom.
 *
 * The disk runs with `throw => false`, so a refused write comes back as a
 * plain `false` and Flysystem's reason is discarded. What reached production
 * was "Could not write the reconciliation temporary file
 * reconciliation/manifest.json.7157bad7.tmp" -- a path relative to a root the
 * reader cannot see, from a user the message does not name, for a cause it
 * does not state. It arrived wrapped in a *second* failure, because writing
 * the log needed the same unwritable directory, so the only message printed
 * was about logging.
 *
 * The mocked disk is deliberate. The real refusal is a permission denial, and
 * these tests run as root, which no mode refuses; pointing the disk at an
 * unusable path instead fails earlier, as Flysystem's own
 * UnableToCreateDirectory, which `throw => false` does not convert. Only a
 * put() returning false reaches the code under test.
 */
class ReconciliationWriteFailureTest extends TestCase
{
    private function diskReturningFalse(string $root): Filesystem
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andReturn(false);
        $disk->shouldReceive('exists')->andReturn(false);
        // The status command counts the asset documents too, and a disk that
        // cannot be written to has none to list.
        $disk->shouldReceive('files')->andReturn([]);
        $disk->shouldReceive('path')->andReturnUsing(
            static fn (string $path): string => rtrim($root, '/').'/'.ltrim($path, '/'),
        );

        return $disk;
    }

    public function test_a_refused_write_names_the_absolute_path_the_reason_and_the_user(): void
    {
        $root = '/var/lib/breakout-nowhere/storage/app/private';

        Storage::shouldReceive('disk')->andReturn($this->diskReturningFalse($root));

        try {
            app(ReconciliationStore::class)->writeManifest(['schema_version' => 1]);
            $this->fail('A refused write should have thrown.');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();

            // The absolute path, so the reader can go and look at it.
            $this->assertStringContainsString($root.'/reconciliation/manifest.json', $message);
            // The cause, rather than only the fact of failure.
            $this->assertStringContainsString('does not exist', $message);
            // And who was refused -- the whole point when two users share a
            // directory and only one of them may write to it.
            $this->assertMatchesRegularExpression('/user running this process \(\S+\)/', $message);
            $this->assertStringContainsString('ownership and mode', $message);
        }
    }

    /**
     * The status command must not call a directory it cannot write to "empty".
     *
     * A manifest that is missing and a manifest that cannot be created look
     * identical from the dashboard, and they need opposite responses: run the
     * rebuild, versus the rebuild will fail until this is writable.
     */
    public function test_the_status_command_distinguishes_a_directory_it_cannot_create(): void
    {
        Storage::shouldReceive('disk')
            ->andReturn($this->diskReturningFalse('/var/lib/breakout-nowhere/storage/app/private'));

        // Artisan::call rather than expectsOutputToContain: the assertion is
        // about one long sentence, and asserting on the captured buffer keeps
        // the failure message readable when it does not match.
        $exitCode = Artisan::call('data:reconcile-status');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('does not exist', $output);
        $this->assertStringContainsString('not writable', $output);
    }

    public function test_a_usable_directory_is_reported_as_writable(): void
    {
        Storage::fake('local');
        config(['reconciliation.local_disk' => 'local']);

        // The rebuild creates this; what matters is that an existing, usable
        // directory is not reported as a problem.
        Storage::disk('local')->makeDirectory('reconciliation');

        $this->artisan('data:reconcile-status')
            ->expectsOutputToContain('writable')
            ->run();
    }
}
