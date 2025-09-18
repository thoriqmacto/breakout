<?php

namespace App\Services\Backtest;

use App\Services\IdxTicks;

/**
 * Stateful breakout engine supporting multi-leg trailing stops.
 */
class BreakoutPositionEngine extends PositionEngine
{
    /** @var array{atr_low_pct:float, entry_mode:string, split_threshold:float, fast_trail_pct:float, slow_trail_pct:float} */
    private array $config;

    private ?BreakoutPosition $position = null;

    /**
     * @param array{atr_low_pct?:float, entry_mode?:string, split_threshold?:float, fast_trail_pct?:float, slow_trail_pct?:float} $config
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'atr_low_pct' => 0.025,
            'entry_mode' => 'either',
            'split_threshold' => 0.06,
            'fast_trail_pct' => 0.02,
            'slow_trail_pct' => 0.03,
        ], $config);
    }

    /**
     * Process the next daily bar and return generated trade events.
     *
     * @param array{date:string, open:float, high:float, low:float, close:float, volume?:float} $bar
     * @return array<int, array<string, mixed>>
     */
    public function onBar(array $bar): array
    {
        $this->recordBar($bar);
        $events = [];

        if ($this->position) {
            $events = array_merge($events, $this->position->onClose(
                $bar['date'],
                $bar['close'],
                $this->config
            ));

            if ($this->position->isClosed()) {
                $events[] = [
                    'type' => 'position_closed',
                    'date' => $bar['date'],
                ];
                $this->position = null;
            }
        }

        if ($this->position === null) {
            $atrRegime = $this->atrRegime();
            $signal = $this->detectBreakout($atrRegime);
            if ($signal !== null) {
                $entryPrice = IdxTicks::round($bar['close']);
                $initialTrail = $atrRegime === 'low'
                    ? $this->config['fast_trail_pct']
                    : $this->config['slow_trail_pct'];
                $legName = abs($initialTrail - $this->config['fast_trail_pct']) < 1e-9 ? 'fast' : 'slow';

                $this->position = new BreakoutPosition(
                    $entryPrice,
                    $bar['date'],
                    $atrRegime,
                    $signal['level'],
                    $signal['tag'],
                    new BreakoutPositionLeg($legName, $initialTrail, 1.0, $bar['close'])
                );

                $events[] = [
                    'type' => 'enter',
                    'date' => $bar['date'],
                    'price' => $entryPrice,
                    'atr_regime' => $atrRegime,
                    'breakout_level' => $signal['level'],
                    'breakout_tag' => $signal['tag'],
                    'stops' => $this->position->stopSummary(),
                ];
            }
        } elseif ($events !== []) {
            // When the position generated events (split/exit), also surface the latest trailing levels.
            $events[] = [
                'type' => 'stops_updated',
                'date' => $bar['date'],
                'stops' => $this->position?->stopSummary(includeInactive: true) ?? [],
            ];
        }

        return $events;
    }

    /**
     * Current position snapshot for inspection or reporting.
     *
     * @return array<string, mixed>|null
     */
    public function positionState(): ?array
    {
        if (! $this->position) {
            return null;
        }

        return [
            'entry_price' => $this->position->entryPrice(),
            'entry_date' => $this->position->entryDate(),
            'atr_regime' => $this->position->atrRegime(),
            'breakout_level' => $this->position->breakoutLevel(),
            'breakout_tag' => $this->position->breakoutTag(),
            'has_split' => $this->position->hasSplit(),
            'legs' => $this->position->legsState(),
        ];
    }

