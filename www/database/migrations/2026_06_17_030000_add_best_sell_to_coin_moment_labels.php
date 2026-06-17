<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable best-sell date per trade (Epic S / sell-analyse). The sell-engine computes the highest
 * reachable sell over the full window (may lie AFTER our own exit); legacy already recorded one in
 * wp_trading_simulation.best_selling_datetime. We store the OVERRIDE here in coin_moment_labels
 * (survives a re-fire, same place as the outcome-override): NULL = use the engine's computed value,
 * set = the legacy-migrated or hand-edited best-sell moment. Keyed by the existing (coin, datetime, rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coin_moment_labels', function (Blueprint $table) {
            $table->dateTime('best_sell_datetime')->nullable()->after('group_break');
        });
    }

    public function down(): void
    {
        Schema::table('coin_moment_labels', function (Blueprint $table) {
            $table->dropColumn('best_sell_datetime');
        });
    }
};
