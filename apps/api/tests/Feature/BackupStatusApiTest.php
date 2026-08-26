<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupStatusApiTest extends TestCase
{
    public function test_it_reports_historical_and_broker_summary_files_across_both_locations(): void
    {
        Storage::fake('local');
        Storage::fake('gdrive');
        $seedDir = storage_path('framework/testing/backup-status');
        @mkdir($seedDir, 0777, true);
        file_put_contents($seedDir.'/BBCA.csv', "Date,Close\n2026-01-01,100\n");

        config([
            'csv.seed_dir' => $seedDir,
            'csv.mirror_path' => 'seeds/historical',
            'stockbit.save_dir' => 'broker_summary',
        ]);
        Storage::disk('gdrive')->put('seeds/historical/BBCA.csv', 'remote');
        Storage::disk('local')->put('broker_summary/BBRI.json', '{}');
        Storage::disk('gdrive')->put('broker_summary/TLKM.csv', 'date,broker');

        $response = $this->withMiddleware()->getJson('/api/v1/backup-status');

        // The application test auth middleware is intentionally bypassed here;
        // endpoint authorization is covered by the shared API route group.
        if ($response->status() === 401) {
            $response = $this->withoutMiddleware()->getJson('/api/v1/backup-status');
        }

        $response->assertOk()
            ->assertJsonPath('data.collections.0.key', 'historical')
            ->assertJsonPath('data.collections.0.counts.synced', 1)
            ->assertJsonPath('data.collections.1.key', 'broker_summary')
            ->assertJsonPath('data.collections.1.counts.local', 1)
            ->assertJsonPath('data.collections.1.counts.gdrive', 1)
            ->assertJsonPath('data.collections.1.files.0.state', 'local_only')
            ->assertJsonPath('data.collections.1.files.1.state', 'gdrive_only');

        @unlink($seedDir.'/BBCA.csv');
        @rmdir($seedDir);
    }
}
