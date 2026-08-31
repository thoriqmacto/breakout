<?php

namespace App\Services\Execution;

use App\Services\Analysis\AssetTechnicalSnapshot;
use App\Services\IdxTicks;

/**
 * Turns a completed session into a plan for the next one.
 *
 * The rule this exists to enforce is that a signal computed from session T's
 * closing bar cannot be executed at session T's close. That price is gone by
 * the time the signal exists. So the plan names a level, derived entirely from
 * information available at T, that the next session must actually reach before
 * anything happens -- and every risk figure is measured from that level rather
 * than from the close.
 *
 * The distinction matters more than it sounds. A setup can clear a 2.0
 * risk/reward comfortably measured from Friday's close and fail it measured
 * from the price you would really pay on Monday, because the trigger sits
 * above the close while the stop does not move. Reporting the first number and
 * calling the setup ready is how a screen looks better than the account.
 *
 * Pure PHP: no database, no clock, deterministic.
 */
class ExecutionPlanner
{
    /**
     * Reward floor when the 55-week high is not above the trigger.
     *
     * Mirrors RiskCalculator::MIN_TARGET_PCT so a breakout that has already
     * cleared its own 55-week high is measured the same way the watchlist
     * measures one that has not.
     */
    public const MIN_TARGET_PCT = 0.10;

    /**
     * Floor on the risk distance, as a fraction of the trigger.
     *
     * Without it a stop a rupiah below the trigger produces an enormous
     * risk/reward out of arithmetic rather than out of the setup.
     */
    public const MIN_RISK_PCT = 0.005;

    /**
     * @param  float|null  $stop  Invalidation level, as computed at T.
     * @return array{
     *   entry_trigger: ?float,
     *   entry_reference: ?float,
     *   entry_reason: string,
     *   stop: ?float,
     *   target: ?float,
     *   risk_per_share: ?float,
     *   risk_reward: ?float,
     *   valid: bool,
     *   notes: array<int, string>,
     * }
     */
    public function plan(AssetTechnicalSnapshot $snapshot, ?float $stop, float $minTargetPct = self::MIN_TARGET_PCT): array
    {
        $notes = [];

        // The level the next session has to clear: the higher of the session's
        // own high and the twenty-session high it was measured against. Both
        // are known at T, and taking the higher means the trigger is always
        // above everything that traded during the signal session -- so filling
        // there is a continuation, never a retrospective fill at a price the
        // signal itself caused.
        $reference = $snapshot->priorHigh20 === null
            ? $snapshot->high
            : max($snapshot->high, $snapshot->priorHigh20);

        if ($reference <= 0) {
            return $this->invalid('no usable reference price in the signal session');
        }

        $trigger = IdxTicks::round($reference + IdxTicks::tickFor($reference), $reference);

        $reason = $snapshot->priorHigh20 !== null && $snapshot->priorHigh20 >= $snapshot->high
            ? sprintf('one tick above the 20-session high (%.4f)', $snapshot->priorHigh20)
            : sprintf('one tick above the signal session high (%.4f)', $snapshot->high);

        if ($stop === null || $stop <= 0) {
            $notes[] = 'no valid invalidation level, so no risk can be measured';

            return [
                'entry_trigger' => $trigger,
                'entry_reference' => round($reference, 4),
                'entry_reason' => $reason,
                'stop' => null,
                'target' => null,
                'risk_per_share' => null,
                'risk_reward' => null,
                'valid' => false,
                'notes' => $notes,
            ];
        }

        if ($stop >= $trigger) {
            $notes[] = sprintf('invalidation (%.4f) is at or above the entry trigger (%.4f)', $stop, $trigger);

            return [
                'entry_trigger' => $trigger,
                'entry_reference' => round($reference, 4),
                'entry_reason' => $reason,
                'stop' => round($stop, 4),
                'target' => null,
                'risk_per_share' => null,
                'risk_reward' => null,
                'valid' => false,
                'notes' => $notes,
            ];
        }

        // The same target rule the watchlist uses, applied at the trigger: the
        // 55-week high when it is still overhead, otherwise a fixed percentage
        // of room. Applying it at the close instead would let a breakout that
        // has already cleared its 55-week high claim the high itself as a
        // target that the entry has already passed.
        $target = ($snapshot->high55w !== null && $snapshot->high55w > $trigger)
            ? $snapshot->high55w
            : $trigger * (1.0 + $minTargetPct);

        if ($snapshot->high55w !== null && $snapshot->high55w <= $trigger) {
            $notes[] = sprintf('55-week high (%.4f) is below the trigger; target set %.0f%% above it', $snapshot->high55w, $minTargetPct * 100);
        }

        $risk = $trigger - $stop;
        $minRisk = $trigger * self::MIN_RISK_PCT;

        if ($risk < $minRisk) {
            $notes[] = sprintf('risk distance floored at %.2f%% of the trigger', self::MIN_RISK_PCT * 100);
            $risk = $minRisk;
        }

        $riskReward = round(($target - $trigger) / $risk, 4);

        return [
            'entry_trigger' => $trigger,
            'entry_reference' => round($reference, 4),
            'entry_reason' => $reason,
            'stop' => round($stop, 4),
            'target' => round($target, 4),
            'risk_per_share' => round($risk, 4),
            'risk_reward' => $riskReward,
            'valid' => true,
            'notes' => $notes,
        ];
    }

