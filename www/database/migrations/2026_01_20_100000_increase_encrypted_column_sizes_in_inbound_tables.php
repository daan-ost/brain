<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Encrypted columns need TEXT type because Laravel's encrypted values
     * are much larger than the original data (base64 + JSON structure).
     */
    public function up(): void
    {
        // Fix inbound_emails encrypted columns
        if (Schema::hasTable('inbound_emails')) {
            Schema::table('inbound_emails', function (Blueprint $table) {
                // Drop index on from_email first (TEXT columns can't be indexed without prefix)
                $indexes = collect(Schema::getIndexes('inbound_emails'))->pluck('name');
                if ($indexes->contains('inbound_emails_from_email_index')) {
                    $table->dropIndex(['from_email']);
                }
            });

            Schema::table('inbound_emails', function (Blueprint $table) {
                // Change encrypted string columns to TEXT to accommodate encrypted values
                $table->text('from_email')->change();
                $table->text('from_name')->nullable()->change();
                $table->text('subject')->nullable()->change();
            });
        }

        // Fix inbound_email_attachments encrypted columns
        if (Schema::hasTable('inbound_email_attachments')) {
            Schema::table('inbound_email_attachments', function (Blueprint $table) {
                // Change encrypted string columns to TEXT
                $table->text('original_filename')->change();
                $table->text('file_path')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('inbound_emails')) {
            Schema::table('inbound_emails', function (Blueprint $table) {
                $table->string('from_email', 255)->change();
                $table->string('from_name', 255)->nullable()->change();
                $table->string('subject', 500)->nullable()->change();
            });

            Schema::table('inbound_emails', function (Blueprint $table) {
                $table->index('from_email');
            });
        }

        if (Schema::hasTable('inbound_email_attachments')) {
            Schema::table('inbound_email_attachments', function (Blueprint $table) {
                $table->string('original_filename', 255)->change();
                $table->string('file_path', 500)->change();
            });
        }
    }
};
