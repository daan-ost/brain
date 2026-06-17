<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Handmatige overrides + audit log voor de sell-engine.
 *
 *  - coin_moment_labels.hard_sell_datetime — "verkoop UITERLIJK op deze datum". De sell-engine
 *    respecteert dit: verkopen op die datum, of eerder bij een drop. Handmatig per trade gezet.
 *  - coin_moment_labels.manual_set_at — timestamp van een handmatige aanpassing (klasse,
 *    best_sell_datetime, hard_sell_datetime). Aanwezig => handmatig = leidend, automatische
 *    heranalyse mag dit niet overschrijven.
 *  - coin_fires_changelog — log per trade van klasse-veranderingen door heranalyse (slecht->goed
 *    etc.). Wordt gevuld door persist_to_brain.py; toont waarom een trade van klasse veranderde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coin_moment_labels', function (Blueprint $table) {
            $table->dateTime('hard_sell_datetime')->nullable()->after('best_sell_datetime');
            $table->timestamp('manual_set_at')->nullable()->after('set_at');
        });

        Schema::create('coin_fires_changelog', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('trading_symbol_id');
            $table->string('symbol', 50)->nullable();
            $table->dateTime('datetime');                           // de trade (koop)
            $table->string('field', 40);                            // bv "klasse", "profit_loss"
            $table->string('old_value', 60)->nullable();
            $table->string('new_value', 60)->nullable();
            $table->string('reason', 80)->nullable();               // "sell-engine-rerun", "manual", ...
            $table->timestamps();
            $table->index(['trading_symbol_id', 'datetime'], 'cfc_trade');
        });
    }

    public function down(): void
    {
        Schema::table('coin_moment_labels', function (Blueprint $table) {
            $table->dropColumn(['hard_sell_datetime', 'manual_set_at']);
        });
        Schema::dropIfExists('coin_fires_changelog');
    }
};
