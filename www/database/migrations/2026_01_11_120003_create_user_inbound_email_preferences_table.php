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
        if (Schema::hasTable('user_inbound_email_preferences')) {
            return;
        }

        Schema::create('user_inbound_email_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->onDelete('cascade');
            $table->boolean('inbound_enabled')->default(false);
            $table->boolean('verify_sender')->default(true);
            $table->json('available_actions')->nullable(); // per-website configured actions with unique email addresses
            $table->timestamps();

            $table->index('user_id');
            $table->index('inbound_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_inbound_email_preferences');
    }
};
