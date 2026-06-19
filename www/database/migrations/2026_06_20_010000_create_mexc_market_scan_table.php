<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot-tabel voor de dagelijkse MEXC-marktscan: volatiele, handelbare USDT-paren als rotatie-
 * kandidaten. Wordt elke run getruncate+herschreven (atomair, pas NA geslaagde fetch). Geen FK
 * naar coins — dit zijn EXTERNE kandidaten die (nog) niet in de engine zitten.
 *
 * Sorteersleutel = volat_pct (24u prijs-range). Volume + mcap = liquiditeit-filters.
 * Marketcap via CoinGecko (MEXC heeft geen native mcap); leeftijd via MEXC firstOpenTime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mexc_market_scan', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('symbol', 40);                        // ASTEROIDUSDT
            $t->string('base', 30);                          // ASTEROID
            $t->string('quote', 12)->default('USDT');
            $t->decimal('price', 24, 10)->nullable();
            $t->decimal('change24h_pct', 10, 2)->nullable(); // priceChangePercent
            $t->decimal('volat_pct', 10, 2)->nullable();     // (high-low)/low*100 — sorteersleutel
            $t->decimal('vol24h_usd', 20, 2)->nullable();    // quoteVolume — liquiditeit-filter
            $t->decimal('mcap_usd', 20, 2)->nullable();      // CoinGecko market_cap (null = onbekend)
            $t->unsignedInteger('age_days')->nullable();     // uit firstOpenTime (of kline-fallback)
            $t->string('age_source', 20)->nullable();        // 'firstOpenTime' | 'kline' | 'unknown'
            $t->string('contract', 120)->nullable();         // MEXC contractAddress
            $t->string('cg_id', 80)->nullable();             // CoinGecko id (join-traceability)
            $t->string('status', 20)->nullable();            // MEXC status (genormaliseerd)
            $t->timestamp('fetched_at');                     // gedeeld per scan
            $t->timestamps();
            $t->unique('symbol', 'mms_symbol');
            $t->index('volat_pct', 'mms_volat');
            $t->index(['mcap_usd', 'vol24h_usd'], 'mms_filters');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mexc_market_scan');
    }
};
