<?php

namespace Tests\Feature;

use App\Services\ContentHasher;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoogleDriveDiagnoseCommandTest extends TestCase
{
    /**
     * A run that never reached Drive must not print a reassuring timing
     * verdict. The first version reported "comfortably inside a 60s gateway
     * timeout" for a page that could not load at all.
     */
    public function test_it_reports_no_timing_when_drive_cannot_be_reached(): void
    {
        Storage::forgetDisk('gdrive');
        config([
            'filesystems.disks.gdrive.clientId' => '',
            'filesystems.disks.gdrive.clientSecret' => '',
            'filesystems.disks.gdrive.refreshToken' => '',
        ]);

        $this->artisan('gdrive:diagnose')
            ->expectsOutputToContain('resolve disk')
            ->expectsOutputToContain('no meaningful timing')
            ->doesntExpectOutputToContain('Comfortably inside')
            ->assertExitCode(1);
    }

    /**
     * A disk that works but cannot supply bulk checksums is degraded, not
     * broken -- and the timing then matters more, because it measured the slow
     * path.
     */
    public function test_it_still_reports_timing_when_the_bulk_checksum_path_is_unavailable(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('seeds/historical/AAA.csv', "Date,Close\n2026-08-26,100\n");

        config(['csv.mirror_path' => 'seeds/historical']);

        $this->artisan('gdrive:diagnose', ['--disk' => 'local'])
            ->expectsOutputToContain('bulk checksums')
            ->expectsOutputToContain('A page load costs')
            ->assertExitCode(0);
    }

    /**
     * The bulk lookup is a Drive-only fast path. Every other disk gets an empty
     * array, and BackupStatus then hashes each file individually -- documented
     * here because a silent change to that contract would quietly restore the
     * per-file API calls that caused the 504.
     */
    public function test_bulk_checksums_are_empty_for_a_disk_that_is_not_drive(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('seeds/historical/AAA.csv', 'x');

        $this->assertSame(
            [],
            app(ContentHasher::class)->directoryChecksums(Storage::disk('local'), 'seeds/historical'),
        );
    }
}
