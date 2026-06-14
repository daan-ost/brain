<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The clean architecture (Daan): the ONLY thing we take from the read-only legacy bot_signals
 * is the raw indicator series (+ the minimal per-coin settings the calculations need). Everything
 * else — rules, fires, promising, good/bad — is rebuilt by us in the brain DB, and the screens
 * read ONLY from brain. These two tables are the imported source; engine/src/import_indicators.py
 * copies DOGEAI + NOS into them.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The raw indicator series, copied from bot_signals.wp_trading_indicator (the one import).
        Schema::create('indicators', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('trading_symbol_id');
            $table->string('symbol', 50)->nullable();
            $table->string('indicator', 30);
            $table->dateTime('datetime');
            $table->double('value')->nullable();
            $table->double('price')->nullable();           // only volumeud carries price
            $table->boolean('volume_found')->default(false);
            $table->index(['trading_symbol_id', 'indicator', 'datetime'], 'idx_sym_ind_dt');
        });

        // Minimal per-coin settings the calculations/selling need (not indicators, but essential).
        Schema::create('coins', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();       // = legacy trading_symbol_id
            $table->string('symbol', 50);
            $table->unsignedSmallInteger('timeframe')->nullable();
            $table->double('stoploss_multiplier')->nullable();
            $table->unsignedSmallInteger('roundingup')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicators');
        Schema::dropIfExists('coins');
    }
};
