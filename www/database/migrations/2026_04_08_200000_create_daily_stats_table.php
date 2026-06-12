<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_stats', function (Blueprint $table) {
            $table->date('date')->primary();

            // Revenue (from orders table, paid_at)
            $table->decimal('revenue', 10, 2)->default(0);
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('avg_order_value', 10, 2)->default(0);
            $table->json('revenue_by_license')->nullable(); // slug → amount
            $table->json('orders_by_license')->nullable();  // slug → count

            // Users (from users + analytics_events)
            $table->unsignedInteger('new_users')->default(0);
            $table->unsignedInteger('email_confirmed')->default(0);
            $table->unsignedInteger('logins')->default(0);
            $table->unsignedInteger('active_users')->default(0); // distinct user_id/day

            // Checkout funnel (from analytics_events)
            $table->unsignedInteger('plans_views')->default(0);
            $table->unsignedInteger('checkout_started')->default(0);
            $table->unsignedInteger('checkout_payment_initiated')->default(0);
            $table->unsignedInteger('credits_purchased_events')->default(0);
            $table->unsignedInteger('upgrade_modal_shown')->default(0);

            // Credits (from credit_ledger + organization_credit_ledger)
            $table->unsignedBigInteger('credits_received')->default(0);
            $table->unsignedBigInteger('credits_spent')->default(0);

            // Traffic (from landing_page_view analytics events)
            $table->unsignedInteger('pageviews')->default(0);
            $table->unsignedInteger('pageviews_google')->default(0);
            $table->unsignedInteger('pageviews_direct')->default(0);

            // Metadata
            $table->timestamp('generated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_stats');
    }
};
