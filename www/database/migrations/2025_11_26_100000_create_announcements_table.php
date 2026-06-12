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
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->json('title_json');
            $table->json('body_json');
            $table->enum('urgency', ['info', 'warning', 'update'])->default('info');
            $table->json('cta_label_json')->nullable();
            $table->string('cta_url', 500)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedInteger('total_views')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['active', 'starts_at', 'ends_at'], 'idx_active_dates');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
