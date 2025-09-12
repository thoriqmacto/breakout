<?php

namespace Tests\Unit;

use App\Services\AssetMetrics;
use App\Services\Strategies\MovingAverageCrossover;
use PHPUnit\Framework\TestCase;

class MovingAverageCrossoverTest extends TestCase
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
                'low' => $close,
                'close' => $close,
            ];
            $i++;
        }
        return $bars;
    }

    public function test_buy_signal_when_short_above_long(): void
    {
        $metrics = new AssetMetrics($this->createBars([1, 2, 3, 4, 5, 6]));
        $strategy = new MovingAverageCrossover($metrics, 3, 5);
        $this->assertSame('buy', $strategy->signal());
    }

    public function test_sell_signal_when_short_below_long(): void
    {
        $metrics = new AssetMetrics($this->createBars([6, 5, 4, 3, 2, 1]));
        $strategy = new MovingAverageCrossover($metrics, 3, 5);
        $this->assertSame('sell', $strategy->signal());
    }

    public function test_hold_signal_when_insufficient_data(): void
    {
        $metrics = new AssetMetrics($this->createBars([1, 2, 3, 4, 5]));
        $strategy = new MovingAverageCrossover($metrics, 3, 5);
        $this->assertSame('hold', $strategy->signal());
    }
}
