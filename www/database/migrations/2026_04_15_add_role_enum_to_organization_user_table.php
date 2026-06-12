<?php

use App\Enums\OrganizationRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill: 'admin' → 'owner', alles anders → 'editor'
        DB::statement("UPDATE organization_user SET role = 'owner' WHERE role = 'admin'");
        DB::statement("UPDATE organization_user SET role = 'editor' WHERE role NOT IN ('owner', 'editor', 'reviewer', 'viewer')");

        // Verander kolom van string naar enum
        DB::statement("ALTER TABLE organization_user MODIFY COLUMN role ENUM('owner','editor','reviewer','viewer') NOT NULL DEFAULT 'editor'");
    }

    public function down(): void
    {
        // Terug naar string kolom
        DB::statement("ALTER TABLE organization_user MODIFY COLUMN role VARCHAR(255) NOT NULL DEFAULT 'editor'");

        // Herstel oude waarden (best-effort)
        DB::statement("UPDATE organization_user SET role = 'admin' WHERE role = 'owner'");
        DB::statement("UPDATE organization_user SET role = 'member' WHERE role IN ('editor', 'reviewer', 'viewer')");
    }
};
