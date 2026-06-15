<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational log of the automation routines (the daily rule-optimisation is routine #1; more
 * routines run after it in one ordered chain — see engine/src/routines.py). One `routine_runs` row
 * per execution of the chain; `routine_run_log` holds the human-readable lines per run
 * ("rule 21: kandidaat X — resultaat: ..."). The /routines screen reads these.
 *
 * Distinct from `rules_history` (which versions the rule DEFINITIONS). This is the run journal:
 * what each scheduled routine did and found, when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routine_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('run_date');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 16)->default('running');   // running|success|failed
            $table->unsignedSmallInteger('n_routines')->default(0);
            $table->text('summary')->nullable();                // one-line outcome of the whole chain
            $table->string('trigger', 32)->default('manual');   // routine|manual|api
            $table->timestamps();
            $table->index('run_date');
            $table->index('status');
        });

        Schema::create('routine_run_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('routine_run_id')->constrained('routine_runs')->cascadeOnDelete();
            $table->string('routine_key', 48);                  // 'rule-optimization', ...
            $table->unsignedSmallInteger('seq')->default(0);    // order within the run
            $table->string('level', 16)->default('info');       // info|finding|change|result|error
            $table->unsignedSmallInteger('rule_number')->nullable();
            $table->text('message');                            // human-readable line for the screen
            $table->json('data')->nullable();                   // structured payload (candidate, ratios, ...)
            $table->timestamp('created_at')->nullable();
            $table->index(['routine_run_id', 'seq']);
            $table->index('routine_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routine_run_log');
        Schema::dropIfExists('routine_runs');
    }
};
