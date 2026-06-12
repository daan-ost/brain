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
        if (! Schema::hasTable('inbound_emails')) {
            return;
        }

        Schema::table('inbound_emails', function (Blueprint $table) {
            if (! Schema::hasColumn('inbound_emails', 'cleanup_scheduled_at')) {
                $table->timestamp('cleanup_scheduled_at')->nullable()->after('processed_at');
                $table->index('cleanup_scheduled_at');
            }
            if (! Schema::hasColumn('inbound_emails', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('cleanup_scheduled_at');
            }
            if (! Schema::hasColumn('inbound_emails', 'output_file_path')) {
                $table->string('output_file_path')->nullable()->after('completed_at');
            }
            if (! Schema::hasColumn('inbound_emails', 'output_file_count')) {
                $table->unsignedInteger('output_file_count')->nullable()->after('output_file_path');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('inbound_emails')) {
            return;
        }

        Schema::table('inbound_emails', function (Blueprint $table) {
            $table->dropIndex(['cleanup_scheduled_at']);
            $table->dropColumn([
                'cleanup_scheduled_at',
                'completed_at',
                'output_file_path',
                'output_file_count',
            ]);
        });
    }
};
