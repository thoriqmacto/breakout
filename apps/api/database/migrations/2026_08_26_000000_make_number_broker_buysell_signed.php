<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * number_broker_buysell holds buyers minus sellers, so it is signed.
 *
 * It was declared unsignedInteger, which contradicts what the column means.
 * Stockbit reported 29 buyers and 56 sellers for one window, giving -27, and
 * MariaDB rejected the insert:
 *
 *   SQLSTATE[22003]: Numeric value out of range: 1264
 *   Out of range value for column 'number_broker_buysell' at row 1
 *
 * Unlike buy_lot, taking abs() here would be wrong: it would turn "27 more
 * sellers than buyers" into its opposite. The column type is the error.
 *
 * total_buyer and total_seller are left unsigned. Those are counts of brokers
 * and cannot meaningfully go below zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bandar_detector_summaries', function (Blueprint $table) {
            $table->integer('number_broker_buysell')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rolling back fails if any negative value has been stored by then,
        // which is the normal state for this column -- the constraint being
        // removed is the bug.
        Schema::table('bandar_detector_summaries', function (Blueprint $table) {
            $table->unsignedInteger('number_broker_buysell')->nullable()->change();
        });
    }
};
