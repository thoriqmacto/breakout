<?php

namespace Tests\Unit;

use App\Services\AssetMetrics;
use App\Services\Backtest\DonchianBacktester;
use App\Services\Strategies\DonchianBreakout;
use PHPUnit\Framework\TestCase;

class DonchianStrategyTest extends TestCase
{
    /**
     * @return array<int, array{date:string, open:float, high:float, low:float, close:float}>
     */
    private function bars(): array
    {
        return [
            ['date' => '2020-01-01', 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1],
            ['date' => '2020-01-02', 'open' => 2, 'high' => 2, 'low' => 2, 'close' => 2],
            ['date' => '2020-01-03', 'open' => 3, 'high' => 3, 'low' => 3, 'close' => 3],
            ['date' => '2020-01-04', 'open' => 4, 'high' => 4, 'low' => 4, 'close' => 4],
            ['date' => '2020-01-05', 'open' => 5, 'high' => 5, 'low' => 5, 'close' => 5],
        ];
    }

    public function test_donchian_channel_calculation(): void
    {
        $metrics = new AssetMetrics(array_slice($this->bars(), 0, 3));
        $channel = $metrics->donchianChannel(3);
        $this->assertSame(3.0, $channel['upper']);
        $this->assertSame(1.0, $channel['lower']);
    }

    public function test_breakout_signal(): void
    {
        $metrics = new AssetMetrics(array_slice($this->bars(), 0, 4));
        $strategy = new DonchianBreakout($metrics, 3);
        $this->assertSame('buy', $strategy->signal());
    }

    public function test_signal_requires_sufficient_bars(): void
    {
        $metrics = new AssetMetrics(array_slice($this->bars(), 0, 3));
        $strategy = new DonchianBreakout($metrics, 3);
        $this->assertSame('hold', $strategy->signal());
    }

    public function test_donchian_channel_excludes_current_when_requested(): void
    {
        $metrics = new AssetMetrics($this->bars());
        $channel = $metrics->donchianChannel(3, false);
        $this->assertSame(4.0, $channel['upper']);
        $this->assertSame(2.0, $channel['lower']);
    }

    public function test_backtest_final_equity(): void
    {
        $backtester = new DonchianBacktester;
        $result = $backtester->run($this->bars(), 100.0);
        $this->assertSame(125.0, $result['final_equity']);
        $this->assertCount(5, $result['equity_curve']);
        $this->assertCount(1, $result['trades']);
        $this->assertSame(25.0, $result['trades'][0]['pnl']);
    }
}
