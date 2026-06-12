<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add 'pending' to the status enum
        // MySQL requires redefining the entire ENUM with all values
        // SQLite doesn't support MODIFY - status is stored as string anyway
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('initiated', 'pending', 'paid', 'failed', 'canceled', 'invoice_requested') DEFAULT 'initiated'");
        }
        // For SQLite, no changes needed - status is already a string column
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'pending' from the status enum
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('initiated', 'paid', 'failed', 'canceled', 'invoice_requested') DEFAULT 'initiated'");
        }
    }
};
