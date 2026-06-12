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
        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->integer('delta'); // positive for credits added, negative for credits spent
            $table->string('reason'); // purchase, spend, adjust, refund
            $table->integer('balance_after');
            $table->json('meta')->nullable(); // additional metadata
            $table->datetime('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['reason', 'user_id']);
            $table->index('balance_after');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_ledger');
    }
};
