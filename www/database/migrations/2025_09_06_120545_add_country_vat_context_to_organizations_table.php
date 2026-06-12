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
        if (Schema::hasTable('organizations')) {
            Schema::table('organizations', function (Blueprint $table) {
                // Only add the new columns that don't exist in create_organizations_table
                if (! Schema::hasColumn('organizations', 'vat_validated_at')) {
                    $table->timestamp('vat_validated_at')->nullable()->after('vat_number');
                }
                if (! Schema::hasColumn('organizations', 'ipregistry_country_code')) {
                    $table->string('ipregistry_country_code', 2)->nullable()->after('vat_validated_at');
                }
                if (! Schema::hasColumn('organizations', 'ipregistry_checked_at')) {
                    $table->timestamp('ipregistry_checked_at')->nullable()->after('ipregistry_country_code');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('organizations')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->dropColumn([
                    'vat_validated_at',
                    'ipregistry_country_code',
                    'ipregistry_checked_at',
                ]);
            });
        }
    }
};
