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
        if (Schema::hasTable('inbound_email_rules')) {
            return;
        }

        Schema::create('inbound_email_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->json('conditions');
            $table->json('actions'); // multilingual descriptions
            $table->integer('priority')->default(100);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('priority');
            $table->index('active');
            $table->index(['active', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inbound_email_rules');
    }
};
