<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Stap 1: kolom naar VARCHAR zodat backfill zonder enum-restrictie kan
        DB::statement("ALTER TABLE invitations MODIFY COLUMN role VARCHAR(255) NOT NULL DEFAULT 'editor'");

        // Stap 2: backfill bestaande invitations
        DB::statement("UPDATE invitations SET role = 'owner' WHERE role = 'admin'");
        DB::statement("UPDATE invitations SET role = 'editor' WHERE role NOT IN ('owner', 'editor', 'reviewer', 'viewer')");

        // Stap 3: kolom omzetten naar de nieuwe enum
        DB::statement("ALTER TABLE invitations MODIFY COLUMN role ENUM('owner','editor','reviewer','viewer') NOT NULL DEFAULT 'editor'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE invitations MODIFY COLUMN role VARCHAR(255) NOT NULL DEFAULT 'editor'");

        DB::statement("UPDATE invitations SET role = 'admin' WHERE role = 'owner'");
        DB::statement("UPDATE invitations SET role = 'member' WHERE role IN ('editor', 'reviewer', 'viewer')");
    }
};
