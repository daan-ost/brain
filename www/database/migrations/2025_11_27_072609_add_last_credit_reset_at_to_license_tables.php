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
        if (! Schema::hasColumn('user_licenses', 'last_credit_reset_at')) {
            Schema::table('user_licenses', function (Blueprint $table) {
                $table->timestamp('last_credit_reset_at')->nullable()->after('ends_at');
            });
        }

        if (! Schema::hasColumn('organization_licenses', 'last_credit_reset_at')) {
            Schema::table('organization_licenses', function (Blueprint $table) {
                $table->timestamp('last_credit_reset_at')->nullable()->after('ends_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_licenses', function (Blueprint $table) {
            $table->dropColumn('last_credit_reset_at');
        });

        Schema::table('organization_licenses', function (Blueprint $table) {
            $table->dropColumn('last_credit_reset_at');
        });
    }
};
