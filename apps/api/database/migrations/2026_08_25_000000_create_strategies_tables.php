<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A strategy is a user-authored rule tree over the daily feature and
        // metric tables. The tree itself lives in `rules` as JSON rather than
        // in columns: its shape is recursive (AND/OR groups nesting
        // conditions), which does not decompose into a fixed schema, and it is
        // always read and written whole.
        Schema::create('strategies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description', 1000)->nullable();

            // private: only the owner may see or run it.
            // public:  anyone may see, run and copy it; only the owner edits.
            $table->string('visibility', 16)->default('private');

            $table->json('rules');
            $table->boolean('is_active')->default(true);

            // Set when this strategy was copied from a public one, so a fork
            // can point back at its origin. nullOnDelete keeps the copy alive
            // if the original is removed.
            $table->foreignId('copied_from_id')->nullable()
                ->constrained('strategies')->nullOnDelete();

            // Denormalised from the latest run so a dashboard card does not
            // need a correlated subquery per strategy to render.
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_run_status', 24)->nullable();
            $table->unsignedInteger('last_match_count')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'visibility'], 'strategies_user_visibility_index');
            $table->index(['visibility', 'is_active'], 'strategies_visibility_active_index');
        });

        Schema::create('strategy_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_id')->constrained()->cascadeOnDelete();
            $table->date('scan_date');

            // queued -> running -> completed | failed
            $table->string('status', 24)->default('queued');

            $table->unsignedInteger('evaluated_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['strategy_id', 'scan_date'], 'strategy_runs_strategy_date_index');
        });

        Schema::create('strategy_run_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol', 20);

            // The field values the rules were evaluated against, and which
            // conditions passed. Kept per match so a result stays explainable
            // after the underlying feature row has moved on.
            $table->json('facts');
            $table->json('explanation');

            $table->timestamps();

            $table->index(['strategy_run_id', 'symbol'], 'strategy_matches_run_symbol_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_run_matches');
        Schema::dropIfExists('strategy_runs');
        Schema::dropIfExists('strategies');
    }
};
