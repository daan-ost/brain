<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail of every change to `rules`. Each change-event bumps a monotonic `version`;
 * for every rule that changed in that event we write ONE row holding BOTH a full snapshot of that
 * rule's subrules (redundant → trivial point-in-time + diffing, no delta-replay) AND the specific
 * diff vs the previous version, plus a per-rule human `toelichting` (why it changed).
 *
 * Reconstruct rule R at version N = latest row for R with version <= N (its `snapshot`).
 * Written by engine/src/rules_history.py, called from add_tuned_subrules.py / seed_rules.py.
 * Never updated or deleted — it is the provenance log for the tuned rule-set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rules_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('version');                 // monotonic change-batch number
            $table->timestamp('changed_at');
            $table->unsignedSmallInteger('rule_number');        // which main rule this row describes
            $table->string('change_type', 32);                  // initial|add_subrule|modify_subrule|remove_subrule|mixed
            $table->json('snapshot');                           // full subrule set of THIS rule at this version
            $table->json('diff')->nullable();                   // added/removed/modified subrules vs previous version
            $table->text('toelichting')->nullable();            // per-rule explanation / rationale
            $table->string('source', 64)->nullable();           // legacy-seed|tuned-precision|rq1-report|...
            $table->string('author', 64)->nullable();           // claude|daan
            $table->timestamps();
            $table->index(['rule_number', 'version']);
            $table->index('version');
            $table->index('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rules_history');
    }
};
