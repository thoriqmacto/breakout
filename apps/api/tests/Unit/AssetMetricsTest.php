<?php

namespace Tests\Unit;

use App\Services\AssetMetrics;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class AssetMetricsTest extends TestCase
{
    /**
     * Create deterministic OHLC fixtures for 300 trading days.
     * Each day close increases by 1, high equals close, low two points below.
     *
     * @return array<int, array{date:string, open:float, high:float, low:float, close:float}>
     */
    private function makeBars(): array
    {
        $bars = [];
        $start = strtotime('2020-01-01');
        for ($i = 0; $i < 300; $i++) {
            $close = $i + 1; // deterministic progression
            $bars[] = [
                'date'  => date('Y-m-d', $start + $i * 86400),
                'open'  => $close,
                'high'  => $close,
                'low'   => $close - 2,
                'close' => $close,
                'volume' => 1000 + $i,
            ];
        }
        return $bars;
    }

    private function metrics(): AssetMetrics
    {
        return new AssetMetrics($this->makeBars());
    }

    /**
     * @return array<int, array{date:string, open:float, high:float, low:float, close:float, volume:float}>
     */
    private function loadHistoricalBars(string $symbol): array
    {
        $path = dirname(__DIR__, 2) . "/database/seeders/data/historical/{$symbol}.csv";
        if (!is_file($path)) {
            $this->fail("Historical data for symbol {$symbol} not found at {$path}");
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->fail("Unable to open historical data file: {$path}");
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            $this->fail("Historical data file {$path} is empty");
        }

        $bars = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 6) {
                continue;
            }

            [$date, $open, $high, $low, $close, $volume] = $row;
            $parsed = DateTimeImmutable::createFromFormat('d/m/Y', $date);
            $bars[] = [
                'date' => $parsed ? $parsed->format('Y-m-d') : $date,
                'open' => (float) $open,
                'high' => (float) $high,
                'low' => (float) $low,
                'close' => (float) $close,
                'volume' => (float) $volume,
            ];
        }

        fclose($handle);

        usort($bars, static fn(array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $bars;
    }

    public function test_atr_matches_manual_value(): void
    {
        $metrics = $this->metrics();
        $this->assertEquals(2.0, $metrics->atr(14));
    }

    public function test_weekly_highs_and_moving_averages(): void
    {
        $metrics = $this->metrics();
        $this->assertEquals(300.0, $metrics->periodHigh(20));
        $this->assertEquals(300.0, $metrics->periodHigh(55));

        $this->assertEqualsWithDelta(200.5, $metrics->movingAverage(200), 0.0001);
        $this->assertEqualsWithDelta(250.5, $metrics->movingAverage(100), 0.0001);
        $this->assertEqualsWithDelta(275.5, $metrics->movingAverage(50), 0.0001);
        $this->assertEqualsWithDelta(225.5, $metrics->movingAverage(30), 0.0001);
    }

    public function test_moving_average_weeks_matches_historical_average(): void
    {
        $bars = $this->loadHistoricalBars('BUMI');
        $metrics = new AssetMetrics($bars);

        $this->assertEqualsWithDelta(115.06, $metrics->movingAverageWeeks(10), 0.0001);
        $this->assertEqualsWithDelta(110.2, $metrics->movingAverageWeeks(30), 0.0001);
    }

    public function test_support_and_resistance_levels(): void
    {
        $metrics = $this->metrics();
        $levels = $metrics->supportResistance(55);
        $this->assertSame(297.0, $levels['support']);
        $this->assertSame(299.0, $levels['resistance']);
    }

    public function test_trend_detection_and_roc(): void
    {
        $metrics = $this->metrics();
        $this->assertTrue($metrics->isUptrend());

        $expectedRoc = (300 - 235) / 235 * 100; // 13 weeks = 65 days
        $this->assertEqualsWithDelta($expectedRoc, $metrics->rocWeeks(13), 0.0001);
    }

    public function test_breakout_detection(): void
    {
        $bars = $this->makeBars();
        $metrics = new AssetMetrics($bars);
        $prevMetrics = new AssetMetrics(array_slice($bars, 0, -1));
        $level = $prevMetrics->periodHigh(20); // 299
        $this->assertTrue($metrics->isBreakout($level));
    }

    public function test_previous_close(): void
    {
        $metrics = $this->metrics();
        $this->assertSame(299.0, $metrics->previousClose());
    }

    public function test_average_volume_last_20_days(): void
    {
        $metrics = $this->metrics();
        $this->assertEqualsWithDelta(1289.5, $metrics->averageVolume(20), 0.0001);
    }

    public function test_last_volume_and_close_to_high(): void
    {
        $metrics = $this->metrics();
        $this->assertSame(1299.0, $metrics->lastVolume());
        $close = $metrics->lastClose();
        $this->assertSame(300.0, $close);
        $this->assertSame(1.0, $close / $metrics->periodHigh(20));
        $this->assertSame(1.0, $close / $metrics->periodHigh(55));
    }
}
