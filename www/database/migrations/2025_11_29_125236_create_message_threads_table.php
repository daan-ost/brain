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
        if (Schema::hasTable('message_threads')) {
            return;
        }

        Schema::create('message_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('category_id')->nullable()->constrained('message_categories')->nullOnDelete();
            $table->string('title')->nullable();
            $table->enum('status', ['open', 'waiting_for_user', 'closed'])->default('open');
            $table->dateTime('last_message_at')->nullable();
            $table->enum('last_message_from', ['user', 'admin', 'llm', 'system'])->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->unsignedInteger('unread_count_user')->default(0);
            $table->unsignedInteger('unread_count_admin')->default(0);
            $table->string('source')->nullable();
            $table->enum('thumb', ['up', 'down'])->nullable();
            $table->json('context_json')->nullable();
            $table->json('settings_json')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('last_message_at');
            $table->index(['user_id', 'status']);
            $table->index('source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_threads');
    }
};
