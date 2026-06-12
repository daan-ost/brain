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
        Schema::create('organization_licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('license_id')->constrained()->onDelete('cascade');
            $table->string('status')->default('active');
            $table->datetime('starts_at')->nullable();
            $table->datetime('ends_at')->nullable();
            $table->string('source')->nullable();
            $table->string('external_ref')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['license_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_licenses');
    }
};
