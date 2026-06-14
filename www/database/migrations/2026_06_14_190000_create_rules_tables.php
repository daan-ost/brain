<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Our OWN rule definitions, in the brain DB. Seeded from the legacy wp_trading_rules as a
 * starting point (engine/src/seed_rules.py) but owned and tuned by us from here on. The engine
 * reads rules from here, not from bot_signals.
 *
 * A rule (rule_number, e.g. 20) is a flat AND of subrules; each subrule checks one
 * (indicator, subrulename, def1_value) value against [b_min, b_max].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedSmallInteger('rule_number');        // the rule (20/21/22/23/101)
            $table->unsignedSmallInteger('sort')->default(0);   // eval order (not groups)
            $table->string('indicator', 30)->nullable();
            $table->string('subrulename', 40);
            $table->double('def1_value')->nullable();           // window length / limit
            $table->double('b_min')->nullable();
            $table->double('b_max')->nullable();
            $table->text('value_condition')->nullable();        // JSON: which metric to select
            $table->string('operator', 30)->nullable();
            $table->text('condition_rule')->nullable();
            $table->boolean('active')->default(true);
            $table->string('source', 40)->default('legacy-seed');
            $table->unsignedInteger('legacy_id')->nullable();   // wp_trading_rules.ID (provenance)
            $table->timestamps();
            $table->index(['rule_number', 'active']);
        });

        // per-coin, per-rule settings the volume calc needs (min_volume drives check_volumeud_3).
        Schema::create('coin_rule_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('trading_symbol_id');
            $table->unsignedSmallInteger('rule_number');
            $table->double('min_volume')->nullable();
            $table->timestamps();
            $table->unique(['trading_symbol_id', 'rule_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coin_rule_settings');
        Schema::dropIfExists('rules');
    }
};
