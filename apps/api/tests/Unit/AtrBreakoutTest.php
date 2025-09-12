<?php

namespace Tests\Unit;

use App\Services\AssetMetrics;
use App\Services\Strategies\AtrBreakout;
use PHPUnit\Framework\TestCase;

class AtrBreakoutTest extends TestCase
{
    /**
     * @param array<int, float> $closes
     * @return array<int, array{date:string, open:float, high:float, low:float, close:float}>
     */
    private function createBars(array $closes): array
    {
        $bars = [];
        $i = 1;
        foreach ($closes as $close) {
            $date = sprintf('2020-01-%02d', $i);
            $bars[] = [
                'date' => $date,
                'open' => $close,
                'high' => $close,
                'low' => $close - 1,
                'close' => $close,
            ];
            $i++;
        }
        return $bars;
    }

    public function test_buy_signal_when_close_breaks_above(): void
    {
        $metrics = new AssetMetrics($this->createBars([1, 2, 3, 5]));
        $strategy = new AtrBreakout($metrics, multiplier: 1.0, period: 3);
        $this->assertSame('buy', $strategy->signal());
    }

    public function test_sell_signal_when_close_breaks_below(): void
    {
        $metrics = new AssetMetrics($this->createBars([5, 4, 3, -1]));
        $strategy = new AtrBreakout($metrics, multiplier: 1.0, period: 3);
        $this->assertSame('sell', $strategy->signal());
    }

    public function test_hold_signal_when_within_band(): void
    {
        $metrics = new AssetMetrics($this->createBars([1, 2, 3, 4]));
        $strategy = new AtrBreakout($metrics, multiplier: 1.0, period: 3);
        $this->assertSame('hold', $strategy->signal());
    }

    public function test_signal_requires_sufficient_bars(): void
    {
        $metrics = new AssetMetrics($this->createBars([1, 2, 3]));
        $strategy = new AtrBreakout($metrics, multiplier: 1.0, period: 3);
        $this->assertSame('hold', $strategy->signal());
    }
}

