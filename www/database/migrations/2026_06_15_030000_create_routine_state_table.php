<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The data-changed GATE state, one row per routine set. Before the (expensive) chain runs, the runner
 * fingerprints the INPUT (per-coin `indicators` signature + the `rules` signature) and compares it to
 * `fingerprint` here. Unchanged → skip (nothing new can come of it). Changed (new data OR a rule
 * change from the previous run / a manual edit) → run, then store the new fingerprint. `last_checked_at`
 * updates on every check (even a skip); `last_ran_at` only when the chain actually executed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routine_state', function (Blueprint $table) {
            $table->id();
            $table->string('set_key', 48)->unique();
            $table->string('fingerprint', 64)->nullable();      // md5 of the input signature
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_ran_at')->nullable();
            $table->string('last_outcome', 160)->nullable();    // "5 toegepast" / "geen wijziging"
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routine_state');
    }
};
