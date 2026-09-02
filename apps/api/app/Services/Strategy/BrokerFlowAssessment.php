<?php

namespace App\Services\Strategy;

/**
 * What broker flow looked like for one asset as of one session.
 *
 * Immutable, and carries its own explanation. Every field here is derived
 * from `broker_accumulation_windows` rows that ended on or before the session
 * being described, so an assessment for an old date is identical whether or
 * not later broker data exists.
 */
final class BrokerFlowAssessment
{
    /**
     * @param  array<int, array{
     *   window_days: int,
     *   avg_net_norm: float,
     *   top3_net_norm: float,
     *   accdist_score: int,
     *   broker_count: int,
     *   value: float,
     *   covered_days: int|null,
     *   direction: int,
     * }>  $windows  keyed by window length
     * @param  array<int, string>  $reasons
     */
    public function __construct(
        public readonly string $regime,
        public readonly array $windows,
        public readonly int $positiveWindows,
        public readonly int $negativeWindows,
        public readonly int $availableWindows,
        public readonly ?float $persistenceRatio,
        public readonly ?float $acceleration,
        public readonly ?float $avgNetNorm,
        public readonly ?float $top3NetNorm,
        public readonly ?float $consistency,
        public readonly ?int $activeBrokers,
        public readonly ?float $concentration,
        public readonly ?int $pbas,
        public readonly ?string $windowEndDate,
        public readonly array $reasons = [],
    ) {}

    /**
     * Flow for one window length, or null when that window has no rollup.
     *
     * @return array<string, mixed>|null
     */
    public function window(int $days): ?array
    {
        return $this->windows[$days] ?? null;
    }

    /**
     * +1 accumulating, -1 distributing, 0 flat or unavailable.
     */
    public function direction(int $days): int
    {
        return (int) ($this->windows[$days]['direction'] ?? 0);
    }

    public function netNorm(int $days): ?float
    {
        return isset($this->windows[$days]) ? (float) $this->windows[$days]['avg_net_norm'] : null;
    }

    public function hasAnyWindow(): bool
    {
        return $this->availableWindows > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $flows = [];

        foreach ($this->windows as $days => $window) {
            $flows['flow_'.$days.'d'] = [
                'window_days' => $days,
                'avg_net_norm' => round((float) $window['avg_net_norm'], 8),
                'top3_net_norm' => round((float) $window['top3_net_norm'], 8),
                'accdist_score' => (int) $window['accdist_score'],
                'broker_count' => (int) $window['broker_count'],
                'covered_days' => $window['covered_days'],
                'direction' => (int) $window['direction'],
            ];
        }

        return array_merge([
            'regime' => $this->regime,
            'persistence_ratio' => $this->persistenceRatio,
            'positive_windows' => $this->positiveWindows,
            'negative_windows' => $this->negativeWindows,
            'available_windows' => $this->availableWindows,
            'acceleration' => $this->acceleration,
            'avg_net_norm' => $this->avgNetNorm,
            'top3_net_norm' => $this->top3NetNorm,
            'consistency' => $this->consistency,
            'active_brokers' => $this->activeBrokers,
            'concentration' => $this->concentration,
            'pbas' => $this->pbas,
            'window_end_date' => $this->windowEndDate,
            'reasons' => $this->reasons,
        ], $flows);
    }

    public static function unavailable(string $reason = 'no broker accumulation windows available'): self
    {
        return new self(
            regime: BrokerRegime::NEUTRAL,
            windows: [],
            positiveWindows: 0,
            negativeWindows: 0,
            availableWindows: 0,
            persistenceRatio: null,
            acceleration: null,
            avgNetNorm: null,
            top3NetNorm: null,
            consistency: null,
            activeBrokers: null,
            concentration: null,
            pbas: null,
            windowEndDate: null,
            reasons: [$reason],
        );
    }
}
