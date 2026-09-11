<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A standing request to be told when a strategy fires on an asset.
 *
 * Backtesting answers "would this have worked"; this answers "is it happening
 * now". They are the same strategy code evaluated over the same bars -- the
 * difference is only that one walks history and the other looks at the last
 * bar -- so an alert cannot drift from the backtest that justified it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_alerts', function (Blueprint $table) {
            $table->id();

            // Owned, because two people watching the same asset want their own
            // alerts: one dismissing a reminder must not clear the other's.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();

            // Denormalised so the list reads without a join and so an alert
            // still names its symbol in a log line after the asset is gone.
            $table->string('symbol', 32);
            $table->string('strategy', 64);

            // Which side to watch for. Separate rows rather than a set, so one
            // can be disabled without touching the other.
            $table->string('signal', 16)->default('buy');

            $table->boolean('enabled')->default(true);

            /**
             * The session the alert last fired for, not a timestamp.
             *
             * The evaluator runs after the daily collection and could run
             * again -- a manual re-run, a catch-up minute -- and a strategy
             * that is still in a buy state would fire on every one of them.
             * Keyed on the bar's own date, a repeat within the same session is
             * recognisable as the same event rather than a new one.
             */
            $table->date('last_triggered_on')->nullable();
            $table->string('last_signal', 16)->nullable();
            $table->timestamp('last_evaluated_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'asset_id', 'strategy', 'signal'], 'strategy_alerts_unique');
            $table->index(['enabled', 'symbol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_alerts');
    }
};
