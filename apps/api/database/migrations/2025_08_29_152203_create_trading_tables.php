<?php

// database/migrations/2025_08_29_152203_create_trading_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('config_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('params_json')->nullable();
        });

        Schema::create('signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->date('date');
            $table->string('signal_type');
            $table->decimal('price', 16, 4)->nullable();
            $table->decimal('atr', 16, 4)->nullable();
            $table->decimal('trail', 16, 4)->nullable();
            $table->json('meta_json')->nullable();
        });

        Schema::create('portfolios', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('base_ccy', 10);
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_id')->constrained('portfolios')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('side', 4);
            $table->decimal('qty_shares', 20, 4);
            $table->decimal('avg_price', 16, 4);
            $table->date('entry_date');
            $table->decimal('trail', 16, 4)->nullable();
            $table->string('status', 20);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained('positions')->cascadeOnDelete();
            $table->string('action', 10);
            $table->decimal('qty', 20, 4);
            $table->decimal('price', 16, 4)->nullable();
            $table->string('status', 20);
            $table->date('date');
        });

        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->decimal('qty', 20, 4);
            $table->decimal('price', 16, 4);
            $table->decimal('fee', 16, 4)->nullable();
        });

        Schema::create('sheet_sources', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->string('url');
            $table->json('mapping_json')->nullable();
        });

        Schema::create('backtests', function (Blueprint $table) {
            $table->string('run_id')->primary();
            $table->timestamp('created_at');
            $table->json('params_json')->nullable();
            $table->json('stats_json')->nullable();
            $table->text('notes')->nullable();
        });

        Schema::create('backtest_trades', function (Blueprint $table) {
            $table->string('run_id');
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->date('entry_date');
            $table->decimal('entry_px', 16, 4);
            $table->date('exit_date')->nullable();
            $table->decimal('exit_px', 16, 4)->nullable();
            $table->decimal('units', 20, 4);
            $table->decimal('pnl', 20, 4)->nullable();
        });

        Schema::create('backtest_equity', function (Blueprint $table) {
            $table->string('run_id');
            $table->date('date');
            $table->decimal('equity', 20, 4);
        });
    }

    public function down(): void {
        Schema::dropIfExists('backtest_equity');
        Schema::dropIfExists('backtest_trades');
        Schema::dropIfExists('backtests');
        Schema::dropIfExists('sheet_sources');
        Schema::dropIfExists('trades');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('portfolios');
        Schema::dropIfExists('signals');
        Schema::dropIfExists('config_profiles');
    }
};

