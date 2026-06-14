<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * best_upside = the max favorable excursion within the hold (the best price you could have sold
 * at, vs buy). This is the trade's QUALITY — independent of our (imperfect) sell-engine. Daan:
 * "goed is minstens 3% winst tov de beste verkoopprijs". So the goed/middel/slecht label is
 * derived from best_upside, not from the realised sell P&L.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coin_fires', function (Blueprint $table) {
            $table->decimal('best_upside', 12, 3)->nullable()->after('selling_price');
        });
    }

    public function down(): void
    {
        Schema::table('coin_fires', function (Blueprint $table) {
            $table->dropColumn('best_upside');
        });
    }
};
