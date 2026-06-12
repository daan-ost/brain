<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('newsletters', function (Blueprint $table) {
            if (! Schema::hasColumn('newsletters', 'segment_key')) {
                $table->string('segment_key', 64)->nullable()->after('send_limit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('newsletters', function (Blueprint $table) {
            if (Schema::hasColumn('newsletters', 'segment_key')) {
                $table->dropColumn('segment_key');
            }
        });
    }
};
