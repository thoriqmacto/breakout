<?php

namespace App\Services\Strategy;

use App\Services\IdxTicks;

/**
 * What a price difference is actually worth after costs.
 *
 * A backtest that reports `(exit - entry) / entry` is reporting a property of
 * the chart, not of the account. On IDX the round trip costs roughly 0.4% in
 * brokerage alone before any slippage, which is an eighth of the +3% the
 * profit floor is designed to hold -- large enough that ignoring it turns a
 * marginal strategy into a good-looking one.
 *
 * Both figures are always produced. Gross is what the levels did; net is what
 * the trade would have returned. The gap between them is itself information:
 * a strategy whose edge disappears into costs is a strategy about costs.
 *
 * Slippage is applied as an adverse price adjustment on both sides rather
 * than as a fee, because that is what it is -- you buy a little higher and
 * sell a little lower than the level says. Tick rounding then puts both
 * prices back on the ladder the exchange actually quotes.
 */
class TradingCostModel
{
    public function __construct(private readonly StrategyProfile $profile) {}

    /**
     * The price a buy at this level really costs, before brokerage.
     */
    public function effectiveBuyPrice(float $price): float
    {
        $slipped = $price * (1.0 + $this->slippagePct() / 100.0);

        return $this->roundToTick($slipped, $price, ceil: true);
    }

    /**
     * The price a sell at this level really realises, before brokerage.
     */
    public function effectiveSellPrice(float $price): float
    {
        $slipped = $price * (1.0 - $this->slippagePct() / 100.0);

        return $this->roundToTick($slipped, $price, ceil: false);
    }

    /**
     * Price movement only. No costs, no slippage.
     */
    public function grossReturnPct(float $entryPrice, float $exitPrice): float
    {
        if ($entryPrice <= 0) {
            return 0.0;
        }

        return round((($exitPrice - $entryPrice) / $entryPrice) * 100.0, 4);
    }

    /**
     * What the round trip returns after slippage and both brokerage legs.
     */
    public function netReturnPct(float $entryPrice, float $exitPrice): float
    {
        if ($entryPrice <= 0) {
            return 0.0;
        }

        $cost = $this->netCostPerShare($entryPrice);
        $proceeds = $this->netProceedsPerShare($exitPrice);

        if ($cost <= 0) {
            return 0.0;
        }

        return round((($proceeds - $cost) / $cost) * 100.0, 4);
    }

    /**
     * Cash out per share on the way in: slipped price plus buy brokerage.
     */
    public function netCostPerShare(float $entryPrice): float
    {
        return $this->effectiveBuyPrice($entryPrice) * (1.0 + $this->buyFeePct() / 100.0);
    }

    /**
     * Cash in per share on the way out: slipped price less sell brokerage.
     */
    public function netProceedsPerShare(float $exitPrice): float
    {
        return $this->effectiveSellPrice($exitPrice) * (1.0 - $this->sellFeePct() / 100.0);
    }

    /**
     * The round-trip cost of a trade at this price, as a percentage.
     *
     * Reported next to the profit floor so the difference between "the stop
     * sits at +3%" and "you keep +3%" is visible rather than implied.
     *
     * Tick rounding is deliberately excluded here, unlike in a simulated
     * fill. This is an estimate at a price *level*, and rounding the same
     * nominal price up on the way in and down on the way out charges a full
     * tick spread that a limit order on both sides would not pay -- on a
     * 5-rupiah ladder at 1305 that alone reads as 0.8%, swamping the fees the
     * figure exists to show. Actual fills in the simulator keep the rounding,
     * because there the prices are different and the ladder is real.
     */
    public function roundTripCostPct(float $price): float
    {
        if ($price <= 0) {
            return 0.0;
        }

        $buy = $price * (1.0 + $this->slippagePct() / 100.0) * (1.0 + $this->buyFeePct() / 100.0);
        $sell = $price * (1.0 - $this->slippagePct() / 100.0) * (1.0 - $this->sellFeePct() / 100.0);

        return round((($buy - $sell) / $buy) * 100.0, 4);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'buy_fee_pct' => $this->buyFeePct(),
            'sell_fee_pct' => $this->sellFeePct(),
            'slippage_pct' => $this->slippagePct(),
            'round_to_tick' => (bool) $this->profile->costs['round_to_tick'],
        ];
    }

    private function buyFeePct(): float
    {
        return (float) $this->profile->costs['buy_fee_pct'];
    }

    private function sellFeePct(): float
    {
        return (float) $this->profile->costs['sell_fee_pct'];
    }

    private function slippagePct(): float
    {
        return (float) $this->profile->costs['slippage_pct'];
    }

    /**
     * Put a slipped price back on the exchange's tick ladder.
     *
     * Rounded away from the trade in both directions -- up on a buy, down on
     * a sell -- so tick rounding can never hand back part of the slippage it
     * was applied on top of. The reference price is the unslipped level, so
     * the ladder is the one that applied at the level being traded.
     */
    private function roundToTick(float $value, float $reference, bool $ceil): float
    {
        if (! $this->profile->costs['round_to_tick'] || $value <= 0) {
            return round($value, 4);
        }

        return $ceil
            ? IdxTicks::ceil($value, $reference)
            : IdxTicks::floor($value, $reference);
    }
}
