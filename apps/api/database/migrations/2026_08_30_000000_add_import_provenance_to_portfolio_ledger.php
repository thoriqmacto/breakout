<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the portfolio ledger record where a row came from, and record when it
 * happened to the second.
 *
 * Two additive changes, both needed by the Stockbit JSON importer and both
 * harmless to the manual workflow:
 *
 *  - `source` / `external_id` carry the broker's own stable transaction id.
 *    A unique index over (portfolio_id, source, external_id) is what makes
 *    re-pasting the same payload a no-op. Identifying a duplicate by
 *    date/price/quantity instead would be wrong: two legitimate executions
 *    can be identical in all three. Manual rows leave both null, and every
 *    supported engine exempts a null from a unique index, so any number of
 *    them coexist.
 *
 *  - `executed_at` widens from date to datetime. The calculator walks
 *    positions in chronological order to maintain a running average cost, and
 *    with date-only storage several fills on the same day fell back to
 *    insertion order -- which is arbitrary, and changes the average cost and
 *    therefore the realized P/L. Stockbit supplies a time; this keeps it.
 *
 * Existing date-only values widen to midnight, which preserves their relative
 * order and keeps every current query (whereYear, whereDate, toDateString)
 * behaving exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->string('source', 32)->nullable()->after('executed_at');
            $table->string('external_id', 64)->nullable()->after('source');
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->dateTime('executed_at')->nullable()->change();
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->unique(
                ['portfolio_id', 'source', 'external_id'],
                'positions_portfolio_source_external_unique'
            );
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->string('source', 32)->nullable()->after('note');
            $table->string('external_id', 64)->nullable()->after('source');
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dateTime('executed_at')->nullable(false)->change();
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->unique(
                ['portfolio_id', 'source', 'external_id'],
                'cash_movements_portfolio_source_external_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropUnique('positions_portfolio_source_external_unique');
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->date('executed_at')->nullable()->change();
            $table->dropColumn(['source', 'external_id']);
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropUnique('cash_movements_portfolio_source_external_unique');
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->date('executed_at')->nullable(false)->change();
            $table->dropColumn(['source', 'external_id']);
        });
    }
};
