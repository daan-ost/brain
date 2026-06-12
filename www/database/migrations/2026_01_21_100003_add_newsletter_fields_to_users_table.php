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
            if (! Schema::hasColumn('users', 'newsletter_subscribed')) {
                $table->boolean('newsletter_subscribed')->default(true)->after('email_bounced_at');
            }
            if (! Schema::hasColumn('users', 'newsletter_unsubscribe_token')) {
                $table->string('newsletter_unsubscribe_token', 64)->nullable()->unique()->after('newsletter_subscribed');
            }
            if (! Schema::hasColumn('users', 'newsletter_unsubscribed_at')) {
                $table->timestamp('newsletter_unsubscribed_at')->nullable()->after('newsletter_unsubscribe_token');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'newsletter_subscribed')) {
                $table->dropColumn('newsletter_subscribed');
            }
            if (Schema::hasColumn('users', 'newsletter_unsubscribe_token')) {
                $table->dropColumn('newsletter_unsubscribe_token');
            }
            if (Schema::hasColumn('users', 'newsletter_unsubscribed_at')) {
                $table->dropColumn('newsletter_unsubscribed_at');
            }
        });
    }
};
