<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add covering indexes for hot query paths anticipated by upcoming
 * portfolio and strategy work, plus existing scans:
 *
 *   - broker_summary_facts(trade_date) — date-only scans across assets,
 *     used by multi-day broker accumulation aggregation.
 *   - bandar_detector_summaries(to_date) — windowed scans across assets.
 *   - positions(portfolio_id, executed_at) — portfolio-scoped P/L over
 *     a date range; current single-column index on executed_at alone
 *     forces a portfolio_id filter to walk the index.
 *   - features_daily(date, has_broker) — strategy:scan-accumulation
 *     filters by both columns; the existing PK is (symbol, date) which
 *     does not help when scanning by date across all symbols.
 *
 * All indexes are non-unique, additive, and reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('broker_summary_facts')) {
            Schema::table('broker_summary_facts', function (Blueprint $table) {
                $table->index('trade_date', 'broker_summary_facts_trade_date_index');
            });
        }

        if (Schema::hasTable('bandar_detector_summaries')) {
            Schema::table('bandar_detector_summaries', function (Blueprint $table) {
                $table->index('to_date', 'bandar_detector_summaries_to_date_index');
            });
        }

        if (Schema::hasTable('positions')) {
            Schema::table('positions', function (Blueprint $table) {
                $table->index(['portfolio_id', 'executed_at'], 'positions_portfolio_id_executed_at_index');
            });
        }

        if (Schema::hasTable('features_daily')) {
            Schema::table('features_daily', function (Blueprint $table) {
                $table->index(['date', 'has_broker'], 'features_daily_date_has_broker_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('features_daily')) {
            Schema::table('features_daily', function (Blueprint $table) {
                $table->dropIndex('features_daily_date_has_broker_index');
            });
        }

        if (Schema::hasTable('positions')) {
            Schema::table('positions', function (Blueprint $table) {
                $table->dropIndex('positions_portfolio_id_executed_at_index');
            });
        }

        if (Schema::hasTable('bandar_detector_summaries')) {
            Schema::table('bandar_detector_summaries', function (Blueprint $table) {
                $table->dropIndex('bandar_detector_summaries_to_date_index');
            });
        }

        if (Schema::hasTable('broker_summary_facts')) {
            Schema::table('broker_summary_facts', function (Blueprint $table) {
                $table->dropIndex('broker_summary_facts_trade_date_index');
            });
        }
    }
};
