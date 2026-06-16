<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The RECALL worklist: one row per promising GROUP (ok-marked moments grouped like the labeler).
 * Tracks whether a rule catches the group, which rule it is closest to (fewest failing subrules),
 * what blocks it (volume vs feature), and a per-group dossier of what has been TRIED — so a routine
 * can work the groups one by one and, once all options are exhausted, park "needs_new_rule" for a
 * later routine. "Data is alles": the factual fields are recomputed each run, but the routine-managed
 * fields (status / tried / resolution_note) are PRESERVED so the dossier accumulates.
 *
 * Natural key (trading_symbol_id, group_lead). Filled by engine/src/recall_worklist.py (read-only on
 * the rules). See brain-promising-labeler (the ok-labels + grouping = ground truth).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promising_recall_state', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('trading_symbol_id');
            $table->timestamp('group_lead');                         // stable group id = first ok-moment
            $table->timestamp('group_to')->nullable();
            $table->unsignedSmallInteger('n_moments')->default(1);
            $table->double('max_up_pct')->nullable();                // group quality (max best_upside in the group)
            $table->boolean('caught')->default(false);               // does an executed trade fall in the group
            $table->unsignedSmallInteger('home_rule')->nullable();   // fewest failing subrules
            $table->unsignedSmallInteger('home_rule_fails')->nullable();
            $table->json('candidate_rules')->nullable();             // {rule: #failing_subrules} — keep ALL options
            $table->string('blocker', 16)->nullable();               // caught | volume | feature
            $table->string('status', 24)->default('open');           // open|caught|in_progress|needs_new_rule|untradeable
            $table->json('tried')->nullable();                       // [{tweak, result, at}] — a routine fills this
            $table->text('resolution_note')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->unique(['trading_symbol_id', 'group_lead']);
            $table->index(['status', 'home_rule']);
            $table->index('blocker');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promising_recall_state');
    }
};
