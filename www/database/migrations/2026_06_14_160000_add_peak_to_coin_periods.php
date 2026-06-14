<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The peak (highest-price) datetime of a promising period's best entry — the ideal
 * "sell"/exit moment. Shown on the coin-explorer graph as a vertical line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coin_periods', function (Blueprint $table) {
            $table->dateTime('peak_datetime')->nullable()->after('best_lowest10');
        });
    }

    public function down(): void
    {
        Schema::table('coin_periods', function (Blueprint $table) {
            $table->dropColumn('peak_datetime');
        });
    }
};
