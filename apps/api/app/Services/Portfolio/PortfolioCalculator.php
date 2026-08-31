<?php

namespace App\Services\Portfolio;

use App\Models\CashMovement;
use App\Models\Portfolio;
use App\Models\Position;
use Illuminate\Support\Collection;

/**
 * Pure-PHP calculator that turns a Portfolio's positions + cash movements
 * into an explainable summary: holdings (running average cost, market
 * value, unrealized P/L), realized P/L from matched exits, available cash,
 * total equity, and allocation breakdowns by symbol and sector.
 *
 * Available cash is three terms:
 *
 *     base cash                what the portfolio opened with
 *   + non-trade movements      deposits, withdrawals, dividends, standalone
 *                              fees, manual adjustments
 *   + signed trade settlements -(qty*price + fee) per entry,
 *                              +(qty*price - fee) per exit
 *   = available cash
 *
 * The third term is the one that was missing. Cash was `base + movements`
 * only, so selling a holding correctly reduced the position and booked a
 * realized gain while the money never appeared -- and buying one never cost
 * anything. Total equity was wrong by the whole traded amount in both
 * directions.
 *
 * Trade settlement is *derived* from the positions ledger rather than mirrored
 * into synthetic cash_movement rows. That makes editing or deleting a position
 * self-correcting: there is no second copy to keep in step, and no way for the
 * two to disagree. cash_movements keeps its own job -- money that moves for
 * reasons other than a trade.
 *
 * Realized P/L is reported separately and is never added to cash. An exit's
 * proceeds already contain the gain or loss; adding it again would count it
 * twice.
 *
 * No DB writes happen here. Callers eager-load positions.asset.latestPriceRecord
 * and cashMovements before invoking compute().
 */
class PortfolioCalculator
{
    public function __construct(private readonly PositionPricing $pricing) {}

    /**
     * Build the full summary payload for a portfolio.
     *
     * @return array{
     *   portfolio_id: int,
     *   base_ccy: string,
     *   base_cash_balance: float,
     *   non_trade_cash_flow: float,
     *   trade_cash_flow: float,
     *   cash_balance: float,
     *   cash_breakdown: array<string, float>,
     *   total_market_value: float,
     *   total_equity: float,
     *   realized_pl: float,
     *   unrealized_pl: float,
     *   holdings: array<int, array<string, mixed>>,
     *   allocation_by_symbol: array<int, array<string, mixed>>,
     *   allocation_by_sector: array<int, array<string, mixed>>,
     * }
     */
    public function compute(Portfolio $portfolio): array
    {
        $positions = $portfolio->relationLoaded('positions')
            ? $portfolio->positions
            : $portfolio->positions()->with('asset.latestPriceRecord')->get();

        $cashMovements = $portfolio->relationLoaded('cashMovements')
            ? $portfolio->cashMovements
            : $portfolio->cashMovements()->get();

        [$holdings, $realizedPl] = $this->reduceHoldings($positions);

        $marketValue = 0.0;
        $unrealizedPl = 0.0;
        foreach ($holdings as &$holding) {
            $asset = $holding['asset'];
            $latestPrice = $asset?->latestPriceRecord;
            $latestClose = $latestPrice ? (float) $latestPrice->close : null;
            $latestCloseDate = $latestPrice?->date?->toDateString();

            $qty = (float) $holding['qty'];
            $avgCost = (float) $holding['avg_cost'];

            $holding['latest_close'] = $latestClose;
            $holding['latest_close_date'] = $latestCloseDate;

            if ($latestClose !== null && $qty > 0) {
                $value = $latestClose * $qty;
                $cost = $avgCost * $qty;
                $holding['market_value'] = $value;
                $holding['unrealized_pl'] = $value - $cost;
                $holding['unrealized_pl_pct'] = $cost > 0
                    ? (($value - $cost) / $cost) * 100.0
                    : null;
                $marketValue += $value;
                $unrealizedPl += $value - $cost;
            } else {
                $fallbackValue = $qty > 0 ? ($avgCost * $qty) : 0.0;
                $holding['market_value'] = $fallbackValue;
                $holding['unrealized_pl'] = 0.0;
                $holding['unrealized_pl_pct'] = null;
                $marketValue += $fallbackValue;
            }
        }
        unset($holding);

        $cash = $this->cash($portfolio, $positions, $cashMovements);

        return [
            'portfolio_id' => (int) $portfolio->id,
            'base_ccy' => (string) $portfolio->base_ccy,
            'base_cash_balance' => $this->round2($cash['base']),
            'non_trade_cash_flow' => $this->round2($cash['non_trade']),
            'trade_cash_flow' => $this->round2($cash['trade']),
            'cash_balance' => $this->round2($cash['available']),
            'cash_breakdown' => $cash['breakdown'],
            'total_market_value' => $this->round2($marketValue),
            // The identity the whole ledger has to satisfy.
            'total_equity' => $this->round2($cash['available'] + $marketValue),
            'realized_pl' => $this->round2($realizedPl),
            'unrealized_pl' => $this->round2($unrealizedPl),
            'holdings' => array_values(array_filter(
                array_map(fn (array $h): array => $this->shapeHolding($h), $holdings),
                static fn (array $h): bool => $h['qty'] > 0 || $h['unrealized_pl'] !== 0.0
            )),
            'allocation_by_symbol' => $this->allocationBySymbol($holdings, $marketValue),
            'allocation_by_sector' => $this->allocationBySector($holdings, $marketValue),
        ];
    }

