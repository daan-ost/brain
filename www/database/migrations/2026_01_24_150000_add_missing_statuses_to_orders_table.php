<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add missing status values to match OrderStatus enum:
     * - expired: Payment expired before completion
     * - refunded: Payment was refunded
     * - charged_back: Payment was charged back
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('initiated', 'pending', 'paid', 'failed', 'canceled', 'expired', 'refunded', 'charged_back', 'invoice_requested') DEFAULT 'initiated'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: This will fail if any rows have the new status values
        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('initiated', 'pending', 'paid', 'failed', 'canceled', 'invoice_requested') DEFAULT 'initiated'");
    }
};
