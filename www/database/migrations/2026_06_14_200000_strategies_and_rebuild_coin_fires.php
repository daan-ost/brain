<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fire-rebuild (A): coin_fires become OUR fires (from rule_engine over brain.indicators),
 * with position dedup (one trade at a time → executed vs shadow) and OUR sell-engine P&L.
 * The legacy result is kept only as an optional offline-comparison reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        // per-rule stop-loss settings (seeded from legacy wp_trading_allrules; owned by us)
        Schema::create('strategies', function (Blueprint $table) {
            $table->unsignedSmallInteger('rule_number')->primary();
            $table->text('sl_settings')->nullable();          // JSON: min_sl1, minutes_in_trade1, ...
            $table->timestamps();
        });

        Schema::table('coin_fires', function (Blueprint $table) {
            $table->boolean('is_executed')->default(true)->after('in_good_period');   // opened a position (not a shadow)
            $table->dateTime('shadow_parent')->nullable()->after('is_executed');       // the open trade it sits inside
            $table->decimal('selling_price', 25, 12)->nullable()->after('buy_price');
            $table->unsignedTinyInteger('legacy_result')->nullable()->after('shadow_parent'); // offline reference only
            $table->decimal('legacy_profit_loss', 12, 3)->nullable()->after('legacy_result');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategies');
        Schema::table('coin_fires', function (Blueprint $table) {
            $table->dropColumn(['is_executed', 'shadow_parent', 'selling_price', 'legacy_result', 'legacy_profit_loss']);
        });
    }
};
