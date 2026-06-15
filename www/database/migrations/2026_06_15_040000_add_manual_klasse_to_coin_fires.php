<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coin_fires', function (Blueprint $table) {
            $table->string('manual_klasse', 10)->nullable()->after('best_upside');
        });
    }

    public function down(): void
    {
        Schema::table('coin_fires', function (Blueprint $table) {
            $table->dropColumn('manual_klasse');
        });
    }
};
