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
        if (Schema::hasTable('message_categories')) {
            return;
        }

        Schema::create('message_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name_en');
            $table->string('name_nl');
            $table->string('slug')->unique();
            $table->json('settings_json')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->index('is_visible');
            $table->index('order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_categories');
    }
};
