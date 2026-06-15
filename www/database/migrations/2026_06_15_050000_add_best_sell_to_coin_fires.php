<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * best_sell = the best price the sell-engine COULD have sold at within our own hold
 * [buy, our sell] — the max favorable excursion until our (imperfect) exit. The gap between
 * best_sell and selling_price is exactly what the sell-engine left on the table.
 * Produced by sell_engine.py (hi_price / hi_dt), persisted via persist_to_brain.py.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coin_fires', function (Blueprint $table) {
            $table->decimal('best_sell_price', 25, 12)->nullable()->after('selling_price');
            $table->dateTime('best_sell_datetime')->nullable()->after('best_sell_price');
        });
    }

    public function down(): void
    {
        Schema::table('coin_fires', function (Blueprint $table) {
            $table->dropColumn(['best_sell_price', 'best_sell_datetime']);
        });
    }
};
