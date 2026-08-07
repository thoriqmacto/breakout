<?php

namespace App\Services\Strategy;

/**
 * Pure-PHP scoring service. Given the per-asset features assembled by
 * WatchlistRanker (technical metrics, broker rollups, latest bar), it
 * produces explainable sub-scores in the 0..100 range and a weighted
 * total. No DB access here; all inputs are explicit so the service is
 * deterministic and unit-testable.
 *
 * Design constraints (per the architect plan):
 *   - Avoid overfitting: all weights and thresholds are constants here
 *     and exposed in the public defaults() array so callers can audit.
 *   - Avoid claiming guaranteed profit: outputs are research watchlist
 *     scores, not trade signals.
 */
class StrategyScoringService
{
    public const VERSION = 'v1';

    public const WEIGHT_BAS = 0.45;

    public const WEIGHT_BCS = 0.35;

    public const WEIGHT_FILTERS = 0.20;

    /**
     * Weight contributions when both filters pass / partially pass.
     */
    private const FILTER_BONUS_LF = 50.0;

    private const FILTER_BONUS_RRF = 50.0;

    /**
     * BCS sub-score thresholds.
     */
    private const BCS_BREAKOUT_BONUS = 50.0;

    private const BCS_VOL_RATIO_THRESHOLD = 1.5;

    private const BCS_VOL_RATIO_BONUS = 25.0;

    private const BCS_CLOSE_POS_THRESHOLD = 0.7;

    private const BCS_CLOSE_POS_BONUS = 25.0;

    /**
     * @return array<string, float>
     */
    public function defaults(): array
    {
        return [
            'weight_bas' => self::WEIGHT_BAS,
            'weight_bcs' => self::WEIGHT_BCS,
            'weight_filters' => self::WEIGHT_FILTERS,
            'bcs_vol_ratio_threshold' => self::BCS_VOL_RATIO_THRESHOLD,
            'bcs_close_pos_threshold' => self::BCS_CLOSE_POS_THRESHOLD,
        ];
    }

    /**
     * Broker Accumulation Score (0..100).
     *
     * @param  array<int, array{avg_net_norm: float, top3_net_norm: float, accdist_score: int, window_days: int}>  $windows
     */
    public function brokerAccumulation(array $windows, ?int $sameDayPbas = null): array
    {
        if ($windows === []) {
            return [
                'score' => 0.0,
                'reasons' => ['no broker accumulation windows available'],
            ];
        }

        // Average avg_net_norm and top3_net_norm across windows, weighted
        // toward longer windows so a single hot day doesn't dominate.
        $weightSum = 0.0;
        $avgWeighted = 0.0;
        $top3Weighted = 0.0;
        $accdistTotal = 0;
        $reasons = [];

        foreach ($windows as $w) {
            $weight = max(1, (int) ($w['window_days'] ?? 1));
            $weightSum += $weight;
            $avgWeighted += $weight * (float) ($w['avg_net_norm'] ?? 0);
            $top3Weighted += $weight * (float) ($w['top3_net_norm'] ?? 0);
            $accdistTotal += (int) ($w['accdist_score'] ?? 0);

            $days = (int) ($w['window_days'] ?? 0);
            $avg = (float) ($w['avg_net_norm'] ?? 0);
            $reasons[] = sprintf('%dD avg_net_norm=%.4f top3=%.4f', $days, $avg, (float) ($w['top3_net_norm'] ?? 0));
        }

        $avg = $weightSum > 0 ? $avgWeighted / $weightSum : 0.0;
        $top3 = $weightSum > 0 ? $top3Weighted / $weightSum : 0.0;

        // Map normalized values to 0..100 with a soft cap at +/- 0.10 net
        // contribution to turnover (empirically generous).
        $base = 50.0 + (($avg + $top3) / 2) * 500.0;
        $base = max(0.0, min(100.0, $base));

        // Accdist agreement bonus / penalty up to +/- 10 points.
        $accdistBoost = max(-10.0, min(10.0, $accdistTotal * 5.0));
        $base = max(0.0, min(100.0, $base + $accdistBoost));

        if ($sameDayPbas !== null) {
            // PBAS in [0..100]; nudge BAS toward it with weight 0.15.
            $base = max(0.0, min(100.0, 0.85 * $base + 0.15 * $sameDayPbas));
            $reasons[] = sprintf('same-day PBAS=%d', $sameDayPbas);
        }

        return [
            'score' => round($base, 4),
            'reasons' => $reasons,
        ];
    }

