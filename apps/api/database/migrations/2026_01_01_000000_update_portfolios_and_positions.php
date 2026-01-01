<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->string('remarks')->nullable()->after('name');
            $table->unsignedSmallInteger('year')->default((int) date('Y'))->after('remarks');
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->decimal('price', 16, 4)->default(0)->after('qty_shares');
            $table->decimal('fee_rate', 8, 4)->default(0)->after('price');
            $table->decimal('fee_value', 20, 4)->default(0)->after('fee_rate');
            $table->date('executed_at')->nullable()->after('fee_value');
        });

        $existingSides = DB::table('positions')->pluck('side', 'id');

        DB::table('positions')->update([
            'price' => DB::raw('avg_price'),
            'fee_value' => 0,
            'executed_at' => DB::raw('entry_date'),
        ]);

        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['trail', 'status', 'entry_date', 'side']);
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->string('side', 10)->default('entry')->after('asset_id');
            $table->index('executed_at');
        });

        foreach ($existingSides as $id => $side) {
            $normalized = strtolower((string) $side) === 'short' ? 'exit' : 'entry';
            DB::table('positions')->where('id', $id)->update(['side' => $normalized]);
        }
    }

    public function down(): void {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropColumn(['remarks', 'year']);
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->date('entry_date')->nullable()->after('avg_price');
            $table->decimal('trail', 16, 4)->nullable()->after('entry_date');
            $table->string('status', 20)->default('open')->after('trail');
        });

        DB::table('positions')->update([
            'entry_date' => DB::raw('executed_at'),
            'status' => 'open',
        ]);

        $existingSides = DB::table('positions')->pluck('side', 'id');

        Schema::table('positions', function (Blueprint $table) {
            $table->dropIndex(['executed_at']);
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['price', 'fee_rate', 'fee_value', 'executed_at', 'side']);
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->string('side', 4)->after('asset_id');
        });

        foreach ($existingSides as $id => $side) {
            $normalized = strtolower((string) $side) === 'exit' ? 'short' : 'long';
            DB::table('positions')->where('id', $id)->update(['side' => $normalized]);
        }
    }
};
