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
        Schema::table('features_daily', function (Blueprint $table) {
            $table->unsignedTinyInteger('pbas')->default(0)->after('valid_long_setup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('features_daily', function (Blueprint $table) {
            $table->dropColumn('pbas');
        });
    }
};
