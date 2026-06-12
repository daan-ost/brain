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
        // Analytics events - optimize date range queries with event filtering
        Schema::table('analytics_events', function (Blueprint $table) {
            $table->index(['event', 'created_at'], 'idx_analytics_events_event_created');
            $table->index(['session_id', 'created_at'], 'idx_analytics_events_session_created');
            $table->index(['user_id', 'created_at'], 'idx_analytics_events_user_created');
        });

        // User licenses - optimize currentLicenses() scope queries
        Schema::table('user_licenses', function (Blueprint $table) {
            $table->index(['user_id', 'is_current', 'status'], 'idx_user_licenses_current');
        });

        // Organization licenses - optimize currentLicenses() scope queries
        Schema::table('organization_licenses', function (Blueprint $table) {
            $table->index(['organization_id', 'is_current', 'status'], 'idx_org_licenses_current');
        });

        // Message threads - optimize user inbox queries
        if (Schema::hasTable('message_threads')) {
            Schema::table('message_threads', function (Blueprint $table) {
                $table->index(['user_id', 'status', 'last_message_at'], 'idx_message_threads_inbox');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('analytics_events', function (Blueprint $table) {
            $table->dropIndex('idx_analytics_events_event_created');
            $table->dropIndex('idx_analytics_events_session_created');
            $table->dropIndex('idx_analytics_events_user_created');
        });

        Schema::table('user_licenses', function (Blueprint $table) {
            $table->dropIndex('idx_user_licenses_current');
        });

        Schema::table('organization_licenses', function (Blueprint $table) {
            $table->dropIndex('idx_org_licenses_current');
        });

        if (Schema::hasTable('message_threads')) {
            Schema::table('message_threads', function (Blueprint $table) {
                $table->dropIndex('idx_message_threads_inbox');
            });
        }
    }
};
