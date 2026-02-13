<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('broksums', 'buy_avg_price')) {
            Schema::table('broksums', function (Blueprint $table) {
                $table->decimal('buy_avg_price', 18, 6)
                    ->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('broksums', 'buy_avg_price')) {
            Schema::table('broksums', function (Blueprint $table) {
                $table->dropColumn('buy_avg_price');
            });
        }
    }
};
