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
        Schema::create('postmark_layout_templates', function (Blueprint $table) {
            $table->id();
            $table->string('postmark_id')->nullable()->index(); // Postmark layout template ID
            $table->string('name'); // Layout template name
            $table->string('alias')->unique(); // Layout template alias
            $table->longText('html_body'); // HTML layout body (must contain content placeholder)
            $table->boolean('active')->default(true);
            $table->json('postmark_metadata')->nullable(); // Store Postmark response data
            $table->timestamps();

            $table->index('active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('postmark_layout_templates');
    }
};
