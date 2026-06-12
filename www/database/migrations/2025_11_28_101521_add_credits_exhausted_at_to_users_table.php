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
        if (! Schema::hasColumn('users', 'credits_exhausted_at')) {
            Schema::table('users', function (Blueprint $table) {
                // Track when user's credits were exhausted (for 24-hour free tier delay)
                $table->timestamp('credits_exhausted_at')->nullable()->after('credits_updated_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('credits_exhausted_at');
        });
    }
};