    /**
     * Determine whether the latest close breaks above a weekly high level.
     *
     * @param string $atrRegime
     * @return array{level:string, tag:string}|null
     */
    private function detectBreakout(string $atrRegime): ?array
    {
        $last = $this->lastBar();
        $previous = $this->previousBar();
        if (! $last || ! $previous) {
            return null;
        }

        $close = $last['close'];
        $prevClose = $previous['close'];

        $levels = [
            '20wH' => [
                'value' => $this->rollingHigh(20 * 5, requireFullPeriod: true),
                'full' => $this->hasFullHistory(20 * 5),
            ],
            '55wH' => [
                'value' => $this->rollingHigh(55 * 5),
                'full' => $this->hasFullHistory(55 * 5),
            ],
        ];

        $breached = [];
        foreach ($levels as $label => $data) {
            $level = $data['value'];
            if ($level <= 0.0) {
                continue;
            }
            if ($prevClose <= $level && $close > $level) {
                $breached[$label] = $data;
            }
        }

        if ($breached === []) {
            return null;
        }

        if (isset($breached['55wH']) && $this->hasFullHistory(55 * 5)) {
            $majorHigh = $this->rollingHighIntraday(55 * 5, requireFullPeriod: true);
            if ($majorHigh > 0.0 && $close <= $majorHigh) {
                unset($breached['55wH']);

                if ($breached === []) {
                    return null;
                }
            }
        }

        $allow55Breakout = isset($breached['55wH'])
            && ($breached['55wH']['full'] || $atrRegime === 'high');
        $allow20Breakout = isset($breached['20wH']);

        $mode = $this->config['entry_mode'];
        if ($mode === '55w_only') {
            return $allow55Breakout ? ['level' => '55wH', 'tag' => 'primary'] : null;
        }

        if ($mode === 'stacked') {
            if ($allow55Breakout) {
                return ['level' => '55wH', 'tag' => 'primary'];
            }
            if ($allow20Breakout) {
                return ['level' => '20wH', 'tag' => 'minor'];
            }
            return null;
        }

        if ($allow55Breakout) {
            return ['level' => '55wH', 'tag' => 'primary'];
        }
        if ($allow20Breakout) {
            return ['level' => '20wH', 'tag' => 'primary'];
        }

        return null;
    }

    private function atrRegime(): string
    {
        $last = $this->lastBar();
        if (! $last || $last['close'] <= 0) {
            return 'low';
        }

        $atr = $this->metrics()->atr(14);
        $atrPct = $atr / $last['close'];

        return $atrPct <= $this->config['atr_low_pct'] ? 'low' : 'high';
    }

    /**
     * Highest intraday high observed within the requested lookback window.
     */
    private function rollingHighIntraday(int $days, bool $requireFullPeriod = false): float
    {
        $count = count($this->history);
        if ($count < 2) {
            return 0.0;
        }

        if ($requireFullPeriod && $count - 1 < $days) {
            return 0.0;
        }

        $lookback = min($days, $count - 1);
        if ($lookback <= 0) {
            return 0.0;
        }

        $slice = array_slice($this->history, -($lookback + 1), $lookback);
        if ($slice === []) {
            return 0.0;
        }

        $values = array_map(static fn (array $bar): float => $bar['high'], $slice);

        return (float) max($values);
    }

    /**
     * Check whether enough prior bars have been processed to cover the requested lookback.
     */
    private function hasFullHistory(int $days): bool
    {
        return count($this->history) - 1 >= $days;
    }
}

final class BreakoutPosition
{
    private bool $hasSplit = false;

    /** @var array<string, BreakoutPositionLeg> */
    private array $legs = [];

    public function __construct(
        private float $entryPrice,
        private string $entryDate,
        private string $atrRegime,
        private string $breakoutLevel,
        private string $breakoutTag,
        BreakoutPositionLeg $initialLeg
    ) {
        $this->legs[$initialLeg->name()] = $initialLeg;
    }

    public function onClose(string $date, float $close, array $config): array
    {
        $events = [];

        foreach ($this->legs as $leg) {
            $leg->recordClose($close);
        }

        if ($this->shouldSplit($close, $config['split_threshold'])) {
            $this->performSplit($close, $config['fast_trail_pct'], $config['slow_trail_pct']);
            $events[] = [
                'type' => 'split',
                'date' => $date,
                'stops' => $this->stopSummary(),
            ];
        }

        foreach ($this->legs as $leg) {
            if ($leg->isActive() && $close <= $leg->stopLevel()) {
                $leg->deactivate();
                $events[] = [
                    'type' => 'exit',
                    'date' => $date,
                    'leg' => $leg->name(),
                    'price' => $leg->stopLevel(),
                    'trail_pct' => $leg->trailPct(),
                    'weight' => $leg->weight(),
                ];
            }
        }

        return $events;
    }

