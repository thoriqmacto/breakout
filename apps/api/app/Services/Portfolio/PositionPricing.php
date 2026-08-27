<?php

namespace App\Services\Portfolio;

/**
 * The one place the ledger's fee and effective-price arithmetic lives.
 *
 * Two callers need it and they know different things. The manual form knows a
 * fee *percentage* and derives the money from it. A broker import knows the
 * exact money the broker charged and must not re-derive it from a rounded
 * percentage -- doing so would put a cost basis in the database that disagrees
 * with the contract note by a few rupiah, and every downstream average and
 * realized P/L would inherit the drift.
 *
 * So the rule is: an explicitly supplied fee_value wins, and the rate becomes
 * the derived, descriptive figure. Where only a rate is given, the behaviour is
 * byte-identical to what the manual workflow did before this class existed:
 *
 *     fee_value = qty * price * rate/100
 *     avg_price = (qty * price + fee_value) / qty      (entry)
 *               = price * (1 + rate/100)
 *
 * which is exactly the old `price * feeMultiplier`.
 */
class PositionPricing
{
    public const SIDE_ENTRY = 'entry';

    public const SIDE_EXIT = 'exit';

    /**
     * Resolve fee_rate, fee_value and avg_price for one ledger row.
     *
     * @param  float|null  $feeRate  Percentage, e.g. 0.15 for 0.15%.
     * @param  float|null  $feeValue  Exact money. Wins over $feeRate when given.
     * @return array{fee_rate: float, fee_value: float, avg_price: float}
     */
    public function normalize(
        string $side,
        float $qty,
        float $price,
        ?float $feeRate = null,
        ?float $feeValue = null,
    ): array {
        $side = strtolower($side) === self::SIDE_EXIT ? self::SIDE_EXIT : self::SIDE_ENTRY;
        $gross = $qty * $price;

        if ($feeValue !== null) {
            $resolvedFee = $feeValue;
            // Descriptive only. A zero-value trade has no meaningful rate, and
            // reporting one would invite dividing by it later.
            $resolvedRate = $gross > 0 ? ($resolvedFee / $gross) * 100.0 : 0.0;
        } else {
            $resolvedRate = $feeRate ?? 0.0;
            $resolvedFee = $gross * ($resolvedRate / 100.0);
        }

        return [
            'fee_rate' => $resolvedRate,
            'fee_value' => $resolvedFee,
            'avg_price' => $this->effectiveUnitPrice($side, $qty, $price, $resolvedFee),
        ];
    }

    /**
     * The per-share price the trade actually happened at once the fee is
     * included: what an entry really cost, or what an exit really returned.
     */
    public function effectiveUnitPrice(string $side, float $qty, float $price, float $feeValue): float
    {
        if ($qty <= 0) {
            return $price;
        }

        $gross = $qty * $price;

        return strtolower($side) === self::SIDE_EXIT
            ? ($gross - $feeValue) / $qty
            : ($gross + $feeValue) / $qty;
    }

    /**
     * Cash the trade moves: what an entry costs to settle, or what an exit
     * nets. Stockbit calls this `netamount`, and the importer checks its own
     * arithmetic against it.
     */
    public function netAmount(string $side, float $qty, float $price, float $feeValue): float
    {
        $gross = $qty * $price;

        return strtolower($side) === self::SIDE_EXIT
            ? $gross - $feeValue
            : $gross + $feeValue;
    }
}
