<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Epic H — de actieve/inactieve perioden per munt: de geoperationaliseerde regime-gate.
 *
 * Per munt een rij per aaneengesloten interval met dezelfde stand (active/inactive). Samen dekken de
 * intervallen de hele verhandelde historie van die munt. De 'pre'-weken vóór de eerste trade-week
 * krijgen GEEN rij (de munt bestond/handelde nog niet). Bron-van-waarheid = `coin_regime.py`, dat het
 * gate-algoritme uit `Coins/Weekly.php::applyGate()` bit-gelijk spiegelt en deze tabel idempotent
 * herschrijft (DELETE munt + INSERT) + een JSON-spiegel exporteert (engine/data/coin_regime.json).
 *
 *   state          = 'active' (we traden) / 'inactive' (pauze) — de aan/uit-streep in /coins/weekly
 *   rolling_result = Σ profit_loss over het rollende 4-weken-venster op de laatste week van het interval
 *   reason         = leesbare reden (zelfde tekst als de UI: "aan · rollend X%" / "maandtempo < 20%" …)
 *   computed_at    = wanneer dit interval berekend is (geen Laravel-timestamps: de hele munt wordt per
 *                    herberekening herschreven, dus per-rij created/updated zijn niet zinvol)
 *
 * De FILTER (opt_lib/load_trades + de schermen) telt een trade alleen NIET mee als hij in een
 * 'inactive'-interval valt; een munt zónder rijen (net ingeladen, regime nog niet berekend) telt dus
 * volledig mee = default actief. Index afgestemd op die NOT-EXISTS-subquery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coin_regime', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('trading_symbol_id');
            $table->date('period_from');                          // maandag van de eerste week in dit interval
            $table->date('period_to');                            // zondag van de laatste week in dit interval
            $table->enum('state', ['active', 'inactive']);
            $table->string('reason')->nullable();                 // leesbare reden (zelfde tekst als de UI-streep)
            $table->decimal('rolling_result', 10, 2)->nullable(); // rollend Σ profit_loss op de laatste week
            $table->timestamp('computed_at')->nullable();
            // de filter-subquery: WHERE trading_symbol_id=? AND state='inactive' AND dt BETWEEN from AND to
            $table->index(['trading_symbol_id', 'state', 'period_from', 'period_to'], 'cr_filter');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coin_regime');
    }
};
