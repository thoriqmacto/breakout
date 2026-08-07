<?php

namespace Tests\Unit;

use App\Services\AssetMetrics;
use App\Services\Strategies\RocMomentum;
use PHPUnit\Framework\TestCase;

class RocMomentumTest extends TestCase
{
    /**
     * @param  array<int, float>  $closes
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

    public function test_buy_signal_when_roc_above_threshold(): void
    {
        $bars = $this->bars([100, 100, 100, 100, 100, 110]);
        $metrics = new AssetMetrics($bars);
        $strategy = new RocMomentum($metrics, 1, 5);
        $this->assertSame('buy', $strategy->signal());
    }

    public function test_sell_signal_when_roc_below_negative_threshold(): void
    {
        $bars = $this->bars([100, 100, 100, 100, 100, 90]);
        $metrics = new AssetMetrics($bars);
        $strategy = new RocMomentum($metrics, 1, 5);
        $this->assertSame('sell', $strategy->signal());
    }

    public function test_hold_signal_when_roc_within_threshold(): void
    {
        $bars = $this->bars([100, 100, 100, 100, 100, 102]);
        $metrics = new AssetMetrics($bars);
        $strategy = new RocMomentum($metrics, 1, 5);
        $this->assertSame('hold', $strategy->signal());
    }
}
