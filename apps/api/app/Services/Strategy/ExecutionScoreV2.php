<?php

namespace App\Services\Strategy;

use App\Services\Analysis\AssetTechnicalSnapshot;

/**
 * The execution-v2 score, and the sentences behind it.
 *
 * v1 produced one number from three inputs (BAS, BCS, filters) and a list of
 * strings that described the inputs rather than the decision. This keeps the
 * same shape -- 0..100, weighted, no fitting -- and changes two things that
 * matter:
 *
 *   Broker flow is scored as four separate questions. Persistence (for how
 *   long), strength (how much), and acceleration (is it building) are
 *   different properties, and averaging them into one BAS is how a single hot
 *   session outranks months of steady accumulation.
 *
 *   Historical outcome is a component. A setup whose comparable population
 *   reached +5% before its stop 64% of the time is a different proposition
 *   from one at 38%, and nothing in v1 could express that. When the sample is
 *   too thin the component scores a neutral 50 rather than zero -- absence of
 *   evidence is not evidence of a bad setup, and scoring it as one would
 *   systematically bury every setup type the database has not seen much of.
 *
 * Every component returns a 0..100 value and a reason. The reasons are the
 * product: a score nobody can take apart is a number to argue with rather
 * than think with.
 *
 * Pure PHP, deterministic, no IO.
 */
class ExecutionScoreV2
{
    /**
     * @param  array<string, mixed>  $plan  from ExecutionPlanner::planForProfile()
     * @param  array<string, mixed>  $historical  from OutcomeProbabilityService
     * @return array{
     *   score: float,
     *   components: array<string, array{value: float, weight: float, contribution: float, reason: string}>,
     *   reasons: array<string, array<int, string>>,
     * }
     */
    public function score(
        BrokerFlowAssessment $flow,
        AssetTechnicalSnapshot $snapshot,
        array $plan,
        array $historical,
        ?float $turnoverValue,
        ?int $activeBrokers,
        StrategyProfile $profile,
    ): array {
        $components = [
            'broker_persistence' => $this->brokerPersistence($flow),
            'broker_strength' => $this->brokerStrength($flow, $profile),
            'broker_acceleration' => $this->brokerAcceleration($flow),
            'breakout_confirmation' => $this->breakoutConfirmation($snapshot),
            'volume_confirmation' => $this->volumeConfirmation($snapshot, $profile),
            'trend_quality' => $this->trendQuality($snapshot),
            'liquidity' => $this->liquidity($turnoverValue, $activeBrokers, $profile),
            'risk_quality' => $this->riskQuality($plan, $profile),
            'historical_outcome' => $this->historicalOutcome($historical),
        ];

        $total = 0.0;
        $detailed = [];

        foreach ($components as $name => $component) {
            $weight = (float) ($profile->scoreWeights[$name] ?? 0.0);
            $contribution = round($weight * $component['value'], 4);
            $total += $contribution;

            $detailed[$name] = [
                'value' => round($component['value'], 4),
                'weight' => $weight,
                'contribution' => $contribution,
                'reason' => $component['reason'],
            ];
        }

        return [
            'score' => round(max(0.0, min(100.0, $total)), 4),
            'components' => $detailed,
            'reasons' => $this->groupReasons($flow, $snapshot, $plan, $historical, $detailed),
        ];
    }

    /**
     * How many of the available windows point the same way.
     *
     * Scored on the persistence ratio directly, so four of four is 100 and two
     * of four is 50. Deliberately linear: there is no evidence for a curve
     * here, and inventing one would be fitting without data.
     *
     * @return array{value: float, reason: string}
     */
    private function brokerPersistence(BrokerFlowAssessment $flow): array
    {
        if (! $flow->hasAnyWindow() || $flow->persistenceRatio === null) {
            return ['value' => 0.0, 'reason' => 'no broker windows available'];
        }

        return [
            'value' => $flow->persistenceRatio * 100.0,
            'reason' => sprintf(
                '%d of %d broker windows positive',
                $flow->positiveWindows,
                $flow->availableWindows,
            ),
        ];
    }

    /**
     * How concentrated the medium-term buying is, mapped onto 0..100 with 50
     * as flat.
     *
     * @return array{value: float, reason: string}
     */
    private function brokerStrength(BrokerFlowAssessment $flow, StrategyProfile $profile): array
    {
        if ($flow->top3NetNorm === null) {
            return ['value' => 50.0, 'reason' => 'no medium-term broker window to measure strength from'];
        }

        $scale = max($profile->brokerStrongTop3Norm, 1.0e-6);
        $value = 50.0 + ($flow->top3NetNorm / $scale) * 50.0;

        return [
            'value' => max(0.0, min(100.0, $value)),
            'reason' => sprintf('top-3 broker net %.4f of turnover (%s)', $flow->top3NetNorm, $flow->regime),
        ];
    }

