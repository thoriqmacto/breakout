<?php

namespace App\Services\Strategy;

/**
 * Reads the 3/5/10/20-day broker rollups as four different questions.
 *
 * The rollups already exist -- `broker_accumulation_windows` has held
 * avg_net_norm, top3_net_norm, accdist_score and broker_count per window
 * length since the strategy module was built. What was missing is what they
 * are worth together. StrategyScoringService averages them into one number
 * weighted by window length, which answers "how much net buying" and cannot
 * answer "for how long", and those are not the same question:
 *
 *     3D  +0.09   5D  -0.01   10D  -0.02   20D  -0.03
 *     3D  +0.01   5D  +0.01   10D  +0.01   20D  +0.01
 *
 * A length-weighted average ranks the first above the second on the strength
 * of one unusual session, while the second is the one that looks like
 * somebody accumulating. So each window keeps its own meaning here:
 *
 *     3D   short-term acceleration
 *     5D   near-term confirmation
 *     10D  primary accumulation regime
 *     20D  background accumulation regime
 *
 * and the regime is decided by the medium-term three, never by 3D alone.
 *
 * Pure PHP: the caller supplies the rows. Nothing here queries, and nothing
 * here can reach a window that ended after the session being assessed.
 */
class BrokerFlowAnalyzer
{
    /**
     * Assess one asset's broker flow.
     *
     * @param  array<int, array<string, mixed>>  $rowsByWindowDays  rollup rows keyed by window_days
     * @param  int|null  $pbas  same-day PBAS, when the feature row has one
     */
    public function analyze(array $rowsByWindowDays, StrategyProfile $profile, ?int $pbas = null, ?string $windowEndDate = null): BrokerFlowAssessment
    {
        $windows = $this->normalizeWindows($rowsByWindowDays, $profile);

        if ($windows === []) {
            return BrokerFlowAssessment::unavailable();
        }

        $reasons = [];

        $positive = 0;
        $negative = 0;

        foreach ($windows as $window) {
            if ($window['direction'] > 0) {
                $positive++;
            } elseif ($window['direction'] < 0) {
                $negative++;
            }
        }

        $available = count($windows);
        $persistence = $available > 0 ? round($positive / $available, 4) : null;

        $regimeWindows = array_intersect_key($windows, array_flip($profile->brokerRegimeWindows));
        [$regime, $regimeReasons, $regimeTop3, $regimeAvg] = $this->classify($regimeWindows, $profile);

        $reasons = array_merge($reasons, $regimeReasons);
        $reasons[] = sprintf('%d/%d broker windows positive', $positive, $available);

        $acceleration = $this->acceleration($windows);

        if ($acceleration !== null) {
            $reasons[] = $acceleration >= 0
                ? sprintf('short-term flow running %.4f above the background window', $acceleration)
                : sprintf('short-term flow running %.4f below the background window', abs($acceleration));
        }

        // Longest available window carries the most representative broker
        // count: a three-day rollup can look concentrated purely because few
        // brokers happened to trade.
        $longest = $windows[max(array_keys($windows))];

        return new BrokerFlowAssessment(
            regime: $regime,
            windows: $windows,
            positiveWindows: $positive,
            negativeWindows: $negative,
            availableWindows: $available,
            persistenceRatio: $persistence,
            acceleration: $acceleration,
            avgNetNorm: $regimeAvg,
            top3NetNorm: $regimeTop3,
            consistency: $this->consistency($windows),
            activeBrokers: (int) $longest['broker_count'],
            concentration: $this->concentration($longest),
            pbas: $pbas,
            windowEndDate: $windowEndDate,
            reasons: $reasons,
        );
    }

