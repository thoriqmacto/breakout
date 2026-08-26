<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Stockbit broker summary is an aggregate over a range, not a day.
 *
 * Requesting from=2026-05-26&to=2026-08-26 returns one server-side aggregate
 * for that whole window. Every broker row carries netbs_date=20260526 -- the
 * range *start*, repeated -- and the previous model read that as a trading
 * date, stamping it onto broker_summary_facts.trade_date. Three months of
 * aggregated flow was therefore stored as if it happened on 26 May.
 *
 * That also made the identity wrong. 2026-05-26..2026-08-26 and
 * 2026-05-26..2026-09-26 are different aggregates, but they collided on
 * (asset, trade_date, broker, transaction_type) and overwrote each other.
 *
 * The window is the unit. A single-day summary is just from_date == to_date,
 * so no second model is needed for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broker_summary_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();

            // Authoritative range, taken from the payload's data.from/data.to.
            $table->date('from_date');
            $table->date('to_date');
            $table->string('transaction_type', 50);

            // Request dimensions that change what Stockbit returns. Nullable
            // because they cannot be recovered for already-archived files.
            $table->string('market_board', 50)->nullable();
            $table->string('investor_type', 50)->nullable();
            $table->unsignedInteger('requested_limit')->nullable();

            // Stockbit caps the broker lists at the requested limit while
            // still reporting the true totals, so a stored window may hold
            // 25 of 42 buyers. Recording both is what lets the UI say so
            // instead of presenting a truncated list as complete.
            $table->unsignedInteger('returned_buyer_count')->default(0);
            $table->unsignedInteger('returned_seller_count')->default(0);
            $table->unsignedInteger('total_buyer')->nullable();
            $table->unsignedInteger('total_seller')->nullable();

            // Provenance, so a window can be traced back to the raw JSON and
            // rebuilt. No credentials are stored here.
            $table->string('source_filename')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->timestamp('imported_at')->nullable();

            $table->timestamps();

            // Named explicitly: the generated name exceeds the 64-character
            // limit MySQL and MariaDB impose.
            $table->unique(
                ['asset_id', 'from_date', 'to_date', 'transaction_type'],
                'broker_summary_windows_unique',
            );
            $table->index(['asset_id', 'to_date'], 'broker_summary_windows_asset_to_idx');
            $table->index('from_date', 'broker_summary_windows_from_idx');
        });

        Schema::create('broker_summary_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broker_summary_window_id')
                ->constrained('broker_summary_windows')
                ->cascadeOnDelete();

            $table->string('broker_code', 20);

            // Which of Stockbit's two ranked lists this row came from. It is
            // not a transaction leg: both lists are net positions over the
            // window, so net_value must never be recomputed as buy - sell.
            $table->string('side', 4);

            $table->string('broker_type', 20)->nullable();
            $table->unsignedBigInteger('frequency')->nullable();

            // netbs_date, kept for auditing only. Deliberately not called
            // trade_date: for a ranged response it is the range start
            // repeated, and treating it as a trading date is the original bug.
            $table->date('source_date')->nullable();

            // Signed throughout. Stockbit's signs are preserved as given --
            // a buyer-list blot can legitimately be negative, and forcing it
            // positive to fit an unsigned column would falsify the source.
            $table->bigInteger('net_lot')->nullable();
            $table->decimal('net_value', 30, 4)->nullable();
            $table->bigInteger('gross_volume')->nullable();
            $table->decimal('gross_value', 30, 4)->nullable();
            $table->decimal('average_price', 18, 6)->nullable();

            $table->timestamps();

            $table->unique(
                ['broker_summary_window_id', 'broker_code', 'side'],
                'broker_summary_entries_unique',
            );
            $table->index('broker_code', 'broker_summary_entries_broker_idx');
        });

        // One bandar_detector accompanies each window, so point at it rather
        // than re-deriving range identity in a second place.
        Schema::table('bandar_detector_summaries', function (Blueprint $table) {
            $table->foreignId('broker_summary_window_id')
                ->nullable()
                ->after('asset_id')
                ->constrained('broker_summary_windows')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bandar_detector_summaries', function (Blueprint $table) {
            $table->dropForeign(['broker_summary_window_id']);
            $table->dropColumn('broker_summary_window_id');
        });

        Schema::dropIfExists('broker_summary_entries');
        Schema::dropIfExists('broker_summary_windows');
    }
};