    /**
     * Whether the short window is running ahead of the background one.
     *
     * @return array{value: float, reason: string}
     */
    private function brokerAcceleration(BrokerFlowAssessment $flow): array
    {
        if ($flow->acceleration === null) {
            return ['value' => 50.0, 'reason' => 'not enough windows to measure acceleration'];
        }

        // The same normalisation scale as strength, so a "strong" amount of
        // acceleration and a "strong" amount of flow mean the same thing.
        $value = 50.0 + ($flow->acceleration / 0.05) * 50.0;

        return [
            'value' => max(0.0, min(100.0, $value)),
            'reason' => $flow->acceleration >= 0
                ? sprintf('short-term flow %.4f above the background window', $flow->acceleration)
                : sprintf('short-term flow %.4f below the background window', abs($flow->acceleration)),
        ];
    }

    /**
     * What level, if any, the close cleared, and how close it is to one.
     *
     * @return array{value: float, reason: string}
     */
    private function breakoutConfirmation(AssetTechnicalSnapshot $snapshot): array
    {
        if ($snapshot->isBreakout55()) {
            return ['value' => 100.0, 'reason' => sprintf('close cleared the 55-session high (%.4f)', (float) $snapshot->priorHigh55)];
        }

        if ($snapshot->isBreakout20()) {
            return ['value' => 80.0, 'reason' => sprintf('close cleared the 20-session high (%.4f)', (float) $snapshot->priorHigh20)];
        }

        $distance = $snapshot->distanceToBreakoutAtr();

        if ($distance === null) {
            return ['value' => 0.0, 'reason' => 'no breakout reference available'];
        }

        // Approaching the level is worth something, and worth less the
        // further away it is. Zero beyond two ATR.
        $value = max(0.0, 50.0 * (1.0 - $distance / 2.0));

        return [
            'value' => $value,
            'reason' => sprintf('%.2f ATR below the 20-session high', $distance),
        ];
    }

    /**
     * @return array{value: float, reason: string}
     */
    private function volumeConfirmation(AssetTechnicalSnapshot $snapshot, StrategyProfile $profile): array
    {
        $ratio = $snapshot->volRatio20;

        if ($ratio === null) {
            return ['value' => 0.0, 'reason' => 'no 20-session volume average available'];
        }

        $closePos = $snapshot->closePos;

        $volumeScore = match (true) {
            $ratio >= $profile->preferredVolumeRatio => 70.0,
            $ratio >= $profile->minVolumeRatio => 50.0,
            $ratio >= 1.0 => 30.0,
            default => 10.0,
        };

        $positionScore = ($closePos !== null && $closePos >= $profile->minClosePosition) ? 30.0 : 0.0;

        return [
            'value' => $volumeScore + $positionScore,
            'reason' => $closePos === null
                ? sprintf('volume %.2fx the 20-session average', $ratio)
                : sprintf(
                    'volume %.2fx the 20-session average, close in the upper %.0f%% of the range',
                    $ratio,
                    (1.0 - $closePos) * 100.0,
                ),
        ];
    }

    /**
     * @return array{value: float, reason: string}
     */
    private function trendQuality(AssetTechnicalSnapshot $snapshot): array
    {
        $value = 0.0;
        $bits = [];

        if ($snapshot->uptrend) {
            $value += 40.0;
            $bits[] = 'above the 150-session average';
        } else {
            $bits[] = 'below the 150-session average';
        }

        if ($snapshot->aboveEma20() === true) {
            $value += 30.0;
            $bits[] = 'above EMA20';
        }

        if ($snapshot->emaAligned() === true) {
            $value += 30.0;
            $bits[] = 'EMA20 above EMA50';
        }

        return ['value' => $value, 'reason' => implode(', ', $bits)];
    }

    /**
     * @return array{value: float, reason: string}
     */
    private function liquidity(?float $turnoverValue, ?int $activeBrokers, StrategyProfile $profile): array
    {
        if ($turnoverValue === null || $activeBrokers === null) {
            return ['value' => 0.0, 'reason' => 'no liquidity data for this session'];
        }

        if ($turnoverValue < $profile->minTurnoverValue) {
            return [
                'value' => 0.0,
                'reason' => sprintf('turnover %.2fB below the %.2fB floor', $turnoverValue / 1e9, $profile->minTurnoverValue / 1e9),
            ];
        }

        if ($activeBrokers < $profile->minActiveBrokers) {
            return [
                'value' => 25.0,
                'reason' => sprintf('only %d active brokers (need %d)', $activeBrokers, $profile->minActiveBrokers),
            ];
        }

        // Comfortably clearing the floor is worth more than scraping it, but
        // the ceiling arrives quickly: past a point more turnover does not
        // make a position any easier to leave.
        $headroom = min(1.0, $turnoverValue / max($profile->minTurnoverValue * 3.0, 1.0));

        return [
            'value' => 60.0 + 40.0 * $headroom,
            'reason' => sprintf('turnover %.2fB across %d brokers', $turnoverValue / 1e9, $activeBrokers),
        ];
    }

