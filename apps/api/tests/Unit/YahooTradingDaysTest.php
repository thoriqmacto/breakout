<?php

namespace Tests\Unit;

use App\Models\TradingDay;
use App\Services\PythonRunner;
use App\Services\YahooTradingDays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class YahooTradingDaysTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_import_persists_close_prices_from_entries(): void
    {
        $runner = Mockery::mock(PythonRunner::class);
        $runner->shouldReceive('run')
            ->once()
            ->withArgs(function (string $script, $payload, array $args) {
                $this->assertSame('get_stocks.py', $script);
                $this->assertNull($payload);
                $this->assertContains('--emit-dates', $args);
                return true;
            })
            ->andReturn([
                'ok' => true,
                'stderr' => '',
                'stdout' => '',
                'json' => [
                    'tickers' => [
                        [
                            'ticker' => '^JKSE',
                            'entries' => [
                                ['date' => '2024-01-02', 'close' => 123.456789],
                                ['date' => '2024-01-03', 'close' => '125.5'],
                            ],
                        ],
                    ],
                ],
            ]);

        $service = new YahooTradingDays($runner, new TradingDay());

        $count = $service->import('2024-01-01', '2024-01-31');

        $this->assertSame(2, $count);

        $first = TradingDay::query()->where('date', '2024-01-02')->first();
        $second = TradingDay::query()->where('date', '2024-01-03')->first();

        $this->assertNotNull($first);
        $this->assertNotNull($second);

        $this->assertEqualsWithDelta(123.456789, (float) $first->close, 0.000001);
        $this->assertEqualsWithDelta(125.5, (float) $second->close, 0.000001);
    }

    public function test_import_handles_legacy_date_payload(): void
    {
        $runner = Mockery::mock(PythonRunner::class);
        $runner->shouldReceive('run')->once()->andReturn([
            'ok' => true,
            'stderr' => '',
            'stdout' => '',
            'json' => [
                'tickers' => [
                    [
                        'ticker' => '^JKSE',
                        'dates' => ['2024-02-01', '2024-02-02'],
                    ],
                ],
            ],
        ]);

        $service = new YahooTradingDays($runner, new TradingDay());

        $count = $service->import('2024-02-01', '2024-02-28');

        $this->assertSame(2, $count);

        $this->assertDatabaseHas('trading_days', [
            'date' => '2024-02-01',
            'close' => null,
        ]);
    }
}
