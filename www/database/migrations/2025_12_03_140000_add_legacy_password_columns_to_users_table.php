<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds columns needed for legacy Joomla password migration:
     * - legacy_password_hash: Backup of original Joomla password hash
     * - needs_password_reset: Flag to force password reset
     * - migrated_at: Timestamp when user was migrated from legacy system
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('legacy_password_hash', 100)->nullable()->after('password')
                ->comment('Backup of original Joomla password hash for rollback and upgrade-on-login');
            $table->boolean('needs_password_reset')->default(false)->after('legacy_password_hash')
                ->comment('Force password reset on next login (corrupt/unknown hash)');
            $table->timestamp('migrated_at')->nullable()->after('needs_password_reset')
                ->comment('Timestamp when user was migrated from legacy system');

            // Index for queries on migrated users
            $table->index('migrated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['migrated_at']);
            $table->dropColumn(['legacy_password_hash', 'needs_password_reset', 'migrated_at']);
        });
    }
};
