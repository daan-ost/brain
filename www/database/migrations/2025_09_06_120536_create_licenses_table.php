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
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('tier'); // onetime, business, enterprise, etc.
            $table->decimal('amount', 8, 2)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->string('billing_cycle')->nullable(); // monthly, yearly, one_time
            $table->integer('credits')->default(0);
            $table->integer('period')->nullable()->comment('Validity period in days');
            $table->json('json_restrictions')->nullable();
            $table->integer('ordering')->default(0);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['tier', 'active']);
            $table->index('ordering');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
