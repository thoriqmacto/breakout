<?php

use App\Services\Portfolio\PositionPricing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make available cash include trade settlement, without moving anybody's
 * displayed balance.
 *
 * The calculator used to compute cash as:
 *
 *     displayed = cash_balance + non-trade movements
 *
 * It now computes:
 *
 *     displayed = cash_balance + non-trade movements + signed trade flows
 *
 * Existing `cash_balance` values were entered, imported and reconciled under
 * the first formula -- typically as "what the broker says I have right now",
 * with every historical buy and sell already reflected in it. Switching
 * formulas without touching the stored figure would subtract every historical
 * purchase a second time and add every sale twice, which on a portfolio with
 * years of imported history is not a small drift: it is a number with no
 * relationship to reality.
 *
 * So the stored base is rebased by exactly the term being introduced:
 *
 *     new_base = old_base - historical_signed_trade_cash_flow
 *
 * which makes the two formulas agree the instant the migration finishes:
 *
 *     new_base + movements + trade_flow
 *   = (old_base - trade_flow) + movements + trade_flow
 *   = old_base + movements
 *   = what was on screen a moment ago
 *
 * From then on the term is live: the next trade moves the cash, and the base
 * means what it says -- the money the portfolio started with, before any of
 * its trades.
 *
 * Guarded by `cash_accounting_version` so a re-run cannot rebase twice. A
 * second application would be silent and would look exactly like a large
 * unexplained withdrawal.
 */
return new class extends Migration
{
    /**
     * Version stamped on a portfolio whose base has been rebased.
     */
    private const VERSION_TRADE_SETTLEMENT = 2;

    public function up(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->unsignedTinyInteger('cash_accounting_version')
                ->default(1)
                ->after('cash_balance');
        });

        $pricing = new PositionPricing;

        DB::table('portfolios')
            ->where('cash_accounting_version', '<', self::VERSION_TRADE_SETTLEMENT)
            ->orderBy('id')
            ->select('id', 'cash_balance')
            ->chunkById(200, function ($portfolios) use ($pricing) {
                foreach ($portfolios as $portfolio) {
                    $tradeFlow = $this->tradeCashFlow((int) $portfolio->id, $pricing);

                    DB::table('portfolios')
                        ->where('id', $portfolio->id)
                        ->update([
                            'cash_balance' => round((float) $portfolio->cash_balance - $tradeFlow, 2),
                            'cash_accounting_version' => self::VERSION_TRADE_SETTLEMENT,
                        ]);
                }
            });
    }

    public function down(): void
    {
        $pricing = new PositionPricing;

        DB::table('portfolios')
            ->where('cash_accounting_version', '>=', self::VERSION_TRADE_SETTLEMENT)
            ->orderBy('id')
            ->select('id', 'cash_balance')
            ->chunkById(200, function ($portfolios) use ($pricing) {
                foreach ($portfolios as $portfolio) {
                    $tradeFlow = $this->tradeCashFlow((int) $portfolio->id, $pricing);

                    DB::table('portfolios')
                        ->where('id', $portfolio->id)
                        ->update([
                            'cash_balance' => round((float) $portfolio->cash_balance + $tradeFlow, 2),
                            'cash_accounting_version' => 1,
                        ]);
                }
            });

        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropColumn('cash_accounting_version');
        });
    }

    /**
     * Signed settlement of every position in the portfolio.
     *
     * Uses the same PositionPricing the calculator does, so the figure removed
     * here is exactly the figure added back at read time. Deriving it any
     * other way -- even with what looks like the same arithmetic -- would make
     * the invariant depend on two implementations staying identical.
     */
    private function tradeCashFlow(int $portfolioId, PositionPricing $pricing): float
    {
        $flow = 0.0;

        DB::table('positions')
            ->where('portfolio_id', $portfolioId)
            ->orderBy('id')
            ->select('id', 'side', 'qty_shares', 'price', 'fee_value')
            ->chunkById(1000, function ($positions) use ($pricing, &$flow) {
                foreach ($positions as $position) {
                    $flow += $pricing->signedCashFlow(
                        (string) $position->side,
                        (float) $position->qty_shares,
                        (float) $position->price,
                        (float) ($position->fee_value ?? 0.0),
                    );
                }
            });

        return $flow;
    }
};
