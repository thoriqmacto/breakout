<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Broksum;
use App\Services\BrokerSummaryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrokerSummaryImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_broker_summary_json_files(): void
    {
        Storage::fake('local');

        $payload = [
            'data' => [
                ['date' => '2024-04-01', 'broker' => 'AB', 'buy_value' => 1000, 'sell_value' => 400],
                ['date' => '2024-04-02', 'broker' => 'CD', 'buy_value' => 500, 'sell_value' => 800],
            ],
        ];

        Storage::disk('local')->put(
            'broker_summary/BBCA_2024-04-01_2024-04-02.json',
            json_encode($payload)
        );

        $summary = app(BrokerSummaryImporter::class)->importFromDisk('local', 'broker_summary');

        $this->assertSame(1, $summary['file_count']);
        $this->assertSame(2, $summary['row_count']);

        $assetId = Asset::where('symbol', 'BBCA')->value('id');
        $this->assertNotNull($assetId);

        $this->assertDatabaseHas('broksums', [
            'asset_id' => $assetId,
            'date' => '2024-04-01',
            'broker' => 'AB',
            'net_value' => 600.00,
            'buy_value' => 1000.00,
            'sell_value' => 400.00,
        ]);

        $this->assertDatabaseHas('broksums', [
            'asset_id' => $assetId,
            'date' => '2024-04-02',
            'broker' => 'CD',
        ]);
    }
}
