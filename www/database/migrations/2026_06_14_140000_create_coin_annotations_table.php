<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual annotations on promising periods and rule-fires (Epic A / Epic R input).
 *
 * Daan flags promising trades / fires that look good on paper but won't work in practice
 * (too-fast rise, too volatile, not executable on the exchange, ...). These labels become
 * the target for rule discovery: find a feature that filters them out of the promising set.
 * Lives in the brain DB; the legacy `wp_trading_simulation.remark` is shown read-only beside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coin_annotations', function (Blueprint $table) {
            $table->id();
            $table->string('target_type', 12);                 // 'fire' | 'period'
            $table->unsignedBigInteger('target_id');           // coin_fires.id / coin_periods.id
            $table->unsignedInteger('trading_symbol_id');
            $table->string('symbol', 50)->nullable();
            $table->dateTime('target_datetime')->nullable();    // best_entry / fire datetime
            $table->string('category', 60)->nullable();         // pulldown value
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['target_type', 'target_id']);
            $table->index(['trading_symbol_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coin_annotations');
    }
};
