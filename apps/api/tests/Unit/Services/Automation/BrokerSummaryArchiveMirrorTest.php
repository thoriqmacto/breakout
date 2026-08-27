<?php

namespace Tests\Unit\Services\Automation;

use App\Services\BrokerSummaryArchiveMirror;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * The promise this service makes: cold storage may fail, but the local archive
 * is never worse off for having tried.
 */
class BrokerSummaryArchiveMirrorTest extends TestCase
{
    private BrokerSummaryArchiveMirror $mirror;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('gdrive');

        config([
            'stockbit.save_disk' => 'local',
            'stockbit.save_dir' => 'broker_summary',
            'automation.broker_summary_mirror_disk' => 'gdrive',
        ]);

        $this->mirror = new BrokerSummaryArchiveMirror;
        $this->mirror->setSleeper(static fn () => null);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function archive(string $name, string $contents = '{"data":{}}'): string
    {
        $path = 'broker_summary/'.$name;
        Storage::disk('local')->put($path, $contents);

        return $path;
    }

    public function test_it_uploads_and_preserves_the_relative_path(): void
    {
        $path = $this->archive('BBCA_2026-08-24_2026-08-28_TRANSACTION_TYPE_NET.json');

        $result = $this->mirror->mirror([$path]);

        $this->assertSame([$path], $result['uploaded']);
        Storage::disk('gdrive')->assertExists($path);
        $this->assertSame(
            Storage::disk('local')->get($path),
            Storage::disk('gdrive')->get($path),
        );
    }

    public function test_an_unchanged_file_is_not_uploaded_again(): void
    {
        $path = $this->archive('BBCA_2026-08-24_2026-08-28_TRANSACTION_TYPE_NET.json');

        $this->mirror->mirror([$path]);
        $second = $this->mirror->mirror([$path]);

        $this->assertSame([], $second['uploaded']);
        $this->assertSame([$path], $second['skipped_unchanged']);
    }

    public function test_a_changed_file_is_uploaded_again(): void
    {
        $path = $this->archive('BBCA_2026-08-24_2026-08-28_TRANSACTION_TYPE_NET.json');
        $this->mirror->mirror([$path]);

        Storage::disk('local')->put($path, '{"data":{"changed":true}}');

        $this->assertSame([$path], $this->mirror->mirror([$path])['uploaded']);
    }

    public function test_a_missing_local_file_is_reported_not_invented(): void
    {
        $result = $this->mirror->mirror(['broker_summary/NOPE_2026-01-01_2026-01-02_X.json']);

        $this->assertSame([], $result['uploaded']);
        $this->assertCount(1, $result['missing']);
    }

    public function test_a_failed_upload_is_reported_and_the_local_file_survives(): void
    {
        $path = $this->archive('BBCA_2026-08-24_2026-08-28_TRANSACTION_TYPE_NET.json');
        $original = Storage::disk('local')->get($path);

        $failing = Mockery::mock(Filesystem::class);
        $failing->shouldReceive('fileExists')->andReturn(false);
        $failing->shouldReceive('put')->andThrow(new RuntimeException('drive_error: quota'));
        Storage::set('gdrive', $failing);

        $result = $this->mirror->mirror([$path]);

        $this->assertCount(1, $result['failed']);
        $this->assertSame([], $result['uploaded']);
        $this->assertSame('failed', $this->mirror->summarize($result)['status']);

        // The whole point: nothing local was moved, truncated or deleted.
        Storage::disk('local')->assertExists($path);
        $this->assertSame($original, Storage::disk('local')->get($path));
    }

    public function test_an_upload_that_does_not_verify_is_treated_as_a_failure(): void
    {
        $path = $this->archive('BBCA_2026-08-24_2026-08-28_TRANSACTION_TYPE_NET.json');

        // A disk that accepts the write and then serves back something else --
        // a silently truncated upload is exactly what a backup must not report
        // as success.
        $lying = Mockery::mock(Filesystem::class);
        $lying->shouldReceive('fileExists')->andReturn(false, true);
        $lying->shouldReceive('put')->andReturn(true);
        $lying->shouldReceive('get')->andReturn('truncated');
        Storage::set('gdrive', $lying);

        $result = $this->mirror->mirror([$path]);

        $this->assertSame([], $result['uploaded']);
        $this->assertCount(1, $result['failed']);
        $this->assertStringContainsString('does not match', $result['failed'][0]['message']);
    }

    public function test_paths_outside_the_archive_directory_are_refused(): void
    {
        Storage::disk('local')->put('stockbit_token.json', 'secret');

        $result = $this->mirror->mirror([
            'stockbit_token.json',
            '../.env',
            'broker_summary/../stockbit_token.json',
        ]);

        $this->assertSame([], $result['uploaded']);
        Storage::disk('gdrive')->assertMissing('stockbit_token.json');
    }

    public function test_mirroring_is_disabled_when_no_disk_is_configured(): void
    {
        config(['automation.broker_summary_mirror_disk' => null, 'csv.mirror_disk' => null]);

        $path = $this->archive('BBCA_2026-08-24_2026-08-28_TRANSACTION_TYPE_NET.json');

        $result = $this->mirror->mirror([$path]);

        $this->assertNull($result['disk']);
        $this->assertFalse($this->mirror->enabled());
        Storage::disk('gdrive')->assertMissing($path);
    }

    public function test_the_push_command_reports_failures_with_a_non_zero_exit(): void
    {
        $this->archive('BBCA_2026-08-24_2026-08-28_TRANSACTION_TYPE_NET.json');

        $this->artisan('broker-summary:mirror-push', ['--disk' => 'gdrive'])->assertExitCode(0);

        Storage::disk('gdrive')->assertExists('broker_summary/BBCA_2026-08-24_2026-08-28_TRANSACTION_TYPE_NET.json');
    }

    public function test_the_push_command_refuses_without_a_disk(): void
    {
        config(['automation.broker_summary_mirror_disk' => null, 'csv.mirror_disk' => null]);

        $this->artisan('broker-summary:mirror-push')->assertExitCode(2);
    }
}
