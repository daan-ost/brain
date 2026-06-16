<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-moment sell-engine outcome (Epic L, punt 4). For EVERY promising moment (not just the rule-fires
 * in coin_fires) we want to run the sell-engine "as if bought here" and store the result, so the labeler
 * can show the realised P&L next to the buy-quality (upside). Keyed by (coin, datetime).
 *
 * PREPARED, not yet populated: `engine/src/sell_promising.py` fills this — but only once the sell-engine
 * is improved (it's parked, Epic S). Until then the labeler falls back to the executed-fire profit_loss.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coin_moment_sells', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('trading_symbol_id');
            $table->string('symbol', 50)->nullable();
            $table->dateTime('datetime');                                  // the buy-moment
            $table->decimal('buy_price', 25, 12)->nullable();
            $table->decimal('selling_price', 25, 12)->nullable();
            $table->dateTime('selling_datetime')->nullable();
            $table->decimal('profit_loss', 12, 3)->nullable();             // realised %
            $table->decimal('hi_pl', 12, 3)->nullable();                   // highest favorable excursion %
            $table->decimal('lo_pl', 12, 3)->nullable();                   // worst drawdown %
            $table->decimal('best_sell_price', 25, 12)->nullable();        // best reachable price in the hold
            $table->dateTime('best_sell_datetime')->nullable();
            $table->unsignedSmallInteger('minutes_in_trade')->nullable();
            $table->string('sell_version', 40)->nullable();                // which sell-engine produced it
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
            $table->unique(['trading_symbol_id', 'datetime'], 'cms_natural');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coin_moment_sells');
    }
};
