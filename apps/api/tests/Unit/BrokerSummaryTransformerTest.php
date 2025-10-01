<?php

namespace Tests\Unit;

use App\Support\BrokerSummaryTransformer;
use PHPUnit\Framework\TestCase;

class BrokerSummaryTransformerTest extends TestCase
{
    public function test_transforms_various_payload_shapes(): void
    {
        $payload = [
            'items' => [
                [
                    'trading_date' => '2024-01-01',
                    'broker_code' => 'PD',
                    'buyValue' => '1,000',
                    'sellValue' => '250',
                ],
                [
                    'meta' => ['date' => '2024-01-02'],
                    'broker' => ['code' => 'NI'],
                    'values' => ['buy' => 400, 'sell' => 100],
                ],
                [
                    'date' => '1704067200',
                    'broker' => 'BR',
                    'buy_value' => null,
                    'sell_value' => null,
                    'values' => ['buy_value' => '600', 'sell_value' => '200'],
                ],
            ],
        ];

        $rows = BrokerSummaryTransformer::toRows('BBCA', $payload);

        $this->assertCount(3, $rows);
        $this->assertSame([
            'symbol' => 'BBCA',
            'date' => '2024-01-01',
            'broker' => 'PD',
            'net_value' => 750.0,
            'buy_value' => 1000.0,
            'sell_value' => 250.0,
        ], $rows[0]);

        $this->assertSame('2024-01-02', $rows[1]['date']);
        $this->assertSame('NI', $rows[1]['broker']);
        $this->assertSame(300.0, $rows[1]['net_value']);

        $this->assertSame('2024-01-01', $rows[2]['date']);
        $this->assertSame('BR', $rows[2]['broker']);
        $this->assertSame(400.0, $rows[2]['net_value']);
    }

    public function test_skips_entries_without_required_fields(): void
    {
        $rows = BrokerSummaryTransformer::toRows('BBCA', [
            'data' => [
                ['broker' => '', 'date' => '2024-01-01', 'buy_value' => 1, 'sell_value' => 1],
                ['broker' => 'AB', 'date' => '', 'buy_value' => 1, 'sell_value' => 1],
                ['broker' => 'CD'],
            ],
        ]);

        $this->assertSame([], $rows);
    }
}
