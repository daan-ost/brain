<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per (coin, dag) de kansrijkheid-maten — het fundament om coins te sorteren op "meest kansrijk".
 *
 * up_pct  = % van de momenten die dag waarop de prijs binnen 60 min nog >=3% stijgt (de KANSRIJK-score).
 *           Gekozen omdat dit cross-coin het sterkst met winst-per-trade correleert (DOGEAI 0,94 / NOS 0,50),
 *           sterker dan std-log-returns (0,71 / 0,31) en veel sterker dan volume (0,71 / -0,04).
 * vol_pct = std van 1-min log-returns x100 — algemene beweeglijkheid (prijs), secundair.
 * n_ticks = aantal volumeud-ticks die dag — activiteit / liquiditeit (de filter, NIET de sorteersleutel).
 * up_7d / vol_7d = 7-daags voortschrijdend gemiddelde; up_7d is de sorteersleutel voor de ranking.
 *
 * Read-only fundament: er verandert niets aan rules of trades. Een dagelijkse routine vult dit.
 * Schaalt 1-op-1 naar meer coins (de routine pakt elke coin in `indicators` automatisch mee).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coin_daily_metrics', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('trading_symbol_id');
            $table->date('date');
            $table->decimal('up_pct', 6, 2)->nullable();    // % momenten >=3% stijging binnen 60 min (kansrijk)
            $table->decimal('vol_pct', 8, 5)->nullable();   // std 1-min log-returns x100 (beweeglijkheid)
            $table->unsignedInteger('n_ticks');             // volumeud-ticks die dag (liquiditeit)
            $table->decimal('up_7d', 6, 2)->nullable();     // 7-daags gem. van up_pct (de sorteersleutel)
            $table->decimal('vol_7d', 8, 5)->nullable();    // 7-daags gem. van vol_pct
            $table->timestamps();
            $table->unique(['trading_symbol_id', 'date'], 'cdm_coin_date');
            $table->index(['trading_symbol_id', 'date'], 'cdm_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coin_daily_metrics');
    }
};
