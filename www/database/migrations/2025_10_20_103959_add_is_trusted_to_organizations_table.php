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
        if (! Schema::hasColumn('organizations', 'is_trusted')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->boolean('is_trusted')
                    ->default(false)
                    ->after('active')
                    ->comment('Trusted organizations have elevated privileges');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('is_trusted');
        });
    }
};
