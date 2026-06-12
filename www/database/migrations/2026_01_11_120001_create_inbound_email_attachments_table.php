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
        if (Schema::hasTable('inbound_email_attachments')) {
            return;
        }

        Schema::create('inbound_email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbound_email_id')->constrained('inbound_emails')->onDelete('cascade');
            $table->string('original_filename', 255); // encrypted
            $table->string('stored_filename', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('file_path', 500); // encrypted file
            $table->string('content_id', 255)->nullable();
            $table->boolean('is_inline')->default(false);
            $table->enum('virus_scan_status', ['pending', 'clean', 'infected', 'failed'])->default('pending'); // prepared for ClamAV
            $table->json('virus_scan_details')->nullable(); // prepared for ClamAV
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index('inbound_email_id');
            $table->index('virus_scan_status');
            $table->index('deleted_at');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inbound_email_attachments');
    }
};
