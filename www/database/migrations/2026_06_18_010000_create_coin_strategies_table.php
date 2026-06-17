<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-coin override-laag voor de sell-engine instelknoppen (de tuning-routine, FASE 1).
 *
 * `strategies` is per RULE (PK rule_number) en wordt coin-loos gelezen door SellEngine. DOGEAI is
 * snel, NOS is traag — ze hebben andere instelknoppen nodig. Deze tabel legt een DUNNE override-laag
 * per (coin, rule) bovenop de globale `strategies`: SellEngine merget per-coin eroverheen, per-coin
 * wint mits NOT NULL. Spiegelt het bestaande `coin_rule_settings`-patroon (per-coin min_volume).
 *
 * FAITHFUL FIRST: zolang deze tabel leeg is verandert er NIETS — SellEngine valt terug op de globale
 * strategies (de faithful-test: sell_compare blijft byte-identiek). GEEN backfill hier: pas vullen op
 * het moment dat een coin daadwerkelijk getuned wordt, anders maakt elke deploy de globale fallback dood.
 *
 * sl_settings = dezelfde JSON-shape als strategies.sl_settings (alleen de afwijkende knobs hoeven erin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coin_strategies', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('trading_symbol_id');
            $table->unsignedSmallInteger('rule_number');
            $table->text('sl_settings')->nullable();   // JSON per (coin,rule); zelfde shape als strategies
            $table->timestamps();
            $table->unique(['trading_symbol_id', 'rule_number'], 'cs_coin_rule');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coin_strategies');
    }
};