    public function isClosed(): bool
    {
        foreach ($this->legs as $leg) {
            if ($leg->isActive()) {
                return false;
            }
        }
        return true;
    }

    public function entryPrice(): float
    {
        return $this->entryPrice;
    }

    public function entryDate(): string
    {
        return $this->entryDate;
    }

    public function atrRegime(): string
    {
        return $this->atrRegime;
    }

    public function breakoutLevel(): string
    {
        return $this->breakoutLevel;
    }

    public function breakoutTag(): string
    {
        return $this->breakoutTag;
    }

    public function hasSplit(): bool
    {
        return $this->hasSplit;
    }

    public function stopSummary(bool $includeInactive = false): array
    {
        $stops = [];
        foreach ($this->legs as $leg) {
            if (! $includeInactive && ! $leg->isActive()) {
                continue;
            }
            $stops[] = [
                'name' => $leg->name(),
                'trail_pct' => $leg->trailPct(),
                'weight' => $leg->weight(),
                'stop' => $leg->stopLevel(),
                'active' => $leg->isActive(),
            ];
        }

        usort($stops, static fn (array $a, array $b): int => $a['trail_pct'] <=> $b['trail_pct']);

        return $stops;
    }

    public function legsState(): array
    {
        return $this->stopSummary(includeInactive: true);
    }

    private function shouldSplit(float $close, float $threshold): bool
    {
        if ($this->hasSplit || $this->entryPrice <= 0.0) {
            return false;
        }

        $activeLegs = array_filter($this->legs, static fn (BreakoutPositionLeg $leg): bool => $leg->isActive());
        if (count($activeLegs) !== 1) {
            return false;
        }

        $gain = ($close / $this->entryPrice) - 1.0;
        return $gain > $threshold;
    }

    private function performSplit(float $close, float $fastPct, float $slowPct): void
    {
        $this->hasSplit = true;

        $activeLeg = null;
        foreach ($this->legs as $leg) {
            if ($leg->isActive()) {
                $activeLeg = $leg;
                break;
            }
        }

        if (! $activeLeg) {
            return;
        }

        $activeLeg->setWeight(0.5);

        $existingName = $activeLeg->name();
        $missingName = $existingName === 'fast' ? 'slow' : 'fast';
        $missingPct = $missingName === 'fast' ? $fastPct : $slowPct;

        $highest = $this->maxHighestClose();
        $newLeg = new BreakoutPositionLeg($missingName, $missingPct, 0.5, $highest);
        $newLeg->recordClose($close);

        $this->legs[$missingName] = $newLeg;
    }

    private function maxHighestClose(): float
    {
        $values = array_map(static fn (BreakoutPositionLeg $leg): float => $leg->highestClose(), $this->legs);
        return $values === [] ? 0.0 : max($values);
    }
}

final class BreakoutPositionLeg
{
    private float $highestClose;

    private float $stop;

    private bool $active = true;

    public function __construct(
        private string $name,
        private float $trailPct,
        private float $weight,
        float $entryClose
    ) {
        $this->highestClose = $entryClose;
        $this->stop = IdxTicks::floor($entryClose * (1 - $this->trailPct), $entryClose);
    }

    public function recordClose(float $close): void
    {
        if (! $this->active) {
            return;
        }

        if ($close > $this->highestClose) {
            $this->highestClose = $close;
        }

        $this->stop = IdxTicks::floor($this->highestClose * (1 - $this->trailPct), $this->highestClose);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function stopLevel(): float
    {
        return $this->stop;
    }

    public function trailPct(): float
    {
        return $this->trailPct;
    }

    public function weight(): float
    {
        return $this->weight;
    }

    public function setWeight(float $weight): void
    {
        $this->weight = $weight;
    }

    public function deactivate(): void
    {
        $this->active = false;
        $this->weight = 0.0;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function highestClose(): float
    {
        return $this->highestClose;
    }
}
