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
        Schema::table('orders', function (Blueprint $table) {
            // Timestamp when payment was completed
            if (! Schema::hasColumn('orders', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('invoice_date');
            }

            // Payment method used (e.g., 'invoice', 'mollie', 'ideal', etc.)
            if (! Schema::hasColumn('orders', 'payment_method')) {
                $table->string('payment_method', 50)->nullable()->after('paid_at');
            }

            if (! Schema::hasColumn('orders', 'paid_at') && ! Schema::hasIndex('orders', 'orders_paid_at_index')) {
                $table->index('paid_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['paid_at']);
            $table->dropColumn(['paid_at', 'payment_method']);
        });
    }
};
