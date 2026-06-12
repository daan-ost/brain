<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_provider', 20)->nullable()->after('payment_method')->index();
            $table->string('provider_payment_id', 255)->nullable()->after('payment_provider')->index();
            $table->string('provider_customer_id', 255)->nullable()->after('provider_payment_id');
            $table->string('provider_subscription_id', 255)->nullable()->after('provider_customer_id')->index();
            $table->string('provider_invoice_id', 255)->nullable()->after('provider_subscription_id')->index();
        });

        Schema::table('user_licenses', function (Blueprint $table) {
            $table->string('payment_provider', 20)->nullable()->after('source')->index();
            $table->string('provider_subscription_id', 255)->nullable()->after('payment_provider')->index();
            $table->string('provider_customer_id', 255)->nullable()->after('provider_subscription_id');
        });

        Schema::table('organization_licenses', function (Blueprint $table) {
            $table->string('payment_provider', 20)->nullable()->after('source')->index();
            $table->string('provider_subscription_id', 255)->nullable()->after('payment_provider')->index();
            $table->string('provider_customer_id', 255)->nullable()->after('provider_subscription_id');
        });

        Schema::table('licenses', function (Blueprint $table) {
            $table->string('payment_provider', 20)->nullable()->after('billing_cycle');
            $table->string('stripe_product_id', 255)->nullable()->after('payment_provider');
            $table->string('stripe_price_id', 255)->nullable()->after('stripe_product_id');
        });

        // Backfill: bestaande Mollie orders → payment_provider='mollie' + provider_*_id vullen
        DB::statement("
            UPDATE orders
            SET payment_provider = 'mollie',
                provider_payment_id = mollie_payment_id,
                provider_customer_id = mollie_customer_id,
                provider_subscription_id = mollie_subscription_id
            WHERE mollie_payment_id IS NOT NULL
        ");

        DB::statement("
            UPDATE user_licenses
            SET payment_provider = 'mollie',
                provider_subscription_id = mollie_subscription_id,
                provider_customer_id = mollie_customer_id
            WHERE mollie_subscription_id IS NOT NULL
        ");

        DB::statement("
            UPDATE organization_licenses
            SET payment_provider = 'mollie',
                provider_subscription_id = mollie_subscription_id,
                provider_customer_id = mollie_customer_id
            WHERE mollie_subscription_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['payment_provider']);
            $table->dropIndex(['provider_payment_id']);
            $table->dropIndex(['provider_subscription_id']);
            $table->dropIndex(['provider_invoice_id']);
            $table->dropColumn([
                'payment_provider',
                'provider_payment_id',
                'provider_customer_id',
                'provider_subscription_id',
                'provider_invoice_id',
            ]);
        });

        Schema::table('user_licenses', function (Blueprint $table) {
            $table->dropIndex(['payment_provider']);
            $table->dropIndex(['provider_subscription_id']);
            $table->dropColumn(['payment_provider', 'provider_subscription_id', 'provider_customer_id']);
        });

        Schema::table('organization_licenses', function (Blueprint $table) {
            $table->dropIndex(['payment_provider']);
            $table->dropIndex(['provider_subscription_id']);
            $table->dropColumn(['payment_provider', 'provider_subscription_id', 'provider_customer_id']);
        });

        Schema::table('licenses', function (Blueprint $table) {
            $table->dropColumn(['payment_provider', 'stripe_product_id', 'stripe_price_id']);
        });
    }
};
