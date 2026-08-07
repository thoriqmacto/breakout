<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — portfolio module completion: introduce per-user scoping and a
 * dedicated cash-movements ledger so the dashboard can compute true equity
 * (cash + market value), not just position summaries.
 *
 * Backwards compatibility:
 *   - portfolios.user_id is nullable on creation, then backfilled to the
 *     first user (id ASC) if any exist, then indexed. We deliberately do
 *     NOT enforce NOT NULL here so environments without a users row keep
 *     working; the PortfolioPolicy only requires user_id on portfolios
 *     that have one set.
 *   - portfolios.cash_balance defaults to 0 so existing rows are valid.
 *   - cash_movements is a new table; nothing depends on its schema yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained('users')
                ->nullOnDelete();

            $table->decimal('cash_balance', 20, 2)
                ->default(0)
                ->after('base_ccy');
        });

        $firstUserId = DB::table('users')->orderBy('id')->value('id');
        if ($firstUserId !== null) {
            DB::table('portfolios')
                ->whereNull('user_id')
                ->update(['user_id' => $firstUserId]);
        }

        Schema::table('portfolios', function (Blueprint $table) {
            $table->index('user_id', 'portfolios_user_id_index');
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_id')
                ->constrained('portfolios')
                ->cascadeOnDelete();
            $table->string('kind', 20);
            $table->decimal('amount', 20, 2);
            $table->date('executed_at');
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['portfolio_id', 'executed_at'], 'cash_movements_portfolio_id_executed_at_index');
            $table->index('kind', 'cash_movements_kind_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');

        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropIndex('portfolios_user_id_index');
        });

        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('cash_balance');
        });
    }
};
