<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened after each historical signal.
 *
 * The execution workspace can already say what a setup looks like today. What
 * it could not say is how setups that looked like this have turned out, and
 * that question cannot be answered from the tables that exist: `watchlist_scores`
 * records the signal and nothing after it, and re-simulating forward outcomes
 * on every page load would be both slow and non-reproducible.
 *
 * So one row per (asset, signal date, profile version), written once by
 * `strategy:evaluate-outcomes` and read cheaply thereafter.
 *
 * Two design points carry most of the weight:
 *
 *   `strategy_version` is part of the unique key, not a column beside it. Two
 *   parameter sets produce genuinely different outcomes for the same signal,
 *   and storing them under one key would silently overwrite one with the
 *   other -- making every stored statistic a mixture of rules nobody chose.
 *
 *   `setup_bucket` is the comparability key: the coarse description of what
 *   kind of setup this was. Probability is looked up by bucket, so the
 *   grouping is stored rather than recomputed, and a bucket definition change
 *   is a version change rather than a quiet re-interpretation of old rows.
 *
 * `resolved` separates a trade that finished from one whose forward window
 * ran out. Counting the second as a loss would bias every statistic downward
 * exactly at the recent end of the data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_signal_outcomes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('symbol', 20);
            $table->date('signal_date');
            $table->date('entry_date')->nullable();
            $table->string('strategy_version', 64);

            // How the setup was classified at signal time. Every one of these
            // is derived only from data available on the signal date.
            $table->string('setup_bucket', 96)->index();
            $table->string('broker_regime', 32)->nullable();
            $table->decimal('broker_persistence_ratio', 8, 4)->nullable();
            $table->unsignedTinyInteger('positive_broker_windows')->nullable();
            $table->unsignedTinyInteger('available_broker_windows')->nullable();
            $table->decimal('broker_acceleration', 18, 8)->nullable();
            $table->decimal('execution_score', 8, 4)->nullable();
            $table->boolean('breakout20')->default(false);
            $table->boolean('breakout55')->default(false);
            $table->decimal('vol_ratio_20', 18, 8)->nullable();
            $table->decimal('close_pos', 18, 8)->nullable();
            $table->decimal('atr14', 16, 4)->nullable();

            // The plan, as it stood before the entry session opened.
            $table->decimal('trigger_price', 16, 4)->nullable();
            $table->decimal('entry_price', 16, 4)->nullable();
            $table->decimal('initial_stop', 16, 4)->nullable();
            $table->decimal('initial_risk_pct', 8, 4)->nullable();

            // The probability question: fixed stop, no trailing, no costs.
            $table->boolean('hit_5pct')->default(false);
            $table->unsignedSmallInteger('days_to_5pct')->nullable();
            $table->boolean('hit_initial_stop')->default(false);
            $table->unsignedSmallInteger('days_to_initial_stop')->nullable();
            $table->boolean('hit_stop_before_5pct')->default(false);
            $table->boolean('reached_5pct_before_stop')->default(false);

            foreach ([1, 3, 5, 10, 20] as $horizon) {
                $table->decimal('mfe_'.$horizon.'d', 12, 4)->nullable();
                $table->decimal('mae_'.$horizon.'d', 12, 4)->nullable();
            }

            // The managed trade: full trailing lifecycle and real costs.
            $table->boolean('trailing_activated')->default(false);
            $table->date('trailing_activated_at')->nullable();
            $table->date('exit_date')->nullable();
            $table->decimal('exit_price', 16, 4)->nullable();
            $table->string('exit_reason', 32)->nullable();
            $table->decimal('max_gain_before_exit_pct', 12, 4)->nullable();
            $table->decimal('gross_return_pct', 12, 4)->nullable();
            $table->decimal('net_return_pct', 12, 4)->nullable();
            $table->unsignedSmallInteger('hold_sessions')->default(0);

            // False while the forward window has not yet produced an answer.
            $table->boolean('resolved')->default(false);

            $table->json('context')->nullable();

            $table->timestamps();

            $table->unique(['asset_id', 'signal_date', 'strategy_version'], 'sso_asset_date_version_unique');
            $table->index(['strategy_version', 'signal_date'], 'sso_version_date_index');
            $table->index(['strategy_version', 'setup_bucket', 'resolved'], 'sso_version_bucket_resolved_index');
            $table->index(['symbol', 'signal_date'], 'sso_symbol_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_signal_outcomes');
    }
};
