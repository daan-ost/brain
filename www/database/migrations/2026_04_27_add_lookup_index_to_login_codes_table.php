<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2: composite index on (email, used_at, expires_at) so the verify query
 * — `WHERE email=? AND used_at IS NULL AND expires_at > NOW()` — stays fast
 * even as the login_codes table grows under cleanup pressure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_codes', function (Blueprint $table) {
            $table->index(['email', 'used_at', 'expires_at'], 'login_codes_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('login_codes', function (Blueprint $table) {
            $table->dropIndex('login_codes_lookup_idx');
        });
    }
};
