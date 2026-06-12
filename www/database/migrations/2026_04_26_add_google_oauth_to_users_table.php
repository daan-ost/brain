<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // L5: guard each column add — sites may already have `avatar`
            // from earlier migrations or Filament defaults; without a guard
            // the migration aborts mid-way leaving partial state.
            if (! Schema::hasColumn('users', 'google_id')) {
                $table->string('google_id')->nullable()->unique()->after('email');
            }
            if (! Schema::hasColumn('users', 'google_token')) {
                $table->text('google_token')->nullable()->after('google_id');
            }
            if (! Schema::hasColumn('users', 'google_refresh_token')) {
                $table->text('google_refresh_token')->nullable()->after('google_token');
            }
            if (! Schema::hasColumn('users', 'avatar')) {
                $table->string('avatar')->nullable()->after('google_refresh_token');
            }
        });

        // H1 note: google_token / google_refresh_token store ENCRYPTED data
        // (cast in User::casts). Encrypted payloads are larger than the
        // plaintext, so TEXT (not VARCHAR) is required.

        // Make password nullable for Google-only / code-only accounts.
        // SQLite testsuite ondersteunt geen MODIFY COLUMN — skip daar (Laravel
        // test runs gebruiken in-memory schema dat al opnieuw wordt opgebouwd).
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE users MODIFY COLUMN password VARCHAR(255) NULL');
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['google_id', 'google_token', 'google_refresh_token', 'avatar'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        if (DB::getDriverName() === 'mysql') {
            // Backfill empty passwords first to avoid NOT NULL violations
            DB::statement("UPDATE users SET password = '' WHERE password IS NULL");
            DB::statement('ALTER TABLE users MODIFY COLUMN password VARCHAR(255) NOT NULL');
        }
    }
};
