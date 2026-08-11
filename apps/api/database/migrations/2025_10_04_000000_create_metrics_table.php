<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('symbol');
            $table->string('name')->nullable();
            $table->decimal('close', 15, 2)->nullable();
            $table->decimal('ma50', 15, 2)->nullable();
            $table->decimal('ma100', 15, 2)->nullable();
            $table->decimal('high20', 15, 2)->nullable();
            $table->decimal('high55', 15, 2)->nullable();
            $table->decimal('atr14', 15, 2)->nullable();
            $table->decimal('roc13', 15, 4)->nullable();
            $table->decimal('avg_vol20', 20, 2)->nullable();
            $table->decimal('vol_vs_avg20', 15, 4)->nullable();
            $table->decimal('close_vs_high20', 15, 4)->nullable();
            $table->decimal('close_vs_high55', 15, 4)->nullable();
            $table->boolean('uptrend')->default(false);
            $table->unsignedInteger('bars')->default(0);
            $table->unsignedTinyInteger('sort_uptrend')->default(0);
            $table->decimal('sort_roc13', 15, 4)->default(0);
            $table->decimal('sort_close_vs_high55', 15, 4)->default(0);
            $table->decimal('sort_close_vs_high20', 15, 4)->default(0);
            $table->decimal('sort_vol_vs_avg20', 15, 4)->default(0);
            $table->timestamps();

            $table->unique('asset_id');

            // Named explicitly: the auto-generated name for these five columns
            // is 97 characters, and MySQL/MariaDB cap identifiers at 64. SQLite
            // has no such limit, so this only fails once the app moves engines.
            $table->index([
                'sort_uptrend',
                'sort_roc13',
                'sort_close_vs_high55',
                'sort_close_vs_high20',
                'sort_vol_vs_avg20',
            ], 'metrics_ranking_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metrics');
    }
};
