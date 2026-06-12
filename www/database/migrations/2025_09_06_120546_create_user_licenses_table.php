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
        Schema::create('user_licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('license_id')->constrained()->onDelete('cascade');
            $table->string('status')->default('active'); // active, inactive, canceled, expired, trial
            $table->datetime('starts_at')->nullable();
            $table->datetime('ends_at')->nullable();
            $table->string('source')->nullable(); // mollie, manual, etc.
            $table->string('external_ref')->nullable(); // external payment reference
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['license_id', 'status']);
            $table->index('external_ref');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_licenses');
    }
};
