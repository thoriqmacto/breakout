<?php

namespace Tests\Unit;

use App\Services\AssetMetrics;
use App\Services\Backtest\GenericBacktester;
use App\Services\Strategies\Strategy;
use App\Services\Strategies\TrailingStop;
use PHPUnit\Framework\TestCase;

class TrailingStopTest extends TestCase
{
    private function createBars(): array
    {
        return [
            ['date' => '2020-01-01', 'open' => 100, 'high' => 100, 'low' => 99, 'close' => 100],
            ['date' => '2020-01-02', 'open' => 110, 'high' => 110, 'low' => 109, 'close' => 110],
            ['date' => '2020-01-03', 'open' => 105, 'high' => 106, 'low' => 98, 'close' => 100],
        ];
    }

    public function test_trailing_stop_triggers_exit(): void
    {
        $bars = $this->createBars();
        $metrics = new AssetMetrics($bars);
        $strategy = new class($metrics) extends Strategy {
            public function __construct(AssetMetrics $metrics)
            {
                parent::__construct($metrics);
                $this->enableTrailingStop(TrailingStop::percent(0.1));
            }

            public function signal(): string
            {
                return $this->metrics->barCount() === 1 ? 'buy' : 'hold';
            }
        };

        $backtester = new GenericBacktester($strategy);
        $result = $backtester->run($bars, 10000.0);

        $this->assertCount(1, $result['trades']);
        $trade = $result['trades'][0];
        $this->assertSame('2020-01-03', $trade['exit_date']);
        $this->assertEquals(99.0, $trade['exit_price']);
        $this->assertEquals(9900.0, $result['final_equity']);
    }
}
