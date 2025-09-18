<?php

namespace Tests\Unit;

use App\Services\AssetMetrics;
use App\Services\Backtest\GenericBacktester;
use App\Services\Strategies\BaseStrategy;
use PHPUnit\Framework\TestCase;

class TickRoundingBacktestTest extends TestCase
{
    /**
     * @return array<int, array{date:string, open:float, high:float, low:float, close:float}>
     */
    private function bars(): array
    {
        return [
            ['date' => '2020-01-01', 'open' => 100.4, 'high' => 100.5, 'low' => 100.1, 'close' => 100.4],
            ['date' => '2020-01-02', 'open' => 102.6, 'high' => 103.0, 'low' => 102.0, 'close' => 102.6],
        ];
    }

    public function test_rounds_trade_prices_and_equity_curve(): void
    {
        $bars = $this->bars();
        $metrics = new AssetMetrics($bars);
        $strategy = new class($metrics) extends BaseStrategy {
            public function signal(): string
            {
                return $this->metrics->barCount() === 1 ? 'buy' : 'sell';
            }
        };

        $backtester = new GenericBacktester($strategy);
        $result = $backtester->run($bars, 1000.0);

        $this->assertSame(1030.0, $result['final_equity']);
        $this->assertSame(100.0, $result['trades'][0]['entry_price']);
        $this->assertSame(103.0, $result['trades'][0]['exit_price']);
        $this->assertSame([
            ['date' => '2020-01-01', 'equity' => 1000.0],
            ['date' => '2020-01-02', 'equity' => 1030.0],
        ], $result['equity_curve']);
    }
}

