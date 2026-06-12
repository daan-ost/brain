<?php

use App\Enums\SenderConfigStatus;
use App\Enums\SenderLevel;
use App\Models\Organization;
use App\Models\OrganizationSenderConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports no pending verifications', function () {
    $this->artisan('sender:check-verifications')
        ->expectsOutput('No pending verifications to check.')
        ->assertExitCode(0);
});

it('skips active reply-to configs', function () {
    $org = Organization::factory()->create();
    OrganizationSenderConfig::factory()->create([
        'organization_id' => $org->id,
        'sender_level' => SenderLevel::ReplyTo,
        'status' => SenderConfigStatus::Active,
    ]);

    $this->artisan('sender:check-verifications')
        ->expectsOutput('No pending verifications to check.')
        ->assertExitCode(0);
});

it('finds pending signature configs', function () {
    $org = Organization::factory()->create();
    OrganizationSenderConfig::factory()->senderSignature()->create([
        'organization_id' => $org->id,
        'status' => SenderConfigStatus::PendingVerification,
        'postmark_signature_id' => null, // no ID, so checkSignatureStatus returns unchanged
    ]);

    $this->artisan('sender:check-verifications')
        ->expectsOutputToContain('Checking 1 pending verifications')
        ->assertExitCode(0);
});

it('finds pending domain configs', function () {
    $org = Organization::factory()->create();
    OrganizationSenderConfig::factory()->domainAuth()->create([
        'organization_id' => $org->id,
        'status' => SenderConfigStatus::PendingVerification,
        'postmark_domain_id' => null, // no ID, so verifyDomainDns returns unchanged
    ]);

    $this->artisan('sender:check-verifications')
        ->expectsOutputToContain('Checking 1 pending verifications')
        ->assertExitCode(0);
});
