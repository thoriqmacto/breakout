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
        Schema::create('features_daily', function (Blueprint $table) {
            $table->string('symbol', 20);
            $table->date('date');

            $table->decimal('ret_1', 18, 8)->default(0);
            $table->decimal('range_pct', 18, 8)->default(0);
            $table->decimal('close_pos', 18, 8)->default(0);
            $table->decimal('body_to_range', 18, 8)->default(0);
            $table->decimal('vol_ratio_20', 18, 8)->default(0);
            $table->decimal('atr_pct', 18, 8)->default(0);
            $table->decimal('close_vs_ma20', 18, 8)->default(0);
            $table->decimal('ma20_slope', 18, 8)->default(0);
            $table->boolean('breakout20')->default(false);
            $table->boolean('compression')->default(false);

            $table->boolean('has_broker')->default(false);
            $table->decimal('turnover_value', 20, 2)->default(0);
            $table->unsignedBigInteger('turnover_volume')->default(0);
            $table->tinyInteger('accdist_score')->default(0);
            $table->decimal('avg_net_norm', 18, 8)->default(0);
            $table->decimal('avg5_net_norm', 18, 8)->default(0);
            $table->decimal('top1_net_norm', 18, 8)->default(0);
            $table->decimal('top3_net_norm', 18, 8)->default(0);
            $table->decimal('top5_net_norm', 18, 8)->default(0);
            $table->decimal('top10_net_norm', 18, 8)->default(0);
            $table->unsignedInteger('buyer_count')->default(0);
            $table->unsignedInteger('seller_count')->default(0);
            $table->unsignedInteger('active_broker_count')->default(0);
            $table->decimal('buyer_hhi', 18, 8)->default(0);
            $table->decimal('seller_hhi', 18, 8)->default(0);
            $table->decimal('top1_sell_share', 18, 8)->default(0);
            $table->decimal('top3_sell_share', 18, 8)->default(0);
            $table->decimal('top5_sell_share', 18, 8)->default(0);
            $table->decimal('net_to_gross_ratio', 18, 8)->default(0);
            $table->decimal('foreign_net_norm', 18, 8)->default(0);
            $table->decimal('local_net_norm', 18, 8)->default(0);
            $table->decimal('gov_net_norm', 18, 8)->default(0);

            $table->boolean('absorption_flag')->default(false);
            $table->boolean('dist_breakdown')->default(false);
            $table->boolean('stealth_acc')->default(false);
            $table->boolean('bandar_dist_hard')->default(false);
            $table->boolean('valid_long_setup')->default(false);

            $table->unsignedTinyInteger('y_hit_5d')->nullable();
            $table->decimal('dd_5d', 18, 8)->nullable();

            $table->timestamps();

            $table->primary(['symbol', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('features_daily');
    }
};
