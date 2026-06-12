<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_stats', function (Blueprint $table) {
            // Revenue split by currency
            $table->decimal('revenue_eur', 10, 2)->default(0)->after('avg_order_value');
            $table->decimal('revenue_usd', 10, 2)->default(0)->after('revenue_eur');
            $table->unsignedInteger('orders_eur')->default(0)->after('revenue_usd');
            $table->unsignedInteger('orders_usd')->default(0)->after('orders_eur');

            // Tier breakdown (JSON: {onetime: {count, revenue}, premium: {count, revenue}})
            $table->json('orders_by_tier')->nullable()->after('orders_by_license');

            // License activations and expirations
            $table->unsignedInteger('new_licenses')->default(0)->after('orders_by_tier');
            $table->unsignedInteger('expired_licenses')->default(0)->after('new_licenses');

            // Invoice-requested orders
            $table->unsignedInteger('invoice_requested_count')->default(0)->after('expired_licenses');
        });
    }

    public function down(): void
    {
        Schema::table('daily_stats', function (Blueprint $table) {
            $table->dropColumn([
                'revenue_eur', 'revenue_usd', 'orders_eur', 'orders_usd',
                'orders_by_tier', 'new_licenses', 'expired_licenses',
                'invoice_requested_count',
            ]);
        });
    }
};
