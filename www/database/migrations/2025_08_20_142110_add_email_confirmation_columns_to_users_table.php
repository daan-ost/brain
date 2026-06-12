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
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('last_confirmation_sent_at')->nullable();
                $table->timestamp('email_bounced_at')->nullable();
                $table->string('email_bounce_type', 32)->nullable();
                $table->string('email_bounce_reason', 255)->nullable();
                $table->string('last_postmark_message_id', 64)->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn([
                    'last_confirmation_sent_at',
                    'email_bounced_at',
                    'email_bounce_type',
                    'email_bounce_reason',
                    'last_postmark_message_id',
                ]);
            });
        }
    }
};
