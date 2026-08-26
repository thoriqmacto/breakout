<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broker_summary_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->date('trade_date');
            $table->string('broker_code', 20);
            $table->string('transaction_type', 50);
            $table->string('broker_type', 20)->nullable();
            $table->unsignedBigInteger('buy_lot')->default(0);
            $table->unsignedBigInteger('buy_volume')->default(0);
            $table->decimal('buy_value', 24, 2)->default(0);
            $table->decimal('buy_value_v', 24, 2)->default(0);
            $table->decimal('buy_avg_price', 18, 6)->nullable();
            $table->unsignedBigInteger('sell_lot')->default(0);
            $table->unsignedBigInteger('sell_volume')->default(0);
            $table->decimal('sell_value', 24, 2)->default(0);
            $table->decimal('sell_value_v', 24, 2)->default(0);
            $table->decimal('sell_avg_price', 18, 6)->nullable();
            $table->bigInteger('net_lot')->default(0);
            $table->bigInteger('net_volume')->default(0);
            $table->decimal('net_value', 24, 2)->default(0);
            $table->timestamps();

            // Named explicitly: the auto-generated name is 76 characters and
            // MySQL/MariaDB cap identifiers at 64. SQLite has no such limit.
            $table->unique(
                ['asset_id', 'trade_date', 'broker_code', 'transaction_type'],
                'broker_summary_facts_unique',
            );
            $table->index(['asset_id', 'trade_date']);
            $table->index(['asset_id', 'broker_code']);
        });

        Schema::create('bandar_detector_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('transaction_type', 50);
            $table->string('broker_accdist', 20)->nullable();
            // Signed: this is buyers minus sellers, and more sellers than
            // buyers is the normal way to express distribution. It was
            // unsignedInteger until 2026_08_26_000000, which MariaDB rejected
            // outright. total_buyer and total_seller are counts, so they stay
            // unsigned.
            $table->integer('number_broker_buysell')->nullable();
            $table->unsignedInteger('total_buyer')->nullable();
            $table->unsignedInteger('total_seller')->nullable();
            $table->decimal('value', 24, 2)->nullable();
            $table->unsignedBigInteger('volume')->nullable();
            $table->decimal('average_price', 18, 6)->nullable();
            $table->json('metrics_json')->nullable();
            $table->timestamps();

            // Same 64-char identifier limit as above.
            $table->unique(
                ['asset_id', 'from_date', 'to_date', 'transaction_type'],
                'bandar_detector_summaries_unique',
            );
            $table->index(['asset_id', 'to_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bandar_detector_summaries');
        Schema::dropIfExists('broker_summary_facts');
    }
};
