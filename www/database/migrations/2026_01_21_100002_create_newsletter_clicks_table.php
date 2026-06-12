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
        if (Schema::hasTable('newsletter_clicks')) {
            return;
        }

        Schema::create('newsletter_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_id')->constrained('newsletter_recipients')->cascadeOnDelete();
            $table->string('url', 2000);
            $table->timestamp('clicked_at');

            $table->index('recipient_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('newsletter_clicks');
    }
};
