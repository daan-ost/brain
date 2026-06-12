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
        // Add price change tracking to licenses table
        Schema::table('licenses', function (Blueprint $table) {
            if (! Schema::hasColumn('licenses', 'upcoming_amount')) {
                $table->decimal('upcoming_amount', 8, 2)->nullable()->after('amount');
            }
            if (! Schema::hasColumn('licenses', 'upcoming_credits')) {
                $table->integer('upcoming_credits')->nullable()->after('credits');
            }
            if (! Schema::hasColumn('licenses', 'price_effective_from')) {
                $table->date('price_effective_from')->nullable()->after('upcoming_credits');
            }
        });

        // Add notification tracking to user_licenses
        Schema::table('user_licenses', function (Blueprint $table) {
            if (! Schema::hasColumn('user_licenses', 'price_change_notified_at')) {
                $table->timestamp('price_change_notified_at')->nullable()->after('last_credit_reset_at');
            }
        });

        // Add notification tracking to organization_licenses
        Schema::table('organization_licenses', function (Blueprint $table) {
            if (! Schema::hasColumn('organization_licenses', 'price_change_notified_at')) {
                $table->timestamp('price_change_notified_at')->nullable()->after('last_credit_reset_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropColumn(['upcoming_amount', 'upcoming_credits', 'price_effective_from']);
        });

        Schema::table('user_licenses', function (Blueprint $table) {
            $table->dropColumn('price_change_notified_at');
        });

        Schema::table('organization_licenses', function (Blueprint $table) {
            $table->dropColumn('price_change_notified_at');
        });
    }
};
