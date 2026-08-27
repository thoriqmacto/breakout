<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teach the rollup table that its input is a range, not a stack of days.
 *
 * broker_accumulation_windows was built when broker_summary_facts held one row
 * per broker per trading day, so a rollup was "sum the last N days" and
 * (end_date, window_days) said everything there was to say. A Stockbit broker
 * summary is really one aggregate over from..to, and those aggregates do not
 * decompose into days -- so the rollup now carries where its range starts, how
 * much of it actually had data, and which imported window it came from.
 *
 * All three are nullable: rows written before this migration described daily
 * sums, and inventing a start or a coverage figure for them would be exactly
 * the kind of fabrication this whole change exists to remove. A null start_date
 * means "written by the old daily aggregator", and consumers treat it as such.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broker_accumulation_windows', function (Blueprint $table) {
            // The range start. Derivable as end_date - (window_days - 1), but
            // only with date arithmetic that differs between SQLite and
            // MariaDB; storing it keeps "does this rollup cover date D" a
            // plain column comparison on both.
            $table->date('start_date')->nullable()->after('end_date');

            // Days of [start_date, end_date] that a real window covered. A
            // 20-day rollup assembled from one 3-day window is not the same
            // evidence as a full one, and without this the two are
            // indistinguishable once written.
            $table->unsignedSmallInteger('covered_days')->nullable()->after('window_days');

            // Non-null marks a row that mirrors one imported window at its own
            // length -- a 92-day summary stored as window_days = 92 rather than
            // cut up into days it never described. Also the provenance link
            // back to the source aggregate.
            $table->foreignId('source_window_id')
                ->nullable()
                ->after('asset_id')
                ->constrained('broker_summary_windows')
                ->nullOnDelete();

            $table->index(['asset_id', 'start_date', 'end_date'], 'baw_asset_range_index');
        });
    }

    public function down(): void
    {
        Schema::table('broker_accumulation_windows', function (Blueprint $table) {
            $table->dropIndex('baw_asset_range_index');
            $table->dropForeign(['source_window_id']);
            $table->dropColumn(['source_window_id', 'covered_days', 'start_date']);
        });
    }
};
