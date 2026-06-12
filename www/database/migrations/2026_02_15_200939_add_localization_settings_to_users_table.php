<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Add new localization fields
            $table->string('date_format', 20)->nullable()->after('timezone');
            $table->string('time_format', 5)->default('24h')->after('date_format');
            $table->tinyInteger('first_day_of_week')->default(1)->after('decimal_sign');
            $table->boolean('locale_manually_set')->default(false)->after('first_day_of_week');
        });

        // Rename decimal_sign to decimal_separator (SQLite compatible approach)
        if (DB::getDriverName() === 'sqlite') {
            // SQLite doesn't support RENAME COLUMN directly in older versions
            // But Laravel 9+ handles this correctly
            Schema::table('users', function (Blueprint $table) {
                $table->renameColumn('decimal_sign', 'decimal_separator');
            });
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->renameColumn('decimal_sign', 'decimal_separator');
            });
        }

        // Rename email_datetime_format to datetime_format
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('email_datetime_format', 'datetime_format');
        });

        // Remove the change_timezone field (no longer needed - timezone is always customizable)
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('change_timezone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('change_timezone')->default(false)->after('remember_token');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('datetime_format', 'email_datetime_format');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('decimal_separator', 'decimal_sign');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['date_format', 'time_format', 'first_day_of_week', 'locale_manually_set']);
        });
    }
};
