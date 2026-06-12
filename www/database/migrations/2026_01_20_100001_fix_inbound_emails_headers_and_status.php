<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('inbound_emails')) {
            return;
        }

        // Change headers from json to longText to support encryption
        Schema::table('inbound_emails', function (Blueprint $table) {
            $table->longText('headers')->nullable()->change();
        });

        // Add virus_detected to status enum (MySQL only - SQLite stores as string)
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE inbound_emails MODIFY COLUMN status ENUM('received', 'processing', 'processed', 'bounced', 'failed', 'virus_detected') DEFAULT 'received'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('inbound_emails')) {
            return;
        }

        // Remove virus_detected from status enum (data loss warning: virus_detected records will fail)
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE inbound_emails MODIFY COLUMN status ENUM('received', 'processing', 'processed', 'bounced', 'failed') DEFAULT 'received'");
        }

        // Change headers back to json
        Schema::table('inbound_emails', function (Blueprint $table) {
            $table->json('headers')->nullable()->change();
        });
    }
};
