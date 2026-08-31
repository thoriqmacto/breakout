<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cover the holding lookup the long-only exit guard performs.
 *
 * The guard asks "how much of this asset does this portfolio hold as at this
 * moment", which filters on (portfolio_id, asset_id, executed_at). The
 * existing positions_portfolio_id_executed_at_index covers the portfolio and
 * the date but leaves the asset filter to a scan of every row the portfolio
 * owns -- fine for a handful of trades, and not for an imported multi-year
 * ledger, where the guard runs on every manual exit.
 *
 * Deliberately the only index added in this change. The other hot paths are
 * already covered: price_bars has a unique (asset_id, date), features_daily is
 * keyed on (symbol, date), broker_accumulation_windows on
 * (asset_id, end_date, window_days), and watchlist_scores on
 * (scan_date, symbol, version) plus (scan_date, version).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('positions')) {
            return;
        }

        Schema::table('positions', function (Blueprint $table) {
            $table->index(
                ['portfolio_id', 'asset_id', 'executed_at'],
                'positions_portfolio_asset_executed_index',
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('positions')) {
            return;
        }

        Schema::table('positions', function (Blueprint $table) {
            $table->dropIndex('positions_portfolio_asset_executed_index');
        });
    }
};
