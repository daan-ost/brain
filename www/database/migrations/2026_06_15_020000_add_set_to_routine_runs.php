<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A routine RUN belongs to a named SET (a chain of routines with a shared goal). The first set is
 * "rule-precision" (eliminate existing bad trades from the rules). As more sets are added, each runs
 * its own ordered chain and journals under its set name; the /routines screen groups by set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routine_runs', function (Blueprint $table) {
            $table->string('set_key', 48)->nullable()->after('id');
            $table->string('set_name', 80)->nullable()->after('set_key');
            $table->index('set_key');
        });
    }

    public function down(): void
    {
        Schema::table('routine_runs', function (Blueprint $table) {
            $table->dropIndex(['set_key']);
            $table->dropColumn(['set_key', 'set_name']);
        });
    }
};
