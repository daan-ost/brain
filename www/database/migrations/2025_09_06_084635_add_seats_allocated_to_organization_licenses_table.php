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
        if (Schema::hasTable('organization_licenses')) {
            Schema::table('organization_licenses', function (Blueprint $table) {
                $table->integer('seats_allocated')->nullable()->after('status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('organization_licenses')) {
            Schema::table('organization_licenses', function (Blueprint $table) {
                $table->dropColumn('seats_allocated');
            });
        }
    }
};
