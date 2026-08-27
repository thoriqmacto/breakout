<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A scheduled task is an Artisan command plus structured parameters,
        // never a shell string. `command` is checked against the allowlist in
        // config/automation.php on write and again before execution, and
        // `parameters` holds {arguments: {...}, options: {...}} validated
        // against that command's declared specification.
        Schema::create('scheduled_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description', 1000)->nullable();

            $table->string('command', 191);
            $table->json('parameters');

            $table->string('cron_expression', 191);

            // Explicit per task rather than inherited from config/app.php.
            // The application runs in UTC; these schedules are IDX market
            // times, so a task that says 16:00 means 16:00 in Jakarta and
            // keeps meaning that if the server timezone ever changes.
            $table->string('timezone', 64)->default('Asia/Jakarta');

            // none | trading_day | last_trading_day_of_week
            $table->string('condition', 48)->default('none');

            // Lower runs first. The daily OHLCV job and the weekly broker
            // summary share a nominal 16:00, and both take the shared Stockbit
            // lock, so this is what makes them sequential rather than racing.
            $table->unsignedSmallInteger('priority')->default(100);

            $table->boolean('enabled')->default(true);
            $table->boolean('sync_gdrive_after_success')->default(false);

            // Marks the rows the migration seeds. They stay fully editable;
            // this only distinguishes them in the UI and lets the seeder
            // recreate them without duplicating.
            $table->boolean('is_system')->default(false);

            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();

            $table->timestamps();

            $table->index(['enabled', 'priority'], 'scheduled_tasks_enabled_priority_index');
        });

        Schema::create('scheduled_task_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduled_task_id')->constrained()->cascadeOnDelete();

            // The occurrence this run belongs to, stored in UTC. Null for a
            // manual "run now", which is not an occurrence and may therefore
            // legitimately repeat.
            $table->timestamp('scheduled_for')->nullable();

            // schedule | manual
            $table->string('trigger', 16)->default('schedule');

            // pending | running | success | failed | skipped | blocked_token
            $table->string('status', 24)->default('pending');
            $table->string('skip_reason', 64)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->integer('exit_code')->nullable();

            // Truncated and redacted before it is written; see
            // automation.max_output_length.
            $table->longText('output')->nullable();
            $table->text('error')->nullable();

            // Per-run facts: the market date or range, ticker counts,
            // failures, and the Google Drive result.
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Duplicate protection. Two dispatcher passes -- or two servers --
            // that both consider the 16:00 occurrence due can only ever create
            // one row for it; the loser's insert fails and it stands down.
            // Manual runs carry a null scheduled_for, which every supported
            // engine exempts from a unique index.
            $table->unique(['scheduled_task_id', 'scheduled_for'], 'scheduled_task_runs_occurrence_unique');
            $table->index(['scheduled_task_id', 'id'], 'scheduled_task_runs_task_id_index');
            $table->index(['status', 'created_at'], 'scheduled_task_runs_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
        Schema::dropIfExists('scheduled_tasks');
    }
};
