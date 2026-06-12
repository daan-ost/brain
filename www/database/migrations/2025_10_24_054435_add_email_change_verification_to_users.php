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
        Schema::table('users', function (Blueprint $table) {
            // Pending email change fields
            $table->string('pending_email')->nullable()->after('email');
            $table->string('email_change_token')->nullable()->after('pending_email');
            $table->timestamp('email_change_requested_at')->nullable()->after('email_change_token');
            $table->timestamp('email_change_token_expires_at')->nullable()->after('email_change_requested_at');

            // Rate limiting
            $table->timestamp('last_email_change_request_at')->nullable()->after('email_change_token_expires_at');

            // Indexes for fast lookups
            $table->index('pending_email');
            $table->index('email_change_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop indexes first
            $table->dropIndex(['pending_email']);
            $table->dropIndex(['email_change_token']);

            // Drop columns
            $table->dropColumn([
                'pending_email',
                'email_change_token',
                'email_change_requested_at',
                'email_change_token_expires_at',
                'last_email_change_request_at',
            ]);
        });
    }
};