    /**
     * What deteriorating broker flow means for a position already held.
     *
     * One window turning negative is not an exit. The rule is persistence in
     * the other direction: the short window alone tightens the stop, the
     * short and near-term together are a distribution warning, and only that
     * plus weakening price structure is an exit warning. Nothing here moves
     * the price stop -- it reports, and the caller decides.
     *
     * @return array{action: string, severity: int, reasons: array<int, string>}
     */
    public function deterioration(BrokerFlowAssessment $flow, bool $priceWeakening = false): array
    {
        if (! $flow->hasAnyWindow()) {
            return [
                'action' => PositionAction::HOLD,
                'severity' => 0,
                'reasons' => ['no broker windows cover this position'],
            ];
        }

        $short = $flow->direction(3);
        $near = $flow->direction(5);
        $primary = $flow->direction(10);

        $reasons = [];

        if ($short < 0 && $near < 0 && $priceWeakening) {
            return [
                'action' => PositionAction::EXIT_WARNING,
                'severity' => 3,
                'reasons' => ['3D and 5D broker flow both negative and price structure weakening'],
            ];
        }

        if ($short < 0 && $near < 0) {
            return [
                'action' => PositionAction::EXIT_WARNING,
                'severity' => 2,
                'reasons' => ['3D and 5D broker flow both negative: distribution rather than a single soft session'],
            ];
        }

        if ($short < 0 && ($near > 0 || $primary > 0)) {
            $reasons[] = '3D broker flow negative while the medium-term windows still accumulate';

            return [
                'action' => PositionAction::HOLD_TIGHTEN_STOP,
                'severity' => 1,
                'reasons' => $reasons,
            ];
        }

        if (BrokerRegime::isDistributive($flow->regime)) {
            return [
                'action' => PositionAction::HOLD_TIGHTEN_STOP,
                'severity' => 1,
                'reasons' => [sprintf('broker regime is %s', $flow->regime)],
            ];
        }

        return [
            'action' => PositionAction::HOLD,
            'severity' => 0,
            'reasons' => [sprintf('broker regime is %s; medium-term flow intact', $flow->regime)],
        ];
    }

    /**
     * The regime decision, and why.
     *
     * Deliberately made only from the medium-term windows. Requiring every
     * one of them to agree before "STRONG" is what keeps the label rare
     * enough to mean something.
     *
     * @param  array<int, array<string, mixed>>  $regimeWindows
     * @return array{0: string, 1: array<int, string>, 2: ?float, 3: ?float}
     */
    private function classify(array $regimeWindows, StrategyProfile $profile): array
    {
        if ($regimeWindows === []) {
            return [
                BrokerRegime::NEUTRAL,
                ['no medium-term broker window available, so no regime can be claimed'],
                null,
                null,
            ];
        }

        $available = count($regimeWindows);
        $positive = 0;
        $negative = 0;
        $top3Sum = 0.0;
        $avgSum = 0.0;

        foreach ($regimeWindows as $window) {
            if ($window['direction'] > 0) {
                $positive++;
            } elseif ($window['direction'] < 0) {
                $negative++;
            }

            $top3Sum += (float) $window['top3_net_norm'];
            $avgSum += (float) $window['avg_net_norm'];
        }

        $top3 = round($top3Sum / $available, 8);
        $avg = round($avgSum / $available, 8);
        $labels = implode('/', array_map(static fn (int $d): string => $d.'D', array_keys($regimeWindows)));

        if ($positive === $available && $top3 >= $profile->brokerStrongTop3Norm) {
            return [
                BrokerRegime::STRONG_ACCUMULATION,
                [sprintf('%s all positive with top-3 net %.4f of turnover', $labels, $top3)],
                $top3,
                $avg,
            ];
        }

        if ($negative === $available && $top3 <= -$profile->brokerStrongTop3Norm) {
            return [
                BrokerRegime::STRONG_DISTRIBUTION,
                [sprintf('%s all negative with top-3 net %.4f of turnover', $labels, $top3)],
                $top3,
                $avg,
            ];
        }

        if ($positive > $negative) {
            return [
                BrokerRegime::ACCUMULATION,
                [sprintf('%d of %d medium-term windows accumulating (%s)', $positive, $available, $labels)],
                $top3,
                $avg,
            ];
        }

        if ($negative > $positive) {
            return [
                BrokerRegime::DISTRIBUTION,
                [sprintf('%d of %d medium-term windows distributing (%s)', $negative, $available, $labels)],
                $top3,
                $avg,
            ];
        }

        return [
            BrokerRegime::NEUTRAL,
            [sprintf('medium-term windows give no consistent direction (%s)', $labels)],
            $top3,
            $avg,
        ];
    }

