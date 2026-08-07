<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Safe deprecation of legacy trading tables that have no remaining
 * references in the codebase: config_profiles, signals, orders, trades,
 * sheet_sources, backtest_equity. These were created by
 * 2025_08_29_152203_create_trading_tables.php for an earlier strategy
 * pipeline that has since been superseded.
 *
 * Strategy:
 *   - For each legacy table that exists, abort the migration with a
 *     clear error if it still contains rows. This prevents silent data
 *     loss in any environment where the table was actually used.
 *   - If the table is empty, drop it (children before parents to avoid
 *     foreign-key issues on engines that enforce FKs).
 *   - down() recreates the tables with their original schema, so a
 *     rollback restores the previous state.
 */
return new class extends Migration
{
    /**
     * Drop order matters: trades → orders (FK), backtest_equity is
     * standalone, the rest depend only on assets which we keep.
     *
     * @var array<int, string>
     */
    private array $legacyTables = [
        'trades',
        'orders',
        'backtest_equity',
        'signals',
        'sheet_sources',
        'config_profiles',
    ];

    public function up(): void
    {
        foreach ($this->legacyTables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $rowCount = DB::table($table)->count();
            if ($rowCount > 0) {
                throw new \RuntimeException(sprintf(
                    'Refusing to drop legacy table "%s": it still contains %d row(s). Migrate or archive the data first, then re-run.',
                    $table,
                    $rowCount
                ));
            }

            Schema::drop($table);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('config_profiles')) {
            Schema::create('config_profiles', function ($table) {
                $table->id();
                $table->string('name');
                $table->json('params_json')->nullable();
            });
        }

        if (! Schema::hasTable('signals')) {
            Schema::create('signals', function ($table) {
                $table->id();
                $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
                $table->date('date');
                $table->string('signal_type');
                $table->decimal('price', 16, 4)->nullable();
                $table->decimal('atr', 16, 4)->nullable();
                $table->decimal('trail', 16, 4)->nullable();
                $table->json('meta_json')->nullable();
            });
        }

        if (! Schema::hasTable('orders')) {
            Schema::create('orders', function ($table) {
                $table->id();
                $table->foreignId('position_id')->constrained('positions')->cascadeOnDelete();
                $table->string('action', 10);
                $table->decimal('qty', 20, 4);
                $table->decimal('price', 16, 4)->nullable();
                $table->string('status', 20);
                $table->date('date');
            });
        }

        if (! Schema::hasTable('trades')) {
            Schema::create('trades', function ($table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->decimal('qty', 20, 4);
                $table->decimal('price', 16, 4);
                $table->decimal('fee', 16, 4)->nullable();
            });
        }

        if (! Schema::hasTable('sheet_sources')) {
            Schema::create('sheet_sources', function ($table) {
                $table->id();
                $table->string('kind');
                $table->string('url');
                $table->json('mapping_json')->nullable();
            });
        }

        if (! Schema::hasTable('backtest_equity')) {
            Schema::create('backtest_equity', function ($table) {
                $table->string('run_id');
                $table->date('date');
                $table->decimal('equity', 20, 4);
            });
        }
    }
};
