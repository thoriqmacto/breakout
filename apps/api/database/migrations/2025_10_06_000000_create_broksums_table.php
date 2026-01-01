<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('broksums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->date('date');
            $table->string('broker', 20);
            $table->decimal('net_value', 20, 2);
            $table->decimal('buy_value', 20, 2);
            $table->decimal('sell_value', 20, 2);
            $table->timestamps();

            $table->unique(['asset_id', 'date', 'broker']);
            $table->index(['date', 'broker']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broksums');
    }
};
