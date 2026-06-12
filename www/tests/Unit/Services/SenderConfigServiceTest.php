<?php

use App\Enums\SenderConfigStatus;
use App\Enums\SenderLevel;
use App\Models\Organization;
use App\Models\OrganizationSenderConfig;
use App\Services\SenderConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new SenderConfigService;
});

it('blocks gmail as non-business email', function () {
    expect($this->service->isBusinessEmail('user@gmail.com'))->toBeFalse();
});

it('blocks hotmail as non-business email', function () {
    expect($this->service->isBusinessEmail('user@hotmail.com'))->toBeFalse();
});

it('blocks yahoo as non-business email', function () {
    expect($this->service->isBusinessEmail('user@yahoo.com'))->toBeFalse();
});

it('allows business email', function () {
    expect($this->service->isBusinessEmail('info@mycompany.nl'))->toBeTrue();
});

it('allows custom domain email', function () {
    expect($this->service->isBusinessEmail('contact@acme-corp.com'))->toBeTrue();
});

it('resolves sender with fallback to platform default', function () {
    $org = Organization::factory()->create();

    $result = $this->service->resolveSender($org);

    expect($result['from'])->toBe(config('mail.from.address'));
    expect($result['reply_to'])->toBeNull();
});

it('resolves sender with reply-to config', function () {
    $org = Organization::factory()->create();
    OrganizationSenderConfig::factory()->create([
        'organization_id' => $org->id,
        'sender_level' => SenderLevel::ReplyTo,
        'status' => SenderConfigStatus::Active,
        'reply_to_email' => 'reply@company.com',
        'from_name' => 'Company Name',
    ]);

    $result = $this->service->resolveSender($org->fresh());

    expect($result['from'])->toBe(config('mail.from.address'));
    expect($result['reply_to'])->toBe('reply@company.com');
    expect($result['from_name'])->toBe('Company Name');
});

it('resolves sender with verified signature', function () {
    $org = Organization::factory()->create();
    OrganizationSenderConfig::factory()->senderSignature()->verified()->create([
        'organization_id' => $org->id,
        'from_email' => 'sender@company.com',
        'from_name' => 'Company',
    ]);

    $result = $this->service->resolveSender($org->fresh());

    expect($result['from'])->toBe('sender@company.com');
    expect($result['from_name'])->toBe('Company');
});

it('falls back to default when signature is not verified', function () {
    $org = Organization::factory()->create();
    OrganizationSenderConfig::factory()->senderSignature()->create([
        'organization_id' => $org->id,
        'status' => SenderConfigStatus::PendingVerification,
    ]);

    $result = $this->service->resolveSender($org->fresh());

    expect($result['from'])->toBe(config('mail.from.address'));
});

// --- isBusinessEmail edge cases ---

it('blocks email domains case-insensitively', function () {
    expect($this->service->isBusinessEmail('user@GMAIL.COM'))->toBeFalse();
    expect($this->service->isBusinessEmail('user@Gmail.Com'))->toBeFalse();
});

it('blocks ziggo and kpn domains', function () {
    expect($this->service->isBusinessEmail('user@ziggo.nl'))->toBeFalse();
    expect($this->service->isBusinessEmail('user@kpnmail.nl'))->toBeFalse();
});

it('blocks protonmail domain', function () {
    expect($this->service->isBusinessEmail('user@protonmail.com'))->toBeFalse();
});

// --- resolveSender edge cases ---

it('resolves sender with verified domain auth', function () {
    $org = Organization::factory()->create();
    OrganizationSenderConfig::factory()->domainAuth()->verified()->create([
        'organization_id' => $org->id,
        'from_email' => 'sender@mydomain.com',
        'from_name' => 'My Domain',
        'reply_to_email' => 'reply@mydomain.com',
    ]);

    $result = $this->service->resolveSender($org->fresh());

    expect($result['from'])->toBe('sender@mydomain.com');
    expect($result['from_name'])->toBe('My Domain');
    expect($result['reply_to'])->toBe('reply@mydomain.com');
});

it('resolves sender falls back from_name to platform default when null', function () {
    $org = Organization::factory()->create();
    OrganizationSenderConfig::factory()->create([
        'organization_id' => $org->id,
        'sender_level' => SenderLevel::ReplyTo,
        'status' => SenderConfigStatus::Active,
        'from_name' => null,
        'reply_to_email' => 'reply@company.com',
    ]);

    $result = $this->service->resolveSender($org->fresh());

    expect($result['from_name'])->toBe(config('mail.from.name'));
});

it('resolves sender with failed domain auth falls back to default', function () {
    $org = Organization::factory()->create();
    OrganizationSenderConfig::factory()->domainAuth()->create([
        'organization_id' => $org->id,
        'status' => SenderConfigStatus::Failed,
    ]);

    $result = $this->service->resolveSender($org->fresh());

    expect($result['from'])->toBe(config('mail.from.address'));
});

// --- configureReplyTo ---

it('configures reply-to and creates config', function () {
    $org = Organization::factory()->create();

    $config = $this->service->configureReplyTo($org, 'info@company.com', 'Company Name');

    expect($config)->toBeInstanceOf(OrganizationSenderConfig::class);
    expect($config->sender_level)->toBe(SenderLevel::ReplyTo);
    expect($config->status)->toBe(SenderConfigStatus::Active);
    expect($config->reply_to_email)->toBe('info@company.com');
    expect($config->from_name)->toBe('Company Name');
});

it('configureReplyTo updates existing config', function () {
    $org = Organization::factory()->create();
    OrganizationSenderConfig::factory()->senderSignature()->verified()->create([
        'organization_id' => $org->id,
        'from_email' => 'old@company.com',
    ]);

    $config = $this->service->configureReplyTo($org->fresh(), 'new@company.com', 'New Name');

    expect($config->sender_level)->toBe(SenderLevel::ReplyTo);
    expect($config->reply_to_email)->toBe('new@company.com');
    expect($config->from_email)->toBeNull();
    expect(OrganizationSenderConfig::where('organization_id', $org->id)->count())->toBe(1);
});

// --- removeConfig ---

it('removes config from database', function () {
    $org = Organization::factory()->create();
    $config = OrganizationSenderConfig::factory()->create([
        'organization_id' => $org->id,
    ]);

    $this->service->removeConfig($config);

    expect(OrganizationSenderConfig::where('organization_id', $org->id)->exists())->toBeFalse();
});

// --- checkSignatureStatus without postmark_signature_id ---

it('checkSignatureStatus returns config unchanged without signature id', function () {
    $config = OrganizationSenderConfig::factory()->senderSignature()->create([
        'postmark_signature_id' => null,
        'status' => SenderConfigStatus::PendingVerification,
    ]);

    $result = $this->service->checkSignatureStatus($config);

    expect($result->status)->toBe(SenderConfigStatus::PendingVerification);
});

// --- resendSignatureVerification without signature id ---

it('resendSignatureVerification does nothing without signature id', function () {
    $config = OrganizationSenderConfig::factory()->senderSignature()->create([
        'postmark_signature_id' => null,
    ]);

    // Should not throw
    $this->service->resendSignatureVerification($config);
    expect(true)->toBeTrue();
});

// --- verifyDomainDns without domain id ---

it('verifyDomainDns returns config unchanged without domain id', function () {
    $config = OrganizationSenderConfig::factory()->domainAuth()->create([
        'postmark_domain_id' => null,
        'status' => SenderConfigStatus::PendingVerification,
    ]);

    $result = $this->service->verifyDomainDns($config);

    expect($result->status)->toBe(SenderConfigStatus::PendingVerification);
});
