<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-horizon upside per buy-moment (Epic L). For each fire we record what the upside was at
 * +5/+10/+15/+30/+45/+60 min (the max favorable excursion within each TRUE time window, with the
 * peak price + time), plus lowest10 (the early dip over the first ~10 ticks). This is buy-moment
 * quality, sell-INdependent — the labeler shows it next to the realised profit_loss so a high
 * upside with a negative profit_loss reads as a sell-engine defect, not a bad buy-moment.
 * Filled by engine/src/persist_to_brain.py.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coin_fires', function (Blueprint $table) {
            // {"5":{"up":..,"peak_px":..,"peak_at":".."}, "10":{...}, ...}
            $table->json('horizons')->nullable()->after('best_upside');
            $table->decimal('lowest10', 12, 3)->nullable()->after('horizons'); // % early dip (first ~10 ticks)
        });
    }

    public function down(): void
    {
        Schema::table('coin_fires', function (Blueprint $table) {
            $table->dropColumn(['horizons', 'lowest10']);
        });
    }
};
