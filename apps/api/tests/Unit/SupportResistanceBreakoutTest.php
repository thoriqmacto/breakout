<?php

namespace Tests\Unit;

use App\Services\AssetMetrics;
use App\Services\Strategies\SupportResistanceBreakout;
use PHPUnit\Framework\TestCase;

class SupportResistanceBreakoutTest extends TestCase
{
    /**
     * @return array<int, array{date:string, open:float, high:float, low:float, close:float}>
     */
    private function buyBars(): array
    {
        return [
            ['date' => '2020-01-01', 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1],
            ['date' => '2020-01-02', 'open' => 2, 'high' => 2, 'low' => 2, 'close' => 2],
            ['date' => '2020-01-03', 'open' => 3, 'high' => 3, 'low' => 3, 'close' => 3],
            ['date' => '2020-01-04', 'open' => 4, 'high' => 4, 'low' => 4, 'close' => 4],
            ['date' => '2020-01-05', 'open' => 5, 'high' => 5, 'low' => 5, 'close' => 5],
            ['date' => '2020-01-06', 'open' => 10, 'high' => 5, 'low' => 5, 'close' => 10],
        ];
    }

    /**
     * @return array<int, array{date:string, open:float, high:float, low:float, close:float}>
     */
    private function sellBars(): array
    {
        return [
            ['date' => '2020-01-01', 'open' => 5, 'high' => 5, 'low' => 5, 'close' => 5],
            ['date' => '2020-01-02', 'open' => 6, 'high' => 6, 'low' => 6, 'close' => 6],
            ['date' => '2020-01-03', 'open' => 7, 'high' => 7, 'low' => 7, 'close' => 7],
            ['date' => '2020-01-04', 'open' => 8, 'high' => 8, 'low' => 8, 'close' => 8],
            ['date' => '2020-01-05', 'open' => 9, 'high' => 9, 'low' => 9, 'close' => 9],
            ['date' => '2020-01-06', 'open' => -1, 'high' => 0, 'low' => 0, 'close' => -1],
        ];
    }

    /**
     * @return array<int, array{date:string, open:float, high:float, low:float, close:float}>
     */
    private function holdBars(): array
    {
        return [
            ['date' => '2020-01-01', 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1],
            ['date' => '2020-01-02', 'open' => 2, 'high' => 2, 'low' => 2, 'close' => 2],
            ['date' => '2020-01-03', 'open' => 3, 'high' => 3, 'low' => 3, 'close' => 3],
            ['date' => '2020-01-04', 'open' => 4, 'high' => 4, 'low' => 4, 'close' => 4],
            ['date' => '2020-01-05', 'open' => 4, 'high' => 6, 'low' => 3, 'close' => 4],
            ['date' => '2020-01-06', 'open' => 5, 'high' => 5, 'low' => 4, 'close' => 5],
        ];
    }

    public function test_buy_signal_when_close_exceeds_resistance(): void
    {
        $metrics = new AssetMetrics($this->buyBars());
        $strategy = new SupportResistanceBreakout($metrics, 1);
        $this->assertSame('buy', $strategy->signal());
    }

    public function test_sell_signal_when_close_below_support(): void
    {
        $metrics = new AssetMetrics($this->sellBars());
        $strategy = new SupportResistanceBreakout($metrics, 1);
        $this->assertSame('sell', $strategy->signal());
    }

    public function test_hold_signal_between_levels(): void
    {
        $metrics = new AssetMetrics($this->holdBars());
        $strategy = new SupportResistanceBreakout($metrics, 1);
        $this->assertSame('hold', $strategy->signal());
    }
}
