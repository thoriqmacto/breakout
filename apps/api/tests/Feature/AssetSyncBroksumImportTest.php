<?php

namespace Tests\Feature;

use App\Services\BrokerSummaryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AssetSyncBroksumImportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_asset_sync_eod_imports_broker_summaries(): void
    {
        config(['csv.index_symbols' => []]);
        config(['python.bin' => '/bin/echo']);

        $importer = Mockery::mock(BrokerSummaryImporter::class);
        $importer->shouldReceive('importFromDisk')
            ->once()
            ->andReturn(['file_count' => 0, 'row_count' => 0, 'symbols' => []]);

        $this->app->instance(BrokerSummaryImporter::class, $importer);

        $this->artisan('asset:sync', ['--eod' => true])
            ->assertExitCode(0);
    }
}
