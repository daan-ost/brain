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
        Schema::create('organization_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->onDelete('cascade');
            $table->string('domain');
            $table->boolean('is_primary')->default(false);
            $table->boolean('validated')->default(false);
            $table->timestamp('validated_at')->nullable();
            $table->string('validation_token')->nullable();
            $table->boolean('auto_enroll_with_verified_domain')->default(false);
            $table->integer('max_storage_days')->nullable();
            $table->string('support_email')->nullable();
            $table->string('license_type', 50)->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('active')->default(true);

            // Indexes
            $table->unique(['organization_id', 'domain'], 'organization_domains_org_domain_unique');
            $table->index('organization_id', 'organization_domains_organization_id_index');
            $table->index('domain', 'organization_domains_domain_index');
            $table->index('validated', 'organization_domains_validated_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_domains');
    }
};
