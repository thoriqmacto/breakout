<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make a backtest findable by what it was.
 *
 * The table was written for a forecasting command that read back its own runs
 * by id, so everything describing a run lived in `params_json`. The dashboard
 * asks different questions -- every run for this symbol, the latest run per
 * strategy, this asset compared across strategies -- and each of those becomes
 * a scan and a JSON parse per row when the columns it filters on are inside a
 * JSON blob.
 *
 * The four columns below are the ones queried, not everything in params: the
 * capital, the date range and the trailing stop are read once a run is already
 * in hand, and they stay where they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backtests', function (Blueprint $table) {
            // Nullable because rows already exist: AssetForecastCommand has
            // been writing here since August, and a backfill would have to
            // invent a strategy name for runs that never recorded one.
            $table->foreignId('asset_id')->nullable()->after('run_id')
                ->constrained('assets')->nullOnDelete();
            $table->string('symbol', 32)->nullable()->after('asset_id');
            $table->string('strategy', 64)->nullable()->after('symbol');

            // Who asked for it. A run started from the dashboard and one from
            // a nightly job are both legitimate history, but only one of them
            // is a user waiting for an answer.
            $table->string('source', 32)->nullable()->after('strategy');

            $table->index(['symbol', 'strategy', 'created_at'], 'backtests_symbol_strategy_idx');
            $table->index(['strategy', 'created_at'], 'backtests_strategy_idx');
        });
    }

    public function down(): void
    {
        Schema::table('backtests', function (Blueprint $table) {
            $table->dropIndex('backtests_symbol_strategy_idx');
            $table->dropIndex('backtests_strategy_idx');
            $table->dropConstrainedForeignId('asset_id');
            $table->dropColumn(['symbol', 'strategy', 'source']);
        });
    }
};
