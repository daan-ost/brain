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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('change_timezone')->default(false)->after('remember_token');
            $table->string('timezone', 50)->nullable()->after('change_timezone');
            $table->string('email_datetime_format', 50)->nullable()->default('dd.MM.yyyy HH:mm:ss (zzz)')->after('timezone');
            $table->char('decimal_sign', 1)->default('.')->after('email_datetime_format');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['change_timezone', 'timezone', 'email_datetime_format', 'decimal_sign']);
        });
    }
};
