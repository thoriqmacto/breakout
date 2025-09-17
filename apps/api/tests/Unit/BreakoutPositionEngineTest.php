<?php

namespace Tests\Unit;

use App\Services\Backtest\BreakoutPositionEngine;
use App\Services\IdxTicks;
use PHPUnit\Framework\TestCase;

class BreakoutPositionEngineTest extends TestCase
{
    private function date(int $offset): string
    {
        return date('Y-m-d', strtotime("2020-01-01 +{$offset} days"));
    }

    private function runSeries(BreakoutPositionEngine $engine, array $bars): array
    {
        $events = [];
        foreach ($bars as $bar) {
            $events = $engine->onBar($bar);
        }
        return $events;
    }

    public function test_low_atr_entry_sets_two_percent_stop(): void
    {
        $engine = new BreakoutPositionEngine();

        $bars = [];
        for ($i = 0; $i < 100; $i++) {
            $bars[] = [
                'date' => $this->date($i),
                'open' => 99.4,
                'high' => 100.0,
                'low' => 99.0,
                'close' => 99.4,
            ];
        }

        $bars[] = [
            'date' => $this->date(100),
            'open' => 100.0,
            'high' => 102.6,
            'low' => 99.8,
            'close' => 102.6,
        ];

        $events = $this->runSeries($engine, $bars);

        $this->assertNotEmpty($events);
        $this->assertSame('enter', $events[0]['type']);
        $this->assertSame('low', $events[0]['atr_regime']);
        $this->assertSame('20wH', $events[0]['breakout_level']);

        $state = $engine->positionState();
        $this->assertNotNull($state);
        $this->assertSame(IdxTicks::round(102.6), $state['entry_price']);
        $this->assertSame('primary', $state['breakout_tag']);
        $this->assertCount(1, $state['legs']);

        $leg = $state['legs'][0];
        $this->assertSame(0.02, $leg['trail_pct']);
        $this->assertSame(1.0, $leg['weight']);

        $expectedStop = IdxTicks::floor(102.6 * (1 - 0.02), 102.6);
        $this->assertEquals($expectedStop, $leg['stop']);
    }

    public function test_high_atr_entry_sets_three_percent_stop(): void
    {
        $engine = new BreakoutPositionEngine();

        $bars = [];
        for ($i = 0; $i < 100; $i++) {
            $bars[] = [
                'date' => $this->date($i),
                'open' => 90.0,
                'high' => 110.0,
                'low' => 80.0,
                'close' => 90.0,
            ];
        }

        $bars[] = [
            'date' => $this->date(100),
            'open' => 95.0,
            'high' => 105.0,
            'low' => 90.0,
            'close' => 105.0,
        ];

        $events = $this->runSeries($engine, $bars);
        $this->assertNotEmpty($events);
        $this->assertSame('enter', $events[0]['type']);
        $this->assertSame('high', $events[0]['atr_regime']);

        $state = $engine->positionState();
        $this->assertNotNull($state);
        $this->assertSame('55wH', $state['breakout_level']); // both levels match so prefer 55wH
        $leg = $state['legs'][0];
        $this->assertSame(0.03, $leg['trail_pct']);

        $expectedStop = IdxTicks::floor(105.0 * (1 - 0.03), 105.0);
        $this->assertEquals($expectedStop, $leg['stop']);
    }

    public function test_split_after_six_percent_gain_creates_two_legs(): void
    {
        $engine = new BreakoutPositionEngine();

        $bars = [];
        for ($i = 0; $i < 100; $i++) {
            $bars[] = [
                'date' => $this->date($i),
                'open' => 99.0,
                'high' => 100.0,
                'low' => 98.5,
                'close' => 99.0,
            ];
        }

        $bars[] = [
            'date' => $this->date(100),
            'open' => 100.0,
            'high' => 102.0,
            'low' => 99.5,
            'close' => 102.0,
        ];

        $this->runSeries($engine, $bars);

        $splitBar = [
            'date' => $this->date(101),
            'open' => 103.0,
            'high' => 110.0,
            'low' => 103.0,
            'close' => 110.0,
        ];

        $events = $engine->onBar($splitBar);
        $this->assertNotEmpty($events);
        $this->assertSame('split', $events[0]['type']);

        $state = $engine->positionState();
        $this->assertNotNull($state);
        $this->assertTrue($state['has_split']);
        $this->assertCount(2, $state['legs']);

        [$fast, $slow] = $state['legs'];
        $this->assertSame('fast', $fast['name']);
        $this->assertSame(0.5, $fast['weight']);
        $this->assertSame(0.02, $fast['trail_pct']);
        $this->assertEquals(IdxTicks::floor(110.0 * (1 - 0.02), 110.0), $fast['stop']);

        $this->assertSame('slow', $slow['name']);
        $this->assertSame(0.5, $slow['weight']);
        $this->assertSame(0.03, $slow['trail_pct']);
        $this->assertEquals(IdxTicks::floor(110.0 * (1 - 0.03), 110.0), $slow['stop']);
    }

