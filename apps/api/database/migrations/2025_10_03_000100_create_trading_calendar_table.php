<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_calendar', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->boolean('is_trading_day')->default(false)->index();
            $table->boolean('is_weekend')->default(false)->index();
            $table->boolean('is_holiday')->default(false)->index();
            $table->string('holiday_reason', 255)->nullable();
            $table->string('source', 32)->default('^JKSE');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_calendar');
    }
};