    /**
     * Short-term flow minus background flow.
     *
     * Positive means the recent sessions are accumulating faster than the
     * longer window's average -- flow is building rather than merely present.
     *
     * @param  array<int, array<string, mixed>>  $windows
     */
    private function acceleration(array $windows): ?float
    {
        if (count($windows) < 2) {
            return null;
        }

        $lengths = array_keys($windows);
        $shortest = min($lengths);
        $longest = max($lengths);

        return round(
            (float) $windows[$shortest]['avg_net_norm'] - (float) $windows[$longest]['avg_net_norm'],
            8,
        );
    }

    /**
     * How consistently the windows agree, on -1..1.
     *
     * The mean of the per-window directions: 1.0 is every window
     * accumulating, -1.0 every window distributing, 0 a mix.
     *
     * @param  array<int, array<string, mixed>>  $windows
     */
    private function consistency(array $windows): ?float
    {
        if ($windows === []) {
            return null;
        }

        $sum = 0;

        foreach ($windows as $window) {
            $sum += (int) $window['direction'];
        }

        return round($sum / count($windows), 4);
    }

    /**
     * How much of the window's net flow the top three brokers account for.
     *
     * Above 1.0 means the rest of the book was net selling into them, which
     * is the shape a single accumulating hand leaves behind.
     *
     * @param  array<string, mixed>  $window
     */
    private function concentration(array $window): ?float
    {
        $avg = (float) $window['avg_net_norm'];
        $top3 = (float) $window['top3_net_norm'];
        $brokers = (int) $window['broker_count'];

        if ($brokers <= 0) {
            return null;
        }

        $total = $avg * $brokers;

        if (abs($total) < 1.0e-12) {
            return null;
        }

        return round($top3 / $total, 6);
    }

    /**
     * Keep only the profile's windows, and give each one a direction.
     *
     * @param  array<int, array<string, mixed>>  $rowsByWindowDays
     * @return array<int, array<string, mixed>>
     */
    private function normalizeWindows(array $rowsByWindowDays, StrategyProfile $profile): array
    {
        $out = [];

        foreach ($profile->brokerWindows as $days) {
            $row = $rowsByWindowDays[$days] ?? null;

            if ($row === null) {
                continue;
            }

            $avg = (float) ($row['avg_net_norm'] ?? 0.0);

            $out[$days] = [
                'window_days' => $days,
                'avg_net_norm' => $avg,
                'top3_net_norm' => (float) ($row['top3_net_norm'] ?? 0.0),
                'accdist_score' => (int) ($row['accdist_score'] ?? 0),
                'broker_count' => (int) ($row['broker_count'] ?? 0),
                'value' => (float) ($row['value'] ?? 0.0),
                'covered_days' => isset($row['covered_days']) ? (int) $row['covered_days'] : null,
                'direction' => $this->direction($avg, $profile->brokerFlowEpsilon),
            ];
        }

        ksort($out);

        return $out;
    }

    /**
     * A window counts as directional only beyond the noise floor. Without the
     * epsilon a rounding-level net of 1e-9 would read as accumulation and
     * every window would be "positive".
     */
    private function direction(float $netNorm, float $epsilon): int
    {
        if ($netNorm >= $epsilon) {
            return 1;
        }

        if ($netNorm <= -$epsilon) {
            return -1;
        }

        return 0;
    }
}
