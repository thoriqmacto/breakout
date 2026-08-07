<?php

namespace Tests\Feature;

use App\Models\Asset;
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
                'from' => '2024-04-03',
                'broker_summary' => [
                    'transaction_type' => 'TRANSACTION_TYPE_NET',
                    'brokers_buy' => [
                        [
                            'netbs_broker_code' => 'AB',
                            'netbs_date' => '2024-04-01',
                            'netbs_broker_type' => 'Lokal',
                            'blot' => 10,
                            'blotv' => 1000,
                            'bval' => 1000,
                            'bvalv' => 1100,
                            'netbs_buy_avg_price' => 10.5,
                        ],
                    ],
                    'brokers_sell' => [
                        [
                            'netbs_broker_code' => 'AB',
                            'netbs_date' => '2024-04-01',
                            'slot' => 5,
                            'slotv' => 500,
                            'sval' => -400,
                            'svalv' => -450,
                            'netbs_sell_avg_price' => 9.5,
                        ],
                        [
                            'netbs_broker_code' => 'CD',
                            'netbs_date' => '2024-04-02',
                            'slot' => 3,
                            'slotv' => 300,
                            'sval' => -800,
                            'svalv' => -850,
                            'netbs_sell_avg_price' => 12.0,
                        ],
                    ],
                ],
                'bandar_detector' => [
                    'broker_accdist' => 'acc',
                    'number_broker_buysell' => 5,
                    'total_buyer' => 3,
                    'total_seller' => 2,
                    'value' => 10000,
                    'volume' => 100000,
                    'average' => 150.5,
                    'avg' => ['avg_value' => 1],
                    'top1' => ['broker' => 'AB'],
                ],
            ],
        ];

        Storage::disk('local')->put(
            'broker_summary/BBCA_2024-04-01_2024-04-02_TRANSACTION_TYPE_NET.json',
            json_encode($payload)
        );

        $summary = app(BrokerSummaryImporter::class)->importFromDisk('local', 'broker_summary');

        $this->assertSame(1, $summary['file_count']);
        $this->assertSame(2, $summary['row_count']);

        $assetId = Asset::where('symbol', 'BBCA')->value('id');
        $this->assertNotNull($assetId);

        $this->assertDatabaseHas('broker_summary_facts', [
            'asset_id' => $assetId,
            'trade_date' => '2024-04-03',
            'broker_code' => 'AB',
            'transaction_type' => 'TRANSACTION_TYPE_NET',
            'broker_type' => 'Lokal',
            'buy_lot' => 10,
            'sell_lot' => 5,
            'net_lot' => 5,
            'buy_value' => 1000.00,
            'sell_value' => 400.00,
            'net_value' => 600.00,
        ]);

        $this->assertDatabaseHas('broker_summary_facts', [
            'asset_id' => $assetId,
            'trade_date' => '2024-04-03',
            'broker_code' => 'CD',
            'transaction_type' => 'TRANSACTION_TYPE_NET',
            'buy_value' => 0.00,
            'sell_value' => 800.00,
            'net_value' => -800.00,
        ]);

        $this->assertDatabaseHas('broksums', [
            'asset_id' => $assetId,
            'date' => '2024-04-03',
            'broker' => 'AB',
            'net_value' => 600.00,
            'buy_value' => 1000.00,
            'buy_avg_price' => 10.5,
            'sell_value' => 400.00,
        ]);

        $this->assertDatabaseHas('broksums', [
            'asset_id' => $assetId,
            'date' => '2024-04-03',
            'broker' => 'CD',
        ]);

        $this->assertDatabaseHas('bandar_detector_summaries', [
            'asset_id' => $assetId,
            'from_date' => '2024-04-01',
            'to_date' => '2024-04-02',
            'transaction_type' => 'TRANSACTION_TYPE_NET',
            'broker_accdist' => 'acc',
            'number_broker_buysell' => 5,
            'total_buyer' => 3,
            'total_seller' => 2,
            'value' => 10000.00,
            'volume' => 100000,
            'average_price' => 150.5,
        ]);
    }
}
