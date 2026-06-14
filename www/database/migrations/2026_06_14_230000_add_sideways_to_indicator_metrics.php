<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sideways band (checkSideWays) — the only one of the "special" Test types that fits the
 * per-(indicator,lookback) cache. (trend_up_and_down, increase_all_indicators and
 * profit_change_compared_to_current were never implemented in legacy; fast_increase is a
 * price-only fixed-window classifier, kept as calc.fast_increase() for rule-eval, not cached.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('indicator_metrics', function (Blueprint $table) {
            $table->double('sideways_upper')->nullable()->after('max_same_value');
            $table->double('sideways_lower')->nullable()->after('sideways_upper');
        });
    }

    public function down(): void
    {
        Schema::table('indicator_metrics', function (Blueprint $table) {
            $table->dropColumn(['sideways_upper', 'sideways_lower']);
        });
    }
};
