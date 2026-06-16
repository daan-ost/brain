<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual grouping override (Epic L). A group of buy-moments (one rise = one trade) is computed from
 * the ok-marked moments, splitting on a >5min gap or a >=1% drop. This per-moment override lets the
 * owner force the boundary by hand: 'break' = start a new group here (uncouple/split), 'join' = stay
 * with the previous group (couple, even if the auto-rules would split). null = auto. Lives on
 * coin_moment_labels so it survives the persist_to_brain re-fire (same as the labels).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coin_moment_labels', function (Blueprint $table) {
            $table->enum('group_break', ['break', 'join'])->nullable()->after('comment');
        });
    }

    public function down(): void
    {
        Schema::table('coin_moment_labels', function (Blueprint $table) {
            $table->dropColumn('group_break');
        });
    }
};
