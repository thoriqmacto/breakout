<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->boolean('sync_price')->default(true)->after('profile_synced_at');
            $table->boolean('sync_profile')->default(true)->after('sync_price');
            $table->boolean('sync_broker_summary')->default(true)->after('sync_profile');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['sync_price', 'sync_profile', 'sync_broker_summary']);
        });
    }
};
