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
        Schema::table('organization_licenses', function (Blueprint $table) {
            if (! Schema::hasColumn('organization_licenses', 'billing_method')) {
                $table->string('billing_method')->nullable()->after('is_current');
            }
            if (! Schema::hasColumn('organization_licenses', 'payment_status')) {
                $table->string('payment_status')->nullable()->after('billing_method');
            }
            if (! Schema::hasColumn('organization_licenses', 'paid_at')) {
                $table->datetime('paid_at')->nullable()->after('payment_status');
            }
            if (! Schema::hasColumn('organization_licenses', 'invoice_number')) {
                $table->string('invoice_number', 50)->nullable()->after('paid_at');
            }
            if (! Schema::hasColumn('organization_licenses', 'invoice_due_date')) {
                $table->date('invoice_due_date')->nullable()->after('invoice_number');
            }
        });

        // Add index - Schema builder handles duplicates gracefully in Laravel 12
        try {
            Schema::table('organization_licenses', function (Blueprint $table) {
                $table->index('invoice_number');
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Index already exists, ignore
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organization_licenses', function (Blueprint $table) {
            $table->dropIndex(['invoice_number']);
            $table->dropColumn(['billing_method', 'payment_status', 'paid_at', 'invoice_number', 'invoice_due_date']);
        });
    }
};
