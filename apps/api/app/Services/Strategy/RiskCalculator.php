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
 *     above close and plausible, else close * (1 + min_target_pct)).
 */
class RiskCalculator
{
    public const ATR_MULTIPLE = 2.0;

    public const MIN_TARGET_PCT = 0.10;

    public const MIN_RISK_PCT = 0.005; // floor for risk distance to avoid divide-by-zero

    /**
     * How far above the close a 55-week high may sit and still be treated as
     * resistance.
     *
     * A long window legitimately reaches a high well above the current price:
     * a stock down 60% from its one-year high has a ratio of 2.5, and that
     * level is real. What it cannot legitimately reach is a multiple in the
     * hundreds -- that is a corporate action showing through unadjusted bars,
     * not a price anyone traded at in comparable terms.
     *
     * MLPT on 2026-09-02 is the worked example: a roughly 1:27 split on
     * 2026-05-21 left a pre-split 257,875 inside the 275-session window while
     * the stock traded at 1,260, a ratio of 205. The largest genuine drawdown
     * in the same universe was 5.3. Ten sits with a wide margin either side of
     * that gap, so it separates a bad bar from a bad year without needing to
     * be tuned.
     *
     * Rejecting the high does not repair the underlying series: `close_vs_high55`
     * is computed upstream and is still wrong for a split-contaminated symbol.
     * This only keeps an unusable number from becoming a trading target.
     */
    public const MAX_TARGET_MULTIPLE = 10.0;

    /**
     * Ceiling on the reported ratio, matching what `watchlist_scores.risk_reward`
     * can store (decimal(8,4)).
     *
     * The target check above already keeps the ratio far below this, so
     * reaching it means an input this class did not anticipate. Capping is
     * still better than the alternative: an out-of-range value aborts the
     * insert, and one symbol's bad row then costs every symbol ranked below it.
     */
    public const MAX_RISK_REWARD = 9999.9999;

    /**
     * Whether a long-window high can be treated as resistance for this price.
     *
     * Shared with ExecutionPlanner, which builds its target from the same
     * `high55w` and would otherwise reach the same unusable number by its own
     * route -- one rule, applied in both places, rather than two copies to
     * keep in step.
     */
    public static function isUsableTarget(
        ?float $high55,
        float $reference,
        float $maxTargetMultiple = self::MAX_TARGET_MULTIPLE
    ): bool {
        return $high55 !== null
            && $reference > 0
            && $high55 <= $reference * $maxTargetMultiple;
    }

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
        float $minTargetPct = self::MIN_TARGET_PCT,
        float $maxTargetMultiple = self::MAX_TARGET_MULTIPLE
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

        // A high this far above the close is not resistance; see
        // MAX_TARGET_MULTIPLE. Recorded rather than silently swapped, because
        // it is evidence of a data problem the reader needs to see.
        $rejectedHigh = null;

        if ($high55 !== null && ! self::isUsableTarget($high55, $close, $maxTargetMultiple)) {
            $rejectedHigh = $high55;
            $high55 = null;
        }

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

            if ($riskReward !== null) {
                $riskReward = min($riskReward, self::MAX_RISK_REWARD);
            }
        }

        return [
            'invalidation_level' => $invalidation === null ? null : round($invalidation, 4),
            'take_profit' => round($takeProfit, 4),
            'risk_reward' => $riskReward,
            'risk_notes' => $this->buildNotes($atr14, $atrStop, $swingLow, $high55, $invalidation, $riskReward, $rejectedHigh),
        ];
    }

    private function buildNotes(
        ?float $atr14,
        ?float $atrStop,
        ?float $swingLow,
        ?float $high55,
        ?float $invalidation,
        ?float $riskReward,
        ?float $rejectedHigh = null
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
        if ($rejectedHigh !== null) {
            $bits[] = sprintf(
                'high55 rejected: %s is more than %.0fx the close, which points at unadjusted bars rather than resistance',
                rtrim(rtrim(number_format($rejectedHigh, 4, '.', ''), '0'), '.'),
                self::MAX_TARGET_MULTIPLE,
            );
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
