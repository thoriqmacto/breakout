<?php

namespace Tests\Unit;

use App\Services\AssetMetrics;
use App\Services\Strategies\RsiReversal;
use PHPUnit\Framework\TestCase;

class RsiReversalTest extends TestCase
{
    /**
     * @param  array<int,float>  $closes
     * @return array<int, array{date:string, open:float, high:float, low:float, close:float}>
     */
    private function bars(array $closes): array
    {
        $bars = [];
        foreach ($closes as $i => $close) {
            $bars[] = [
                'date' => '2020-01-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                'open' => $close,
                'high' => $close,
                'low' => $close,
                'close' => $close,
            ];
        }

        return $bars;
    }

    public function test_rsi_calculation(): void
    {
        $closes = [10, 11, 12, 11, 10, 9, 10, 11, 12, 11, 10, 9, 10, 11, 12];
        $metrics = new AssetMetrics($this->bars($closes));
        $this->assertEqualsWithDelta(57.14, $metrics->relativeStrengthIndex(), 0.01);
    }

    public function test_buy_signal_when_rsi_crosses_above_oversold(): void
    {
        $closes = [10, 9, 8, 7, 8];
        $metrics = new AssetMetrics($this->bars($closes));
        $strategy = new RsiReversal($metrics, 3);
        $this->assertSame('buy', $strategy->signal());
    }

    public function test_sell_signal_when_rsi_crosses_below_overbought(): void
    {
        $closes = [10, 11, 12, 13, 12];
        $metrics = new AssetMetrics($this->bars($closes));
        $strategy = new RsiReversal($metrics, 3);
        $this->assertSame('sell', $strategy->signal());
    }

    public function test_hold_signal_when_no_cross_occurs(): void
    {
        $closes = [10, 9, 8, 7, 6];
        $metrics = new AssetMetrics($this->bars($closes));
        $strategy = new RsiReversal($metrics, 3);
        $this->assertSame('hold', $strategy->signal());
    }
}
