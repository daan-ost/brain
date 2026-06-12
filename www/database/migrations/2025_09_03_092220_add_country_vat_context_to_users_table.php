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
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('billing_country_code', 2)->nullable()->after('email_verified_at');
                $table->string('currency_preference', 3)->nullable()->after('billing_country_code');
                $table->string('vat_number', 32)->nullable()->after('currency_preference');
                $table->timestamp('vat_validated_at')->nullable()->after('vat_number');
                $table->string('ipregistry_country_code', 2)->nullable()->after('vat_validated_at');
                $table->timestamp('ipregistry_checked_at')->nullable()->after('ipregistry_country_code');

                $table->index('billing_country_code');
                $table->index('vat_number');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['billing_country_code']);
                $table->dropIndex(['vat_number']);

                $table->dropColumn([
                    'billing_country_code',
                    'currency_preference',
                    'vat_number',
                    'vat_validated_at',
                    'ipregistry_country_code',
                    'ipregistry_checked_at',
                ]);
            });
        }
    }
};
