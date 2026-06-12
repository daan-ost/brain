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
        if (Schema::hasTable('license_notifications')) {
            return;
        }

        Schema::create('license_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_license_id')->nullable()->constrained('user_licenses')->onDelete('cascade');
            $table->foreignId('organization_license_id')->nullable()->constrained('organization_licenses')->onDelete('cascade');
            $table->string('notification_type', 50); // expiry_7_days, expiry_1_day, renewal_7_days, low_credits
            $table->timestamp('sent_at');
            $table->timestamps();

            // Indexes for efficient querying (short names to avoid MySQL 64-char limit)
            $table->index(['user_license_id', 'notification_type'], 'ln_user_type_idx');
            $table->index(['organization_license_id', 'notification_type'], 'ln_org_type_idx');

            // Unique constraint to prevent duplicate notifications
            $table->unique(['user_license_id', 'notification_type', 'sent_at'], 'ln_user_unique');
            $table->unique(['organization_license_id', 'notification_type', 'sent_at'], 'ln_org_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('license_notifications');
    }
};
