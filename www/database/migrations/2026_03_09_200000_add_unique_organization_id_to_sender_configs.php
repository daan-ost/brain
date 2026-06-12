<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_sender_configs', function (Blueprint $table) {
            $table->unique('organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('organization_sender_configs', function (Blueprint $table) {
            $table->dropUnique(['organization_id']);
        });
    }
};
