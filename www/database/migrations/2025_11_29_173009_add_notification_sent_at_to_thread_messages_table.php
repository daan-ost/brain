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
        if (! Schema::hasColumn('thread_messages', 'notification_sent_at')) {
            Schema::table('thread_messages', function (Blueprint $table) {
                $table->timestamp('notification_sent_at')->nullable()->after('is_hidden');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('thread_messages', function (Blueprint $table) {
            $table->dropColumn('notification_sent_at');
        });
    }
};