    /**
     * Breakout Confirmation Score (0..100).
     */
    public function breakoutConfirmation(array $features): array
    {
        $score = 0.0;
        $reasons = [];

        $breakout = (bool) ($features['breakout20'] ?? false);
        $closePos = (float) ($features['close_pos'] ?? 0);
        $volRatio = (float) ($features['vol_ratio_20'] ?? 0);

        if ($breakout) {
            $score += self::BCS_BREAKOUT_BONUS;
            $reasons[] = 'close above prior 20D high';
        }

        if ($volRatio >= self::BCS_VOL_RATIO_THRESHOLD) {
            $score += self::BCS_VOL_RATIO_BONUS;
            $reasons[] = sprintf('volume %.2fx 20D average', $volRatio);
        } elseif ($volRatio >= 1.0) {
            // Half credit for above-average volume short of the threshold.
            $score += self::BCS_VOL_RATIO_BONUS / 2;
            $reasons[] = sprintf('volume %.2fx 20D average (below %.1fx threshold)', $volRatio, self::BCS_VOL_RATIO_THRESHOLD);
        }

        if ($closePos >= self::BCS_CLOSE_POS_THRESHOLD) {
            $score += self::BCS_CLOSE_POS_BONUS;
            $reasons[] = sprintf('close in upper %.0f%% of range', (1.0 - self::BCS_CLOSE_POS_THRESHOLD) * 100);
        }

        return [
            'score' => round(min(100.0, $score), 4),
            'reasons' => $reasons,
        ];
    }

    /**
     * Liquidity Filter — a hard pass/fail on tradability.
     *
     * @return array{pass: bool, reason: string}
     */
    public function liquidityFilter(float $turnoverValue, int $activeBrokerCount, float $minTurnover, int $minBrokers = 5): array
    {
        if ($turnoverValue < $minTurnover) {
            return [
                'pass' => false,
                'reason' => sprintf('turnover %s below %s', $this->humanInt($turnoverValue), $this->humanInt($minTurnover)),
            ];
        }
        if ($activeBrokerCount < $minBrokers) {
            return [
                'pass' => false,
                'reason' => sprintf('only %d active brokers (need >= %d)', $activeBrokerCount, $minBrokers),
            ];
        }

        return [
            'pass' => true,
            'reason' => sprintf('turnover %s, %d brokers', $this->humanInt($turnoverValue), $activeBrokerCount),
        ];
    }

    /**
     * Risk/Reward Filter.
     *
     * @return array{pass: bool, reason: string}
     */
    public function riskRewardFilter(?float $riskReward, float $minRiskReward = 2.0): array
    {
        if ($riskReward === null) {
            return ['pass' => false, 'reason' => 'risk/reward unavailable'];
        }
        if ($riskReward < $minRiskReward) {
            return [
                'pass' => false,
                'reason' => sprintf('R/R=%.2f below minimum %.2f', $riskReward, $minRiskReward),
            ];
        }

        return [
            'pass' => true,
            'reason' => sprintf('R/R=%.2f', $riskReward),
        ];
    }

    /**
     * Combine sub-scores into a final 0..100 with explainable contribution.
     *
     * @return array{score_total: float, contribution: array<string, float>}
     */
    public function total(float $bas, float $bcs, bool $lfPass, bool $rrfPass): array
    {
        $filterPoints = ($lfPass ? self::FILTER_BONUS_LF : 0.0) + ($rrfPass ? self::FILTER_BONUS_RRF : 0.0);

        $contribution = [
            'bas' => round(self::WEIGHT_BAS * $bas, 4),
            'bcs' => round(self::WEIGHT_BCS * $bcs, 4),
            'filters' => round(self::WEIGHT_FILTERS * $filterPoints, 4),
        ];

        $total = array_sum($contribution);

        return [
            'score_total' => round(max(0.0, min(100.0, $total)), 4),
            'contribution' => $contribution,
        ];
    }

    private function humanInt(float $v): string
    {
        if ($v >= 1_000_000_000) {
            return sprintf('%.2fB', $v / 1_000_000_000);
        }
        if ($v >= 1_000_000) {
            return sprintf('%.2fM', $v / 1_000_000);
        }
        if ($v >= 1_000) {
            return sprintf('%.2fK', $v / 1_000);
        }

        return number_format($v, 0);
    }
}
