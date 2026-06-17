<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-tick sell trail (Epic S — Daan's model: "store, per datetime, all values"). For a buy-moment
 * (coin, datetime) the sell-engine walks the price forward and at EACH volumeud tick records what every
 * mechanism wanted: the absolute floor (minimum_price), the lock_profit ratchet (lock_price), the
 * rule-101 multiplier, and the resulting stop (stoploss_price). One row per tick. Lets us see the max
 * price reached during the hold and later automate the best sell-moment / hard-drop window by hand-free
 * analysis, and is the instrument to debug the remaining rule-101 sell-signal timing.
 *
 * Filled by engine/src/sell_ticks.py via SellEngine.sell(trace=True), for fires + promising moments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coin_sell_ticks', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('trading_symbol_id');
            $table->string('symbol', 50)->nullable();
            $table->dateTime('datetime');                                  // the buy-moment (trade key)
            $table->dateTime('tick_datetime');                             // this tick
            $table->decimal('minutes_in_trade', 8, 2)->nullable();
            $table->decimal('marketprice', 25, 12)->nullable();
            $table->decimal('profit', 12, 3)->nullable();                  // % vs buy at this tick
            $table->decimal('highest_profit', 12, 3)->nullable();          // peak % so far (drives the ratchet)
            $table->decimal('minimum_price', 25, 12)->nullable();          // absolute floor (min_sl1 * buy)
            $table->decimal('lock_price', 25, 12)->nullable();             // lock_profit ratchet output (pre-clamp)
            $table->decimal('rule101_mult', 16, 8)->nullable();            // rule-101 stop multiplier (null = empty)
            $table->decimal('stoploss_price', 25, 12)->nullable();         // the SL carried out of this tick
            $table->decimal('selling_price', 25, 12)->nullable();
            $table->string('orderstatus', 10)->nullable();                 // hold | sell
            $table->string('sell_version', 40)->nullable();
            $table->timestamps();
            $table->index(['trading_symbol_id', 'datetime'], 'cst_trade');             // the trail of one trade
            $table->unique(['trading_symbol_id', 'datetime', 'tick_datetime'], 'cst_natural');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coin_sell_ticks');
    }
};
