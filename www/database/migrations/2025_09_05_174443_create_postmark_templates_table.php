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
        Schema::create('postmark_templates', function (Blueprint $table) {
            $table->id();
            $table->string('postmark_id')->nullable()->index(); // Postmark template ID
            $table->string('name'); // Template name
            $table->string('alias')->unique(); // Template alias
            $table->string('subject')->nullable(); // Email subject
            $table->longText('html_body')->nullable(); // HTML template body
            $table->longText('text_body')->nullable(); // Text template body
            $table->enum('template_type', ['Standard', 'Layout'])->default('Standard');
            $table->string('layout_template_alias')->nullable(); // Associated layout template
            $table->boolean('active')->default(true);
            $table->json('postmark_metadata')->nullable(); // Store Postmark response data
            $table->timestamps();

            $table->index(['template_type', 'active']);
            $table->index('layout_template_alias');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('postmark_templates');
    }
};
