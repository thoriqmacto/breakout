<?php

namespace App\Services\Strategy;

/**
 * Pure-PHP risk math: derives an invalidation level (stop), a take-profit
 * target, and the resulting risk/reward ratio from technical inputs.
 * No DB access; deterministic and unit-testable.
 *
 * Conventions:
 *   - Long-only watchlist: invalidation < close < take_profit.
 *   - Stop = max(swing_low, close - atr_multiple * atr14). Pick the
 *     tighter of the two (higher value) so a bigger ATR doesn't mask a
 *     well-defined recent low.
 *   - Take-profit = nearest meaningful resistance (defaults to high55 if
 *     above close, else close * (1 + min_target_pct)).
 */
class RiskCalculator
{
    public const ATR_MULTIPLE = 2.0;

    public const MIN_TARGET_PCT = 0.10;

    public const MIN_RISK_PCT = 0.005; // floor for risk distance to avoid divide-by-zero

    /**
     * @return array{
     *   invalidation_level: ?float,
     *   take_profit: ?float,
     *   risk_reward: ?float,
     *   risk_notes: string,
     * }
     */
    public function compute(
        float $close,
        ?float $atr14,
        ?float $swingLow,
        ?float $high55,
        float $atrMultiple = self::ATR_MULTIPLE,
        float $minTargetPct = self::MIN_TARGET_PCT
    ): array {
        if ($close <= 0) {
            return [
                'invalidation_level' => null,
                'take_profit' => null,
                'risk_reward' => null,
                'risk_notes' => 'invalid close price',
            ];
        }

        $atrStop = ($atr14 !== null && $atr14 > 0)
            ? $close - ($atrMultiple * $atr14)
            : null;
        $candidates = array_filter([$atrStop, $swingLow], static fn ($v) => $v !== null && $v > 0 && $v < $close);

        $invalidation = empty($candidates) ? null : max($candidates);

        $takeProfit = ($high55 !== null && $high55 > $close)
            ? $high55
            : $close * (1.0 + $minTargetPct);

        $riskReward = null;
        if ($invalidation !== null) {
            $risk = $close - $invalidation;
            $reward = $takeProfit - $close;
            $minRisk = $close * self::MIN_RISK_PCT;
            if ($risk < $minRisk) {
                $risk = $minRisk;
            }
            $riskReward = $risk > 0 ? round($reward / $risk, 4) : null;
        }

        return [
            'invalidation_level' => $invalidation === null ? null : round($invalidation, 4),
            'take_profit' => round($takeProfit, 4),
            'risk_reward' => $riskReward,
            'risk_notes' => $this->buildNotes($atr14, $atrStop, $swingLow, $high55, $invalidation, $riskReward),
        ];
    }

    private function buildNotes(
        ?float $atr14,
        ?float $atrStop,
        ?float $swingLow,
        ?float $high55,
        ?float $invalidation,
        ?float $riskReward
    ): string {
        $bits = [];
        if ($atr14 !== null) {
            $bits[] = sprintf('ATR14=%.4f', $atr14);
        }
        if ($atrStop !== null) {
            $bits[] = sprintf('ATR-stop=%.4f', $atrStop);
        }
        if ($swingLow !== null) {
            $bits[] = sprintf('swing-low=%.4f', $swingLow);
        }
        if ($high55 !== null) {
            $bits[] = sprintf('high55=%.4f', $high55);
        }
        if ($invalidation === null) {
            $bits[] = 'no valid invalidation level';
        }
        if ($riskReward !== null) {
            $bits[] = sprintf('R/R=%.2f', $riskReward);
        }

        return implode(' | ', $bits);
    }
}