    /**
     * Walk positions in chronological order, maintaining a running average
     * cost per asset. Returns the per-asset accumulator plus realized P/L
     * collected from matched exits.
     *
     * Convention:
     *   - Entry: avg = (qty_old*avg_old + qty_new*price_new + fee) / total_qty
     *   - Exit:  realized += qty_exit * (price_exit - avg_old) - fee
     *            qty -= qty_exit  (avg unchanged)
     *
     * @param  Collection<int, Position>  $positions
     * @return array{0: array<int, array<string, mixed>>, 1: float}
     */
    private function reduceHoldings(Collection $positions): array
    {
        $sorted = $positions->sortBy([
            ['executed_at', 'asc'],
            ['id', 'asc'],
        ]);

        $byAsset = [];
        $realizedPl = 0.0;

        foreach ($sorted as $position) {
            $assetId = (int) $position->asset_id;
            $byAsset[$assetId] ??= [
                'asset_id' => $assetId,
                'asset' => $position->asset ?? null,
                'qty' => 0.0,
                'avg_cost' => 0.0,
                'cost_basis' => 0.0,
                'last_executed_at' => null,
            ];
            $h = &$byAsset[$assetId];

            $qty = (float) $position->qty_shares;
            $price = (float) $position->price;
            $feeValue = (float) ($position->fee_value ?? 0.0);
            $side = (string) $position->side;

            if ($side === 'entry') {
                $newQty = $h['qty'] + $qty;
                if ($newQty > 0) {
                    $h['avg_cost'] = (($h['avg_cost'] * $h['qty']) + ($price * $qty) + $feeValue) / $newQty;
                }
                $h['qty'] = $newQty;
                $h['cost_basis'] = $h['avg_cost'] * $h['qty'];
            } else {
                $exitQty = min($qty, $h['qty']);
                if ($exitQty > 0) {
                    $realizedPl += ($price - $h['avg_cost']) * $exitQty - $feeValue;
                    $h['qty'] -= $exitQty;
                    $h['cost_basis'] = $h['avg_cost'] * $h['qty'];
                } else {
                    $realizedPl -= $feeValue;
                }
            }

            $h['last_executed_at'] = $position->executed_at?->toDateString();
            unset($h);
        }

        return [$byAsset, $realizedPl];
    }

