<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trading-engine rebuild tables (Step 1: faithful rule-engine replay).
 *
 * The read-only `bot_signals` source is never touched. These tables live in the
 * brain app DB and hold the recomputed candidates, per-subrule values, and fires
 * so the /engine screens can be browsed. See docs/methodology/rule-boundary-method.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One evaluation run: a (symbol, main rule, period) replay.
        Schema::create('engine_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('trading_symbol_id');
            $table->string('symbol', 50)->nullable();
            $table->unsignedTinyInteger('rule_number');
            $table->dateTime('period_from');
            $table->dateTime('period_to');
            $table->unsignedInteger('candidates')->default(0);   // datetimes passing the volume gate
            $table->unsignedInteger('fires')->default(0);        // candidates where every subrule passed
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->index(['trading_symbol_id', 'rule_number']);
        });

        // One evaluated candidate datetime (volume-gated) within a run.
        Schema::create('engine_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('engine_runs')->cascadeOnDelete();
            $table->dateTime('datetime');
            $table->boolean('passed')->default(false);            // true = BUY (all subrules green)
            $table->smallInteger('failed_at_sort')->nullable();   // sort of the first failing subrule (null if passed)
            $table->decimal('price', 25, 12)->nullable();
            $table->index(['run_id', 'passed']);
            $table->index(['run_id', 'datetime']);
        });

        // One subrule value computed for one candidate (the "gevonden waardes" to inspect).
        Schema::create('engine_subrule_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signal_id')->constrained('engine_signals')->cascadeOnDelete();
            $table->smallInteger('sort');
            $table->unsignedInteger('subrule_id');                // wp_trading_rules.ID (source)
            $table->string('indicator', 30);
            $table->string('subrulename', 30);
            $table->decimal('def1', 8, 2)->nullable();
            $table->decimal('computed_value', 40, 20)->nullable();
            $table->decimal('b_min', 28, 10)->nullable();
            $table->decimal('b_max', 28, 10)->nullable();
            $table->boolean('passed')->nullable();                // green/red, or NULL = not yet evaluated
            $table->index(['signal_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engine_subrule_values');
        Schema::dropIfExists('engine_signals');
        Schema::dropIfExists('engine_runs');
    }
};
