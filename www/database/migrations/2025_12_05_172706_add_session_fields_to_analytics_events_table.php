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
        Schema::table('analytics_events', function (Blueprint $table) {
            $table->uuid('session_id')->nullable()->after('user_id')->index();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('success')->nullable();
            $table->string('error_code', 50)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('analytics_events', function (Blueprint $table) {
            $table->dropColumn(['session_id', 'duration_ms', 'success', 'error_code']);
        });
    }
};