    /**
     * The cash ledger, in the three terms the summary reports.
     *
     * @param  Collection<int, Position>  $positions
     * @param  Collection<int, CashMovement>  $cashMovements
     * @return array{base: float, non_trade: float, trade: float, available: float, breakdown: array<string, float>}
     */
    private function cash(Portfolio $portfolio, Collection $positions, Collection $cashMovements): array
    {
        $base = (float) ($portfolio->cash_balance ?? 0.0);

        $nonTrade = 0.0;
        $breakdown = array_fill_keys(CashMovement::KINDS, 0.0);

        foreach ($cashMovements as $movement) {
            $signed = $movement->signedAmount();
            $nonTrade += $signed;
            $breakdown[$movement->kind] = ($breakdown[$movement->kind] ?? 0.0) + $signed;
        }

        // Derived from the positions themselves, so a corrected or deleted
        // position corrects the cash with it and nothing needs synchronising.
        $trade = 0.0;

        foreach ($positions as $position) {
            $trade += $this->pricing->signedCashFlowForPosition($position);
        }

        $breakdown = array_map(fn (float $value): float => $this->round2($value), $breakdown);
        $breakdown['base_cash'] = $this->round2($base);
        $breakdown['trade_settlement'] = $this->round2($trade);

        return [
            'base' => $base,
            'non_trade' => $nonTrade,
            'trade' => $trade,
            'available' => $base + $nonTrade + $trade,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * @param  array<string, mixed>  $holding
     * @return array<string, mixed>
     */
    private function shapeHolding(array $holding): array
    {
        $asset = $holding['asset'];

        return [
            'asset_id' => (int) $holding['asset_id'],
            'symbol' => $asset?->symbol,
            'name' => $asset?->name,
            'sector' => $asset?->sector,
            'qty' => $this->round4((float) $holding['qty']),
            'avg_cost' => $this->round4((float) $holding['avg_cost']),
            'cost_basis' => $this->round2((float) $holding['cost_basis']),
            'latest_close' => $holding['latest_close'],
            'latest_close_date' => $holding['latest_close_date'],
            'market_value' => $this->round2((float) $holding['market_value']),
            'unrealized_pl' => $this->round2((float) $holding['unrealized_pl']),
            'unrealized_pl_pct' => $holding['unrealized_pl_pct'] === null
                ? null
                : $this->round4((float) $holding['unrealized_pl_pct']),
            'last_executed_at' => $holding['last_executed_at'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $holdings
     * @return array<int, array<string, mixed>>
     */
    private function allocationBySymbol(array $holdings, float $totalMarketValue): array
    {
        $rows = [];
        foreach ($holdings as $h) {
            if ($h['qty'] <= 0) {
                continue;
            }
            $value = (float) $h['market_value'];
            $rows[] = [
                'asset_id' => (int) $h['asset_id'],
                'symbol' => $h['asset']?->symbol,
                'value' => $this->round2($value),
                'pct' => $totalMarketValue > 0 ? $this->round4(($value / $totalMarketValue) * 100.0) : null,
            ];
        }

        usort($rows, fn (array $a, array $b): int => ($b['value'] <=> $a['value']));

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $holdings
     * @return array<int, array<string, mixed>>
     */
    private function allocationBySector(array $holdings, float $totalMarketValue): array
    {
        $bySector = [];
        foreach ($holdings as $h) {
            if ($h['qty'] <= 0) {
                continue;
            }
            $sector = $h['asset']?->sector ?: 'Unclassified';
            $bySector[$sector] ??= 0.0;
            $bySector[$sector] += (float) $h['market_value'];
        }

        $rows = [];
        foreach ($bySector as $sector => $value) {
            $rows[] = [
                'sector' => $sector,
                'value' => $this->round2($value),
                'pct' => $totalMarketValue > 0 ? $this->round4(($value / $totalMarketValue) * 100.0) : null,
            ];
        }

        usort($rows, fn (array $a, array $b): int => ($b['value'] <=> $a['value']));

        return $rows;
    }

    private function round2(float $value): float
    {
        return round($value, 2);
    }

    private function round4(float $value): float
    {
        return round($value, 4);
    }
}
