<?php

namespace Tests\Feature;

use App\Services\BackupStatus;
use App\Services\BarCsvMirror;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers POST /api/v1/backup-status/mirror-push.
 */
class BackupMirrorPushTest extends TestCase
{
    private string $seedDir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('gdrive');

        $this->seedDir = sys_get_temp_dir().'/breakout-mirror-push-'.bin2hex(random_bytes(4));
        mkdir($this->seedDir, 0755, true);

        config([
            'csv.seed_dir' => $this->seedDir,
            'csv.mirror_path' => 'seeds/historical',
            'csv.mirror_disk' => 'gdrive',
            'stockbit.save_dir' => 'broker_summary',
        ]);

        // The manifest is a real file under storage/app; a stale one from
        // another test would change what flush() decides to skip.
        @unlink(storage_path('app/bar-csv-mirror.json'));
        Cache::flush();
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

    private function localCsv(string $symbol, string $contents): void
    {
        file_put_contents($this->seedDir.'/'.$symbol.'.csv', $contents);
    }

    private function push(array $payload = [])
    {
        $response = $this->postJson('/api/v1/backup-status/mirror-push', $payload);

        if ($response->status() === 401) {
            $response = $this->withoutMiddleware()->postJson('/api/v1/backup-status/mirror-push', $payload);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function historicalFrom(array $report, string $name): array
    {
        foreach ($report['collections'] as $collection) {
            if ($collection['key'] !== 'historical') {
                continue;
            }

            foreach ($collection['files'] as $file) {
                if ($file['name'] === $name) {
                    return $file;
                }
            }
        }

        $this->fail("The refreshed report does not mention {$name}.");
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/v1/backup-status/mirror-push')->assertUnauthorized();
    }

    public function test_a_changed_local_file_is_uploaded_and_then_reports_synced(): void
    {
        $updated = "Date,Close\n2026-08-25,9000\n2026-08-26,9100\n";
        $this->localCsv('BBCA', $updated);
        Storage::disk('gdrive')->put('seeds/historical/BBCA.csv', "Date,Close\n2026-08-25,9000\n");

        $response = $this->push(['collection' => 'historical', 'symbols' => ['BBCA']]);

        $response->assertOk()->assertJsonPath('data.uploaded', ['BBCA']);

        $this->assertSame($updated, Storage::disk('gdrive')->get('seeds/historical/BBCA.csv'));

        // The response carries a freshly computed report, so the UI can settle
        // without a second round trip.
        $file = $this->historicalFrom($response->json('data.report'), 'BBCA.csv');
        $this->assertSame(BackupStatus::SYNCED, $file['state']);
        $this->assertFalse($file['can_push']);
    }

    /**
     * The stale-manifest trap. flush() normally skips a symbol whose local hash
     * matches what was last sent -- but the manifest records the send, not what
     * Drive holds now, so a file deleted on Drive would be skipped and the user
     * told the push succeeded.
     */
    public function test_it_uploads_a_file_the_manifest_believes_was_already_mirrored(): void
    {
        $contents = "Date,Close\n2026-08-26,9100\n";
        $this->localCsv('BBRI', $contents);

        // Mirror it normally, so the manifest remembers this exact content.
        app(BarCsvMirror::class)->flush(['BBRI'], 'gdrive');
        $this->assertTrue(Storage::disk('gdrive')->exists('seeds/historical/BBRI.csv'));

        // Now the remote copy disappears behind the application's back.
        Storage::disk('gdrive')->delete('seeds/historical/BBRI.csv');

        // An ordinary flush is fooled by the manifest.
        $unforced = app(BarCsvMirror::class)->flush(['BBRI'], 'gdrive');
        $this->assertSame(['BBRI'], $unforced['skipped']);
        $this->assertFalse(Storage::disk('gdrive')->exists('seeds/historical/BBRI.csv'));

        $response = $this->push(['symbols' => ['BBRI']]);

        $response->assertOk()->assertJsonPath('data.uploaded', ['BBRI']);
        $this->assertSame($contents, Storage::disk('gdrive')->get('seeds/historical/BBRI.csv'));
    }

    public function test_it_does_not_push_a_file_whose_drive_copy_is_newer(): void
    {
        $this->localCsv('ANTM', "Date,Close\n2026-08-01,1000\n");
        Storage::disk('gdrive')->put('seeds/historical/ANTM.csv', "Date,Close\n2026-08-26,1500\n");
        touch($this->seedDir.'/ANTM.csv', time() - 6000);

        $response = $this->push(['symbols' => ['ANTM']]);

        $response->assertOk()
            ->assertJsonPath('data.uploaded', [])
            ->assertJsonPath('data.rejected', ['ANTM']);

        $this->assertSame(
            "Date,Close\n2026-08-26,1500\n",
            Storage::disk('gdrive')->get('seeds/historical/ANTM.csv'),
            'A newer Drive backup was overwritten with older local data.',
        );
    }

    public function test_pushing_with_no_selection_pushes_everything_eligible(): void
    {
        $this->localCsv('AAAA', 'a,1');
        $this->localCsv('BBBB', 'b,2');
        Storage::disk('gdrive')->put('seeds/historical/BBBB.csv', 'b,2');

        $response = $this->push();

        // AAAA is local_only; BBBB already matches byte for byte.
        $response->assertOk()->assertJsonPath('data.uploaded', ['AAAA']);
        $this->assertSame('a,1', Storage::disk('gdrive')->get('seeds/historical/AAAA.csv'));
    }

    public function test_an_unknown_symbol_is_rejected_rather_than_pushed(): void
    {
        $this->localCsv('BBCA', 'x,1');

        $response = $this->push(['symbols' => ['NOPE']]);

        $response->assertOk()
            ->assertJsonPath('data.uploaded', [])
            ->assertJsonPath('data.rejected', ['NOPE']);

        $this->assertFalse(Storage::disk('gdrive')->exists('seeds/historical/NOPE.csv'));
    }

    /**
     * The endpoint takes symbols, never paths, and the pattern rejects anything
     * that could climb out of the seed directory.
     */
    public function test_a_traversal_attempt_is_rejected_by_validation(): void
    {
        $this->push(['symbols' => ['../../etc/passwd']])->assertStatus(422);
        $this->push(['symbols' => ['/etc/passwd']])->assertStatus(422);
    }

    public function test_an_unknown_collection_is_rejected(): void
    {
        $this->push(['collection' => 'everything'])->assertStatus(422);
    }

    /**
     * With no archive mirror configured, a push says so rather than reporting
     * a success that uploaded nothing.
     */
    public function test_a_broker_summary_push_without_a_configured_mirror_is_refused(): void
    {
        config(['automation.broker_summary_mirror_disk' => null]);

        Storage::disk('local')->put('broker_summary/BBCA_2026-08-28_2026-08-28_ALL.json', '{"a":1}');

        $this->push(['collection' => 'broker_summary'])->assertStatus(503);

        $this->assertFalse(
            Storage::disk('gdrive')->exists('broker_summary/BBCA_2026-08-28_2026-08-28_ALL.json'),
        );
    }

    /**
     * The browser names nothing. Paths come from the local archive listing on
     * the server, so a symbol list in the request cannot narrow -- or widen --
     * what is uploaded.
     */
    public function test_a_broker_summary_push_uploads_the_server_side_listing(): void
    {
        config(['automation.broker_summary_mirror_disk' => 'gdrive']);

        Storage::disk('local')->put('broker_summary/BBCA_2026-08-28_2026-08-28_ALL.json', '{"a":1}');
        Storage::disk('local')->put('broker_summary/TLKM_2026-08-28_2026-08-28_ALL.json', '{"b":2}');

        $response = $this->push([
            'collection' => 'broker_summary',
            'symbols' => ['BBCA'],
        ])->assertOk();

        $uploaded = $response->json('data.uploaded');

        $this->assertCount(2, $uploaded, 'The push honoured a browser-supplied symbol list.');

        foreach (['BBCA', 'TLKM'] as $symbol) {
            $path = "broker_summary/{$symbol}_2026-08-28_2026-08-28_ALL.json";

            $this->assertTrue(Storage::disk('gdrive')->exists($path));
            $this->assertSame(
                Storage::disk('local')->get($path),
                Storage::disk('gdrive')->get($path),
            );
        }
    }

    /** A second push re-uploads nothing: the remote bytes already match. */
    public function test_a_repeated_broker_summary_push_skips_unchanged_files(): void
    {
        config(['automation.broker_summary_mirror_disk' => 'gdrive']);

        Storage::disk('local')->put('broker_summary/BBCA_2026-08-28_2026-08-28_ALL.json', '{"a":1}');

        $this->push(['collection' => 'broker_summary'])->assertOk();

        $this->push(['collection' => 'broker_summary'])
            ->assertOk()
            ->assertJsonCount(0, 'data.uploaded')
            ->assertJsonCount(1, 'data.skipped');
    }

    public function test_a_concurrent_push_is_refused(): void
    {
        $this->localCsv('BBCA', 'x,1');

        $held = Cache::lock('backup-status:mirror-push', 300);
        $this->assertTrue($held->get());

        try {
            $this->push(['symbols' => ['BBCA']])
                ->assertStatus(409)
                ->assertJsonPath('status', 'error');
        } finally {
            $held->release();
        }
    }

    /**
     * Pushing into a Drive that cannot be read would report success without any
     * way to confirm it, so it is refused up front.
     */
    public function test_a_push_is_refused_when_drive_is_unhealthy(): void
    {
        $this->localCsv('BBCA', 'x,1');

        Storage::forgetDisk('gdrive');
        config([
            'filesystems.disks.gdrive.clientId' => '',
            'filesystems.disks.gdrive.clientSecret' => '',
            'filesystems.disks.gdrive.refreshToken' => '',
        ]);

        $this->push(['symbols' => ['BBCA']])
            ->assertStatus(503)
            ->assertJsonPath('status', 'error');
    }

    public function test_the_push_response_never_exposes_credentials(): void
    {
        config([
            'filesystems.disks.gdrive.clientId' => 'client-id-value',
            'filesystems.disks.gdrive.clientSecret' => 'client-secret-value',
            'filesystems.disks.gdrive.refreshToken' => 'refresh-token-value',
        ]);
        $this->localCsv('BBCA', 'x,1');

        $body = $this->push(['symbols' => ['BBCA']])->getContent();

        $this->assertStringNotContainsString('client-secret-value', $body);
        $this->assertStringNotContainsString('refresh-token-value', $body);
    }
}
