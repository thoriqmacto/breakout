<?php

namespace Tests\Unit;

use App\Services\AssetMetrics;
use App\Services\Backtest\GenericBacktester;
use App\Services\Strategies\Strategy;
use PHPUnit\Framework\TestCase;

class BacktesterMetricsTest extends TestCase
{
    /**
     * @return array<int, array{date:string, open:float, high:float, low:float, close:float}>
     */
    private function bars(): array
    {
        return [
            ['date' => '2020-01-01', 'open' => 100.0, 'high' => 100.0, 'low' => 100.0, 'close' => 100.0],
            ['date' => '2021-01-01', 'open' => 110.0, 'high' => 110.0, 'low' => 110.0, 'close' => 110.0],
        ];
    }

    public function test_calculates_metrics(): void
    {
        $bars = $this->bars();
        $metrics = new AssetMetrics($bars);
        $strategy = new class($metrics) extends Strategy {
            public function signal(): string
            {
                return $this->metrics->barCount() === 1 ? 'buy' : 'sell';
            }
        };

        $backtester = new GenericBacktester($strategy);
        $result = $backtester->run($bars, 1000.0);

        $metrics = $backtester->calculateMetrics(
            $bars,
            $result['equity_curve'],
            $result['trades'],
            1000.0,
            $result['final_equity']
        );

        $this->assertEqualsWithDelta(0.10, $metrics['cagr'], 0.0001);
        $this->assertSame(0.0, $metrics['maxdd']);
        $this->assertSame(0.0, $metrics['sharpe']);
        $this->assertSame(1.0, $metrics['winrate']);
        $this->assertTrue(is_infinite($metrics['profit_factor']));
        $this->assertSame(1, $metrics['trades']);
    }
}
