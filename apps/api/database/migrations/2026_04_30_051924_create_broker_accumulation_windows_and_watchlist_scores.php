<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — broker summary strategy module:
 *   - broker_accumulation_windows: per-asset / window-end / window-days
 *     rollup of broker net flow used as input to the scoring service.
 *     Idempotent unique key so the rollup command is re-runnable.
 *   - watchlist_scores: explainable persisted output of the ranker,
 *     keyed by (scan_date, symbol, version) so multiple scoring
 *     versions can coexist for backtesting and trend tracking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broker_accumulation_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')
                ->constrained('assets')
                ->cascadeOnDelete();
            $table->date('end_date');
            $table->unsignedSmallInteger('window_days');

            // Aggregated metrics over the window.
            $table->decimal('avg_net_norm', 18, 8)->default(0);
            $table->decimal('top3_net_norm', 18, 8)->default(0);
            $table->decimal('top5_net_norm', 18, 8)->default(0);
            $table->decimal('foreign_net_norm', 18, 8)->default(0);
            $table->decimal('local_net_norm', 18, 8)->default(0);
            $table->tinyInteger('accdist_score')->default(0);
            $table->unsignedInteger('broker_count')->default(0);
            $table->decimal('value', 24, 2)->default(0);
            $table->unsignedBigInteger('volume')->default(0);

            $table->timestamps();

            $table->unique(
                ['asset_id', 'end_date', 'window_days'],
                'baw_asset_end_window_unique'
            );
            $table->index(['end_date', 'window_days'], 'baw_end_window_index');
        });

        Schema::create('watchlist_scores', function (Blueprint $table) {
            $table->id();
            $table->date('scan_date');
            $table->string('symbol', 20);
            $table->foreignId('asset_id')
                ->nullable()
                ->constrained('assets')
                ->nullOnDelete();
            $table->string('version', 32)->default('v1');

            // Inputs at scan time, kept on the row so callers can reproduce
            // the score without re-querying upstream tables.
            $table->decimal('close', 16, 4)->nullable();
            $table->decimal('net_value', 24, 2)->nullable();
            $table->decimal('vol_ratio_20', 18, 8)->nullable();
            $table->boolean('breakout20')->default(false);

            // Sub-scores (0..100).
            $table->decimal('score_total', 8, 4)->default(0);
            $table->decimal('score_bas', 8, 4)->default(0);
            $table->decimal('score_bcs', 8, 4)->default(0);

            // Filter outcomes.
            $table->boolean('lf_pass')->default(false);
            $table->boolean('rrf_pass')->default(false);

            // Risk levels and explainable rationale.
            $table->decimal('invalidation_level', 16, 4)->nullable();
            $table->decimal('take_profit', 16, 4)->nullable();
            $table->decimal('risk_reward', 8, 4)->nullable();
            $table->json('top_brokers')->nullable();
            $table->json('reasons')->nullable();
            $table->string('risk_notes', 1024)->nullable();

            $table->timestamps();

            $table->unique(['scan_date', 'symbol', 'version'], 'ws_scan_symbol_version_unique');
            $table->index(['scan_date', 'version'], 'ws_scan_version_index');
            $table->index(['symbol', 'scan_date'], 'ws_symbol_scan_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlist_scores');
        Schema::dropIfExists('broker_accumulation_windows');
    }
};
