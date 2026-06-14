<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coin-explorer tables (Epic A): the promising periods and the rule-fires, persisted to
 * the brain DB so the day-navigator screens can browse a coin day by day without
 * recomputing in Python. The read-only `bot_signals` source is never written to — these
 * are produced by engine/src/persist_to_brain.py. Price for the chart is read live from
 * the bot_signals connection; only periods + fires live here.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One promising period = a clustered good-trade window (one best entry per rise).
        Schema::create('coin_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('trading_symbol_id');
            $table->string('symbol', 50)->nullable();
            $table->dateTime('period_from');
            $table->dateTime('period_to');
            $table->dateTime('best_entry');
            $table->decimal('best_upside', 12, 3)->nullable();      // % peak upside of the best entry
            $table->decimal('best_lowest10', 12, 3)->nullable();    // % early dip of the best entry
            $table->unsignedInteger('n_moments')->default(0);       // raw promising moments collapsed
            $table->unsignedSmallInteger('gap_minutes')->default(15);
            $table->string('label_version', 60)->nullable();        // params hash for reproducibility
            $table->timestamps();
            $table->index(['trading_symbol_id', 'period_from']);
            $table->index(['trading_symbol_id', 'best_entry']);
        });

        // One rule-fire = a recorded legacy trade (20/21/22/23), with its promising membership.
        Schema::create('coin_fires', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('trading_symbol_id');
            $table->string('symbol', 50)->nullable();
            $table->dateTime('datetime');
            $table->unsignedSmallInteger('rule');
            $table->unsignedTinyInteger('result')->nullable();      // 1 goed / 2 middel / 3 slecht / null
            $table->boolean('in_good_period')->default(false);      // inside a promising period? (the automatable good/bad label)
            $table->foreignId('period_id')->nullable()->constrained('coin_periods')->nullOnDelete();
            $table->decimal('profit_loss', 12, 3)->nullable();
            $table->decimal('buy_price', 25, 12)->nullable();
            $table->dateTime('selling_datetime')->nullable();
            $table->timestamps();
            $table->index(['trading_symbol_id', 'datetime']);
            $table->index(['trading_symbol_id', 'rule']);
            $table->index(['trading_symbol_id', 'in_good_period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coin_fires');
        Schema::dropIfExists('coin_periods');
    }
};
