<?php

namespace Tests\Feature;

use App\Services\BackupStatus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers the deep report, GET /api/v1/backup-status/audit.
 *
 * The file-by-file comparison used to be what GET /api/v1/backup-status
 * returned. It now lives behind an explicit action, because its cost grows
 * with every archived broker-summary JSON and the ordinary page load should
 * not pay it. The behaviour it asserts is unchanged, so these tests moved
 * endpoint rather than moving goalposts.
 *
 * The rule every test here defends: a filename existing in both places is not
 * synchronisation. The first version of this suite asserted synced = 1 for a
 * local BBCA.csv holding "Date,Close\n2026-01-01,100" against a Drive copy
 * holding the literal string "remote", which encoded the bug it was supposed to
 * catch.
 */
class BackupStatusApiTest extends TestCase
{
    private string $seedDir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('gdrive');

        $this->seedDir = sys_get_temp_dir().'/breakout-backup-status-'.bin2hex(random_bytes(4));
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

    private function localCsv(string $name, string $contents, ?int $modifiedAt = null): void
    {
        file_put_contents($this->seedDir.'/'.$name, $contents);

        if ($modifiedAt !== null) {
            touch($this->seedDir.'/'.$name, $modifiedAt);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function historical(): array
    {
        $response = $this->getJson('/api/v1/backup-status/audit');

        if ($response->status() === 401) {
            $response = $this->withoutMiddleware()->getJson('/api/v1/backup-status/audit');
        }

        $response->assertOk();

        foreach ($response->json('data.collections') as $collection) {
            if ($collection['key'] === 'historical') {
                return $collection;
            }
        }

        $this->fail('The historical collection is missing from the report.');
    }

    /**
     * @return array<string, mixed>
     */
    private function fileNamed(array $collection, string $name): array
    {
        foreach ($collection['files'] as $file) {
            if ($file['name'] === $name) {
                return $file;
            }
        }

        $this->fail("The report does not mention {$name}.");
    }

    public function test_identical_contents_are_synced(): void
    {
        $contents = "Date,Close\n2026-08-25,9000\n";
        $this->localCsv('BBCA.csv', $contents);
        Storage::disk('gdrive')->put('seeds/historical/BBCA.csv', $contents);

        $collection = $this->historical();

        $this->assertSame(BackupStatus::SYNCED, $this->fileNamed($collection, 'BBCA.csv')['state']);
        $this->assertSame(1, $collection['counts']['synced']);
        $this->assertSame(0, $collection['counts']['pending_push']);
        $this->assertFalse($this->fileNamed($collection, 'BBCA.csv')['can_push']);
    }

    /**
     * The exact bug that was reported: stockbit:scrape extends the local CSV,
     * Drive still holds the shorter one, and the page said "Both" in green.
     */
    public function test_same_name_with_different_contents_is_not_synced(): void
    {
        $this->localCsv(
            'BBCA.csv',
            "Date,Close\n2026-08-25,9000\n2026-08-26,9100\n",
            modifiedAt: time(),
        );
        Storage::disk('gdrive')->put('seeds/historical/BBCA.csv', "Date,Close\n2026-08-25,9000\n");
        touch($this->seedDir.'/BBCA.csv', time());

        $collection = $this->historical();
        $file = $this->fileNamed($collection, 'BBCA.csv');

        $this->assertNotSame(BackupStatus::SYNCED, $file['state'], 'Different contents were reported as synced.');
        $this->assertSame(0, $collection['counts']['synced']);
        $this->assertSame(1, $collection['counts']['pending_push']);
        $this->assertTrue($file['can_push']);
    }

    /**
     * Equal length proves nothing, so the size short-circuit must not be
     * allowed to stand in for the hash.
     */
    public function test_same_size_but_different_bytes_is_never_synced(): void
    {
        $this->localCsv('BBRI.csv', 'abc123');
        Storage::disk('gdrive')->put('seeds/historical/BBRI.csv', 'xyz789');

        $file = $this->fileNamed($this->historical(), 'BBRI.csv');

        $this->assertSame(6, $file['local']['size']);
        $this->assertSame(6, $file['gdrive']['size']);
        $this->assertNotSame(BackupStatus::SYNCED, $file['state']);
        $this->assertContains($file['state'], [
            BackupStatus::LOCAL_NEWER,
            BackupStatus::GDRIVE_NEWER,
            BackupStatus::DIFFERENT,
        ]);
    }

    public function test_a_newer_local_copy_is_local_newer_and_pushable(): void
    {
        $this->localCsv('TLKM.csv', "Date,Close\n2026-08-26,4000\n");
        Storage::disk('gdrive')->put('seeds/historical/TLKM.csv', "Date,Close\n2026-08-01,3000\n");
        touch($this->seedDir.'/TLKM.csv', time() + 600);

        $file = $this->fileNamed($this->historical(), 'TLKM.csv');

        $this->assertSame(BackupStatus::LOCAL_NEWER, $file['state']);
        $this->assertTrue($file['can_push']);
    }

    /**
     * A newer Drive copy might be the good one, so it is never offered for an
     * automatic overwrite.
     */
    public function test_a_newer_drive_copy_is_not_pushable(): void
    {
        $this->localCsv('ANTM.csv', "Date,Close\n2026-08-01,1000\n");
        Storage::disk('gdrive')->put('seeds/historical/ANTM.csv', "Date,Close\n2026-08-26,1500\n");
        touch($this->seedDir.'/ANTM.csv', time() - 6000);

        $file = $this->fileNamed($this->historical(), 'ANTM.csv');

        $this->assertSame(BackupStatus::GDRIVE_NEWER, $file['state']);
        $this->assertFalse($file['can_push']);
    }

    public function test_local_only_is_pushable(): void
    {
        $this->localCsv('ASII.csv', "Date,Close\n2026-08-26,5000\n");

        $collection = $this->historical();
        $file = $this->fileNamed($collection, 'ASII.csv');

        $this->assertSame(BackupStatus::LOCAL_ONLY, $file['state']);
        $this->assertTrue($file['can_push']);
        $this->assertNull($file['gdrive']);
        $this->assertSame(1, $collection['counts']['local_only']);
    }

    public function test_drive_only_is_not_pushable(): void
    {
        Storage::disk('gdrive')->put('seeds/historical/GOTO.csv', "Date,Close\n2026-08-26,60\n");

        $file = $this->fileNamed($this->historical(), 'GOTO.csv');

        $this->assertSame(BackupStatus::GDRIVE_ONLY, $file['state']);
        $this->assertFalse($file['can_push']);
        $this->assertNull($file['local']);
    }

    /**
     * "We could not look" is not "your backups are missing". Reporting every
     * local file as local_only when Drive was unreachable both alarms the
     * operator and offers a push that cannot be verified.
     */
    public function test_an_unreachable_drive_is_not_reported_as_missing_files(): void
    {
        $this->localCsv('BMRI.csv', "Date,Close\n2026-08-26,7000\n");

        // No fake for gdrive: the real driver runs, and with no credentials it
        // refuses to build a disk at all.
        Storage::forgetDisk('gdrive');
        config([
            'filesystems.disks.gdrive.clientId' => '',
            'filesystems.disks.gdrive.clientSecret' => '',
            'filesystems.disks.gdrive.refreshToken' => '',
        ]);

        $response = $this->getJson('/api/v1/backup-status/audit');

        if ($response->status() === 401) {
            $response = $this->withoutMiddleware()->getJson('/api/v1/backup-status/audit');
        }

        $response->assertOk();

        $drive = $response->json('data.google_drive');
        $this->assertFalse($drive['can_read']);
        $this->assertFalse($drive['configured']);
        $this->assertSame('not_configured', $drive['status']);

        $collection = $this->historical();
        $file = $this->fileNamed($collection, 'BMRI.csv');

        $this->assertSame(BackupStatus::COMPARE_ERROR, $file['state']);
        $this->assertNotSame(BackupStatus::LOCAL_ONLY, $file['state']);
        $this->assertFalse($file['can_push'], 'A push was offered while Drive could not be read.');
        $this->assertSame(0, $collection['counts']['synced']);
    }

    public function test_the_report_never_exposes_credentials(): void
    {
        config([
            'filesystems.disks.gdrive.clientId' => 'client-id-value',
            'filesystems.disks.gdrive.clientSecret' => 'client-secret-value',
            'filesystems.disks.gdrive.refreshToken' => 'refresh-token-value',
        ]);

        $response = $this->getJson('/api/v1/backup-status/audit');

        if ($response->status() === 401) {
            $response = $this->withoutMiddleware()->getJson('/api/v1/backup-status/audit');
        }

        $body = $response->assertOk()->getContent();

        $this->assertStringNotContainsString('client-secret-value', $body);
        $this->assertStringNotContainsString('refresh-token-value', $body);
        $this->assertStringNotContainsString('client-id-value', $body);
    }

    public function test_broker_summary_is_reported_but_not_pushable(): void
    {
        Storage::disk('local')->put('broker_summary/BBRI.json', '{}');
        Storage::disk('gdrive')->put('broker_summary/TLKM.csv', 'date,broker');

        $response = $this->getJson('/api/v1/backup-status/audit');

        if ($response->status() === 401) {
            $response = $this->withoutMiddleware()->getJson('/api/v1/backup-status/audit');
        }

        $response->assertOk()
            ->assertJsonPath('data.collections.1.key', 'broker_summary')
            ->assertJsonPath('data.collections.1.pushable', false)
            ->assertJsonPath('data.collections.1.counts.local', 1)
            ->assertJsonPath('data.collections.1.counts.gdrive', 1)
            ->assertJsonPath('data.collections.1.files.0.state', BackupStatus::LOCAL_ONLY)
            ->assertJsonPath('data.collections.1.files.0.can_push', false)
            ->assertJsonPath('data.collections.1.files.1.state', BackupStatus::GDRIVE_ONLY);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/backup-status')->assertUnauthorized();
        $this->getJson('/api/v1/backup-status/audit')->assertUnauthorized();
    }
}
