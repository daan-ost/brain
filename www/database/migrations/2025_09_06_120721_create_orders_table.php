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
        Schema::create('orders', function (Blueprint $table) {
            // Primary key as UUID
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique()->nullable();

            // Payer information (polymorphic)
            $table->enum('payer_type', ['user', 'organization']);
            $table->unsignedBigInteger('payer_id')->nullable();

            // License reference
            $table->unsignedInteger('license_id');

            // Order type
            $table->enum('type', ['onetime', 'subscription']);

            // Financial details
            $table->char('currency', 3);
            $table->decimal('net_amount', 10, 2);
            $table->decimal('tax_amount', 10, 2)->default(0.00);
            $table->decimal('gross_amount', 10, 2);

            // Billing information
            $table->char('country', 2);
            $table->string('vat_id', 32)->nullable();
            $table->json('billing_snapshot')->nullable();

            // Status
            $table->enum('status', ['initiated', 'paid', 'failed', 'canceled', 'invoice_requested'])
                ->default('initiated');

            // Payment provider details
            $table->string('mollie_payment_id', 64)->nullable();
            $table->string('mollie_customer_id', 255)->nullable();
            $table->string('mollie_subscription_id', 64)->nullable();

            // Additional metadata
            $table->json('meta')->nullable();

            // Invoice details
            $table->string('invoice_number', 50)->nullable();
            $table->string('invoice_file_path', 500)->nullable();
            $table->timestamp('invoice_date')->nullable();

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->index('invoice_number', 'idx_invoice_number');
            $table->unique('invoice_number');
            $table->index(['payer_type', 'payer_id']);
            $table->index('status');
            $table->index('license_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
