<?php

// database/migrations/2025_08_29_152202_create_assets_and_price_bars_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('symbol')->unique();
            $table->string('name')->nullable();
            $table->string('sector')->nullable();
            $table->decimal('float', 20, 4)->nullable();
            $table->decimal('ipo_price', 20, 4)->nullable();
            $table->decimal('marketcap', 20, 4)->nullable();
        });

        Schema::create('price_bars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->date('date');
            $table->decimal('open', 16, 4)->nullable();
            $table->decimal('high', 16, 4)->nullable();
            $table->decimal('low', 16, 4)->nullable();
            $table->decimal('close', 16, 4)->nullable();
            $table->unsignedBigInteger('volume')->nullable();
            $table->timestamps();
            $table->unique(['asset_id', 'date']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('price_bars');
        Schema::dropIfExists('assets');
    }
};