    /**
     * Tighter stops score higher, and a rejected plan scores nothing.
     *
     * @param  array<string, mixed>  $plan
     * @return array{value: float, reason: string}
     */
    private function riskQuality(array $plan, StrategyProfile $profile): array
    {
        $risk = $plan['initial_risk_pct'] ?? null;

        if ($risk === null) {
            return ['value' => 0.0, 'reason' => 'no measurable initial risk'];
        }

        if (($plan['valid'] ?? false) === false) {
            return [
                'value' => 0.0,
                'reason' => sprintf('initial risk %.2f%% above the %.2f%% ceiling', $risk, $profile->maxInitialRiskPct),
            ];
        }

        $ceiling = max($profile->maxInitialRiskPct, 1.0e-6);
        $value = max(0.0, min(100.0, (1.0 - $risk / $ceiling) * 100.0));

        return [
            'value' => $value,
            'reason' => sprintf('initial risk %.2f%% against a %.2f%% ceiling', $risk, $profile->maxInitialRiskPct),
        ];
    }

    /**
     * The empirical component.
     *
     * A thin sample scores a neutral 50, never zero. Scoring the unknown as
     * bad would systematically suppress every setup type the database happens
     * not to have seen much of, which says more about the data than about the
     * setup.
     *
     * @param  array<string, mixed>  $historical
     * @return array{value: float, reason: string}
     */
    private function historicalOutcome(array $historical): array
    {
        $probability = $historical['probability_hit_5_before_stop'] ?? null;

        if (($historical['status'] ?? null) !== 'OK' || $probability === null) {
            return [
                'value' => 50.0,
                'reason' => sprintf(
                    'insufficient comparable history (%d of %d needed); scored neutral',
                    (int) ($historical['sample_size'] ?? 0),
                    (int) ($historical['minimum_sample'] ?? 0),
                ),
            ];
        }

        return [
            'value' => max(0.0, min(100.0, (float) $probability * 100.0)),
            'reason' => sprintf(
                '%.1f%% of %d comparable setups reached +5%% before their stop',
                (float) $probability * 100.0,
                (int) $historical['sample_size'],
            ),
        ];
    }

    /**
     * The explanation payload, grouped the way the interface reads it.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $historical
     * @param  array<string, array<string, mixed>>  $components
     * @return array<string, array<int, string>>
     */
    private function groupReasons(
        BrokerFlowAssessment $flow,
        AssetTechnicalSnapshot $snapshot,
        array $plan,
        array $historical,
        array $components,
    ): array {
        $broker = [
            $components['broker_persistence']['reason'],
            $components['broker_strength']['reason'],
            $components['broker_acceleration']['reason'],
        ];

        foreach ($flow->reasons as $reason) {
            if (! in_array($reason, $broker, true)) {
                $broker[] = $reason;
            }
        }

        $price = [
            $components['breakout_confirmation']['reason'],
            $components['volume_confirmation']['reason'],
            $components['trend_quality']['reason'],
        ];

        if ($snapshot->compression === true) {
            $price[] = 'volatility compressing into the level';
        }

        $risk = [$components['risk_quality']['reason'], $components['liquidity']['reason']];

        if (($plan['initial_stop_source'] ?? null) !== null) {
            $risk[] = 'stop taken from '.$plan['initial_stop_source'];
        }

        $history = [$components['historical_outcome']['reason']];

        if (($historical['status'] ?? null) === 'OK') {
            $history[] = sprintf('comparable population: %s', (string) $historical['bucket_label']);

            if ($historical['median_days_to_5'] !== null) {
                $history[] = sprintf('median %.0f trading days to +5%%', (float) $historical['median_days_to_5']);
            }

            if (($historical['match'] ?? null) === OutcomeProbabilityService::MATCH_COARSE) {
                $history[] = 'exact setup bucket was too thin, so a wider comparable population was used';
            }
        }

        return [
            'broker' => array_values(array_filter($broker)),
            'price' => array_values(array_filter($price)),
            'risk' => array_values(array_filter($risk)),
            'history' => array_values(array_filter($history)),
        ];
    }
}
