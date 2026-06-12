<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('organization_sender_logs')) {
            return;
        }

        Schema::create('organization_sender_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('recipient_email', 255);
            $table->string('template_alias', 100)->nullable();
            $table->string('tag', 100)->nullable();
            $table->enum('status', ['sent', 'failed', 'rate_limited', 'bounced'])->default('sent');
            $table->string('postmark_message_id', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_sender_logs');
    }
};
