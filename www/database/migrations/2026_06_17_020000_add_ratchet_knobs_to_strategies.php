<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make the full sell-engine configurable from data (Daan's model — "deze moeten instelbaar zijn").
 * The lock_profit ratchet (hp_setting6/7) and the CHECK-2 age/profit ladder (array_profit) ran on
 * code defaults; this writes them explicitly into strategies.sl_settings so they can be tuned per
 * rule. Values = the legacy-effective ones (hp6=4, hp7=15 hard-override, the standard ladder), so
 * the engine output does not change — only the knobs become visible/editable. Idempotent (+= keeps
 * any existing key).
 */
return new class extends Migration
{
    private array $knobs = [
        'array_profit' => [[5, -0.4], [7, -0.1], [8, 0], [20, 0.5]],
        'hp_setting6' => '4',
        'hp_setting7' => '15',
    ];

    public function up(): void
    {
        foreach (DB::table('strategies')->whereNotNull('sl_settings')->get() as $s) {
            $j = json_decode($s->sl_settings, true) ?: [];
            $j += $this->knobs;
            DB::table('strategies')->where('rule_number', $s->rule_number)
                ->update(['sl_settings' => json_encode($j), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('strategies')->whereNotNull('sl_settings')->get() as $s) {
            $j = json_decode($s->sl_settings, true) ?: [];
            foreach (array_keys($this->knobs) as $k) {
                unset($j[$k]);
            }
            DB::table('strategies')->where('rule_number', $s->rule_number)
                ->update(['sl_settings' => json_encode($j), 'updated_at' => now()]);
        }
    }
};
