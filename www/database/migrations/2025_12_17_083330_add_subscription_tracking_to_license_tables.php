<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add subscription tracking fields to license tables:
     * - mollie_subscription_id: Link to Mollie subscription for recurring payments
     * - mollie_customer_id: Mollie customer ID for API calls
     * - price_at_purchase: Price paid when license was purchased (for price change tracking)
     * - currency_at_purchase: Currency used at time of purchase
     */
    public function up(): void
    {
        Schema::table('user_licenses', function (Blueprint $table) {
            if (! Schema::hasColumn('user_licenses', 'mollie_subscription_id')) {
                $table->string('mollie_subscription_id')->nullable()->after('external_ref');
                $table->index('mollie_subscription_id');
            }
            if (! Schema::hasColumn('user_licenses', 'mollie_customer_id')) {
                $table->string('mollie_customer_id')->nullable()->after('mollie_subscription_id');
                $table->index('mollie_customer_id');
            }
            if (! Schema::hasColumn('user_licenses', 'price_at_purchase')) {
                $table->decimal('price_at_purchase', 8, 2)->nullable()->after('license_id');
            }
            if (! Schema::hasColumn('user_licenses', 'currency_at_purchase')) {
                $table->string('currency_at_purchase', 3)->nullable()->after('price_at_purchase');
            }
        });

        Schema::table('organization_licenses', function (Blueprint $table) {
            if (! Schema::hasColumn('organization_licenses', 'mollie_subscription_id')) {
                $table->string('mollie_subscription_id')->nullable()->after('external_ref');
                $table->index('mollie_subscription_id');
            }
            if (! Schema::hasColumn('organization_licenses', 'mollie_customer_id')) {
                $table->string('mollie_customer_id')->nullable()->after('mollie_subscription_id');
                $table->index('mollie_customer_id');
            }
            if (! Schema::hasColumn('organization_licenses', 'price_at_purchase')) {
                $table->decimal('price_at_purchase', 8, 2)->nullable()->after('license_id');
            }
            if (! Schema::hasColumn('organization_licenses', 'currency_at_purchase')) {
                $table->string('currency_at_purchase', 3)->nullable()->after('price_at_purchase');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_licenses', function (Blueprint $table) {
            $table->dropIndex(['mollie_subscription_id']);
            $table->dropIndex(['mollie_customer_id']);
            $table->dropColumn([
                'mollie_subscription_id',
                'mollie_customer_id',
                'price_at_purchase',
                'currency_at_purchase',
            ]);
        });

        Schema::table('organization_licenses', function (Blueprint $table) {
            $table->dropIndex(['mollie_subscription_id']);
            $table->dropIndex(['mollie_customer_id']);
            $table->dropColumn([
                'mollie_subscription_id',
                'mollie_customer_id',
                'price_at_purchase',
                'currency_at_purchase',
            ]);
        });
    }
};
