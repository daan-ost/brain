<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * brain-eigen volume_found vlag, naast de legacy-gekopieerde `volume_found`. De motor gebruikt nog
 * `volume_found` (legacy); dit is een SHADOW-veld voor het meten wat er zou gebeuren als we omschakelen.
 * Berekening: engine/src/compute_volume_found.py (check_volumeud_3 met per-rule overrides; ANY rule 20-23
 * slaagt -> 1). Voor toekomstige coins (TradingView) is dit de enige bron — legacy heeft die niet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            $table->boolean('brain_volume_found')->default(false)->after('volume_found');
            $table->index(['trading_symbol_id', 'indicator', 'brain_volume_found'], 'idx_brain_vf');
        });
    }

    public function down(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            $table->dropIndex('idx_brain_vf');
            $table->dropColumn('brain_volume_found');
        });
    }
};
