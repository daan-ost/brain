<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-moment hand labels (Epic L). Lives SEPARATELY from coin_fires on purpose:
 * engine/src/persist_to_brain.py does `DELETE FROM coin_fires` on every re-fire, so any
 * label stored on coin_fires.manual_klasse is wiped the next time a routine runs. This table
 * is never touched by the re-fire — labels survive. Natural key (coin, datetime, rule, source)
 * lets a label rejoin its fire after a rebuild, and keeps imported legacy labels (source=legacy)
 * separate from Daan's own (source=manual).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coin_moment_labels', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('trading_symbol_id');
            $table->string('symbol', 50)->nullable();
            $table->dateTime('datetime');
            $table->unsignedSmallInteger('rule');

            $table->enum('decision', ['yes', 'no', 'no_volume'])->nullable();   // legacy ok_trade
            $table->enum('manual_klasse', ['goed', 'middel', 'slecht'])->nullable(); // overrides klasseKey()
            $table->string('category', 80)->nullable();                          // reden (CoinAnnotation::CATEGORIES)
            $table->text('comment')->nullable();

            $table->enum('source', ['manual', 'legacy'])->default('manual');
            $table->unsignedTinyInteger('legacy_result')->nullable();            // 1/2/3 at import

            $table->string('set_by', 120)->nullable();
            $table->timestamp('set_at')->nullable();
            $table->timestamps();

            // one label per (coin, datetime, rule) per source
            $table->unique(['trading_symbol_id', 'datetime', 'rule', 'source'], 'cml_natural');
            // fast lookup when attaching labels to a day's fires
            $table->index(['trading_symbol_id', 'datetime', 'rule'], 'cml_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coin_moment_labels');
    }
};
