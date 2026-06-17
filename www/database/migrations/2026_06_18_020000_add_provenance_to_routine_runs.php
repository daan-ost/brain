<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reproduceerbaarheid (provenance) per routine-run — nodig zodra de sell-tuning-routine zelf
 * instelknoppen mag wijzigen (Daan: "straks mag die echt zelf instellingen wijzigen, wel in een
 * log wegschrijven wat gedaan is"). Met deze kolommen kun je later exact reconstrueren WELKE
 * knob-versie WELKE trades produceerde.
 *
 * Onderscheid: `routine_state.fingerprint` is een set-niveau INPUT-gate (md5 van indicators+rules),
 * niet per-run en zonder output of code-versie. Dit is per-run provenance op de tabel waar al één
 * rij per uitvoering staat.
 *
 * - input_hash    md5 van de geteste input-set (trades + knobs-baseline)
 * - output_hash   md5 van het uitkomst-blok (W/V, Σprofit, klasse-verdeling) — MOET deterministisch:
 *                 sorteer + rond de velden vóór het hashen, anders is reproduceerbaarheid waardeloos.
 * - knobs_version welke knob-variant deze run draaide (bv 'r21-min_sl1=0.985')
 * - git_sha       engine code-versie ten tijde van de run
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routine_runs', function (Blueprint $table) {
            $table->string('input_hash', 64)->nullable()->after('summary');
            $table->string('output_hash', 64)->nullable()->after('input_hash');
            $table->string('knobs_version', 40)->nullable()->after('output_hash');
            $table->string('git_sha', 40)->nullable()->after('knobs_version');
        });
    }

    public function down(): void
    {
        Schema::table('routine_runs', function (Blueprint $table) {
            $table->dropColumn(['input_hash', 'output_hash', 'knobs_version', 'git_sha']);
        });
    }
};