    /**
     * What an actual fill at $fillPrice does to the plan.
     *
     * Used both by the backtester, where the fill is simulated from the next
     * session's bar, and by the workspace, where it answers "the open gapped;
     * is this still the trade I planned?". A setup can be perfectly good and
     * still be a bad entry, and the only honest way to say so is to remeasure.
     *
     * @param  array<string, mixed>  $plan
     * @return array{
     *   fill_price: float,
     *   risk_per_share: ?float,
     *   risk_reward: ?float,
     *   gap_pct: ?float,
     *   passes: bool,
     *   reason: string,
     * }
     */
    public function evaluateFill(array $plan, float $fillPrice, float $minRiskReward, ?float $maxGapPct = null): array
    {
        $trigger = $plan['entry_trigger'] ?? null;
        $stop = $plan['stop'] ?? null;
        $target = $plan['target'] ?? null;

        $gapPct = ($trigger !== null && $trigger > 0)
            ? round(($fillPrice - $trigger) / $trigger, 6)
            : null;

        if ($stop === null || $target === null || $fillPrice <= 0) {
            return [
                'fill_price' => round($fillPrice, 4),
                'risk_per_share' => null,
                'risk_reward' => null,
                'gap_pct' => $gapPct,
                'passes' => false,
                'reason' => 'no complete plan to measure the fill against',
            ];
        }

        if ($fillPrice <= $stop) {
            return [
                'fill_price' => round($fillPrice, 4),
                'risk_per_share' => null,
                'risk_reward' => null,
                'gap_pct' => $gapPct,
                'passes' => false,
                'reason' => 'fill is at or below the invalidation level',
            ];
        }

        if ($maxGapPct !== null && $gapPct !== null && $gapPct > $maxGapPct) {
            return [
                'fill_price' => round($fillPrice, 4),
                'risk_per_share' => null,
                'risk_reward' => null,
                'gap_pct' => $gapPct,
                'passes' => false,
                'reason' => sprintf('open gapped %.2f%% beyond the trigger, past the %.2f%% guard', $gapPct * 100, $maxGapPct * 100),
            ];
        }

        $risk = $fillPrice - $stop;
        $minRisk = $fillPrice * self::MIN_RISK_PCT;

        if ($risk < $minRisk) {
            $risk = $minRisk;
        }

        $riskReward = round(($target - $fillPrice) / $risk, 4);

        if ($riskReward < $minRiskReward) {
            return [
                'fill_price' => round($fillPrice, 4),
                'risk_per_share' => round($risk, 4),
                'risk_reward' => $riskReward,
                'gap_pct' => $gapPct,
                'passes' => false,
                'reason' => sprintf('R/R at the fill is %.2f, below the %.2f minimum', $riskReward, $minRiskReward),
            ];
        }

        return [
            'fill_price' => round($fillPrice, 4),
            'risk_per_share' => round($risk, 4),
            'risk_reward' => $riskReward,
            'gap_pct' => $gapPct,
            'passes' => true,
            'reason' => sprintf('R/R at the fill is %.2f', $riskReward),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invalid(string $note): array
    {
        return [
            'entry_trigger' => null,
            'entry_reference' => null,
            'entry_reason' => $note,
            'stop' => null,
            'target' => null,
            'risk_per_share' => null,
            'risk_reward' => null,
            'valid' => false,
            'notes' => [$note],
        ];
    }
}
