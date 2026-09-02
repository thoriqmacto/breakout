<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The profit lifecycle of a holding the portfolio actually has.
 *
 * `positions` is a ledger: each row is one execution, entry or exit, and a
 * holding is the running result of all of them for an asset. So the risk
 * state cannot hang off a position row -- it belongs to the holding, which is
 * why this is keyed on (portfolio, asset) rather than on a position id.
 *
 * Everything here could in principle be recomputed from bars on every
 * request, and three of the fields could not be recomputed correctly:
 *
 *   `trailing_activated_at` is a fact about when a threshold was crossed. Once
 *   the price has moved on, replaying bars gives the same answer only while
 *   the entry price and the profile are unchanged -- and a profile change
 *   would silently rewrite history.
 *
 *   `effective_stop_price` must never move down. A stop derived fresh on each
 *   request has no memory, so a profile edit or a data correction could lower
 *   it, which is precisely the guarantee the lifecycle rests on.
 *
 *   `highest_price_since_entry` depends on the entry date, and an entry that
 *   predates the stored bar history would otherwise start its high from
 *   whatever the earliest available bar happens to be.
 *
 * `strategy_version` is stored because the numbers mean nothing without the
 * parameters that produced them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_risk_states', function (Blueprint $table) {
            $table->id();

            $table->foreignId('portfolio_id')->constrained('portfolios')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('strategy_version', 64);

            // The holding, as the portfolio ledger reports it. Stored so a
            // change in the ledger is visible as a change here rather than
            // silently reinterpreting an existing lifecycle.
            $table->decimal('qty_shares', 20, 4)->default(0);
            $table->decimal('entry_price', 16, 4)->nullable();
            $table->date('opened_at')->nullable();

            $table->decimal('highest_price_since_entry', 16, 4)->nullable();
            $table->boolean('trailing_active')->default(false);
            $table->date('trailing_activated_at')->nullable();
            $table->decimal('trailing_activation_price', 16, 4)->nullable();
            $table->decimal('profit_floor_price', 16, 4)->nullable();
            $table->decimal('trailing_stop_price', 16, 4)->nullable();
            $table->decimal('initial_stop_price', 16, 4)->nullable();
            $table->decimal('effective_stop_price', 16, 4)->nullable();
            $table->date('stop_updated_at')->nullable();
            $table->date('evaluated_through')->nullable();

            $table->string('latest_broker_regime', 32)->nullable();
            $table->string('latest_action', 32)->nullable();
            $table->json('latest_reasons')->nullable();

            // A holding that has been sold keeps its row for history and stops
            // receiving lifecycle updates. Without this a closed position goes
            // on reporting HOLD for ever.
            $table->boolean('closed')->default(false);
            $table->date('closed_at')->nullable();

            $table->timestamps();

            $table->unique(['portfolio_id', 'asset_id', 'strategy_version'], 'prs_portfolio_asset_version_unique');
            $table->index(['portfolio_id', 'closed'], 'prs_portfolio_closed_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_risk_states');
    }
};
