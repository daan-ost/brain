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
        if (Schema::hasTable('inbound_emails')) {
            return;
        }

        Schema::create('inbound_emails', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->string('message_id', 255)->index();
            $table->string('from_email', 255)->index(); // encrypted
            $table->string('from_name', 255)->nullable(); // encrypted
            $table->string('to_email', 255)->index(); // NOT encrypted - admin visible
            $table->string('action_type', 50)->nullable()->index();
            $table->string('subject', 500)->nullable(); // encrypted
            $table->longText('body_text')->nullable(); // encrypted
            $table->longText('body_html')->nullable(); // encrypted
            $table->json('headers')->nullable(); // encrypted
            $table->foreignId('thread_id')->nullable()->constrained('message_threads')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['received', 'processing', 'processed', 'bounced', 'failed'])->default('received')->index();
            $table->text('processing_notes')->nullable(); // admin visible only
            $table->enum('virus_scan_status', ['pending', 'clean', 'infected', 'failed'])->default('pending'); // prepared for ClamAV
            $table->json('virus_scan_details')->nullable(); // prepared for ClamAV
            $table->decimal('spam_score', 3, 2)->nullable();
            $table->unsignedTinyInteger('nested_email_count')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inbound_emails');
    }
};
