<?php

// database/migrations/2025_08_29_152202_create_assets_and_prices_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('symbol')->unique(); // e.g., ANTM, ASII
            $table->string('name')->nullable();
            $table->integer('lot_size')->default(100);
            $table->decimal('tick_size', 10, 4)->default(1);
            $table->timestamps();
        });

        Schema::create('prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->date('date');                       // YYYY-MM-DD
            $table->decimal('open', 16, 4)->nullable();
            $table->decimal('high', 16, 4)->nullable();
            $table->decimal('low', 16, 4)->nullable();
            $table->decimal('close', 16, 4)->nullable();
            $table->unsignedBigInteger('volume')->nullable();
            $table->unique(['asset_id','date']);
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('prices');
        Schema::dropIfExists('assets');
    }
};
