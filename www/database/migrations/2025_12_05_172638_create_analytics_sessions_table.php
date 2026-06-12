<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('analytics_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('session_group_id')->nullable()->index();  // Shared across tabs (localStorage)
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('guest_sid', 36)->nullable()->index();

            // Device & Context
            $table->string('device_type', 20)->nullable();  // desktop, mobile, tablet, bot
            $table->text('user_agent')->nullable();

            // Timing
            $table->timestamp('started_at');
            $table->timestamp('last_activity_at');
            $table->timestamp('ended_at')->nullable();

            // Frustration Metrics (real-time updated by JS)
            $table->unsignedSmallInteger('rapid_click_count')->default(0);
            $table->unsignedSmallInteger('rage_clicks')->default(0);
            $table->boolean('form_abandonment')->default(false);
            $table->decimal('frustration_score', 3, 2)->default(0.00);  // 0.00 - 1.00
            $table->decimal('scroll_depth', 3, 2)->default(0.00);       // 0.00 - 1.00 (max scroll reached)

            // Session Replay (JSON array of actions, max 50 items)
            $table->json('session_actions')->nullable();

            // Behavior & Intent (AI-generated, filled later)
            $table->string('inferred_intent', 100)->nullable();
            $table->json('behavior_snapshot')->nullable();
            $table->json('last_actions_before_exit')->nullable();

            // Aggregates
            $table->unsignedInteger('total_events')->default(0);
            $table->unsignedInteger('total_pages_viewed')->default(0);

            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_sessions');
    }
};