    public function test_fast_leg_exits_when_price_hits_stop(): void
    {
        $engine = new BreakoutPositionEngine();

        $bars = [];
        for ($i = 0; $i < 100; $i++) {
            $bars[] = [
                'date' => $this->date($i),
                'open' => 99.0,
                'high' => 100.0,
                'low' => 98.5,
                'close' => 99.0,
            ];
        }

        $bars[] = [
            'date' => $this->date(100),
            'open' => 100.0,
            'high' => 102.0,
            'low' => 99.5,
            'close' => 102.0,
        ];

        $this->runSeries($engine, $bars);

        $engine->onBar([
            'date' => $this->date(101),
            'open' => 103.0,
            'high' => 110.0,
            'low' => 103.0,
            'close' => 110.0,
        ]);

        $exitBar = [
            'date' => $this->date(102),
            'open' => 108.0,
            'high' => 108.0,
            'low' => 106.0,
            'close' => 107.0,
        ];

        $events = $engine->onBar($exitBar);
        $this->assertNotEmpty($events);
        $this->assertSame('exit', $events[0]['type']);
        $this->assertSame('fast', $events[0]['leg']);

        $state = $engine->positionState();
        $this->assertNotNull($state);
        $this->assertCount(2, $state['legs']);

        [$fast, $slow] = $state['legs'];
        $this->assertFalse($fast['active']);
        $this->assertSame(0.0, $fast['weight']);
        $this->assertTrue($slow['active']);
        $this->assertSame(0.5, $slow['weight']);
    }

    public function test_entry_mode_55w_only_waits_for_major_breakout(): void
    {
        $engine = new BreakoutPositionEngine(['entry_mode' => '55w_only']);

        $bars = [];
        for ($i = 0; $i < 175; $i++) {
            $bars[] = [
                'date' => $this->date($i),
                'open' => 100.0,
                'high' => 120.0,
                'low' => 95.0,
                'close' => 100.0,
            ];
        }

        for ($i = 175; $i < 275; $i++) {
            $bars[] = [
                'date' => $this->date($i),
                'open' => 90.0,
                'high' => 100.0,
                'low' => 85.0,
                'close' => 90.0,
            ];
        }

        $minorBreakout = [
            'date' => $this->date(275),
            'open' => 95.0,
            'high' => 102.0,
            'low' => 95.0,
            'close' => 102.0,
        ];

        $events = $this->runSeries($engine, array_merge($bars, [$minorBreakout]));
        $this->assertSame([], $events);
        $this->assertNull($engine->positionState());

        $majorBreakout = [
            'date' => $this->date(276),
            'open' => 110.0,
            'high' => 122.0,
            'low' => 108.0,
            'close' => 122.0,
        ];

        $events = $engine->onBar($majorBreakout);
        $this->assertNotEmpty($events);
        $this->assertSame('enter', $events[0]['type']);
        $this->assertSame('55wH', $events[0]['breakout_level']);
    }

    public function test_stacked_mode_marks_minor_breakout(): void
    {
        $engine = new BreakoutPositionEngine(['entry_mode' => 'stacked']);

        $bars = [];
        for ($i = 0; $i < 175; $i++) {
            $bars[] = [
                'date' => $this->date($i),
                'open' => 100.0,
                'high' => 120.0,
                'low' => 95.0,
                'close' => 100.0,
            ];
        }

        for ($i = 175; $i < 275; $i++) {
            $bars[] = [
                'date' => $this->date($i),
                'open' => 90.0,
                'high' => 100.0,
                'low' => 85.0,
                'close' => 90.0,
            ];
        }

        $minorBreakout = [
            'date' => $this->date(275),
            'open' => 95.0,
            'high' => 102.0,
            'low' => 95.0,
            'close' => 102.0,
        ];

        $events = $this->runSeries($engine, array_merge($bars, [$minorBreakout]));
        $this->assertNotEmpty($events);
        $this->assertSame('enter', $events[0]['type']);
        $this->assertSame('20wH', $events[0]['breakout_level']);
        $this->assertSame('minor', $events[0]['breakout_tag']);
    }
}
