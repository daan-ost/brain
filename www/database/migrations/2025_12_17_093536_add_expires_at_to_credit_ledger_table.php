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
        Schema::table('credit_ledger', function (Blueprint $table) {
            if (! Schema::hasColumn('credit_ledger', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('balance_after');
                $table->index('expires_at');
            }
        });

        // Also add to organization_credit_ledger if it exists
        if (Schema::hasTable('organization_credit_ledger')) {
            Schema::table('organization_credit_ledger', function (Blueprint $table) {
                if (! Schema::hasColumn('organization_credit_ledger', 'expires_at')) {
                    $table->timestamp('expires_at')->nullable()->after('balance_after');
                    $table->index('expires_at');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credit_ledger', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn('expires_at');
        });

        if (Schema::hasTable('organization_credit_ledger')) {
            Schema::table('organization_credit_ledger', function (Blueprint $table) {
                if (Schema::hasColumn('organization_credit_ledger', 'expires_at')) {
                    $table->dropIndex(['expires_at']);
                    $table->dropColumn('expires_at');
                }
            });
        }
    }
};
