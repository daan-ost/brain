<?php

use App\Enums\SenderConfigStatus;
use App\Enums\SenderLevel;
use App\Models\Organization;
use App\Models\OrganizationSenderConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- isUsable() ---

it('reply-to with active status is usable', function () {
    $config = OrganizationSenderConfig::factory()->create([
        'sender_level' => SenderLevel::ReplyTo,
        'status' => SenderConfigStatus::Active,
    ]);
    expect($config->isUsable())->toBeTrue();
});

it('reply-to with verified status is not usable', function () {
    $config = OrganizationSenderConfig::factory()->create([
        'sender_level' => SenderLevel::ReplyTo,
        'status' => SenderConfigStatus::Verified,
    ]);
    expect($config->isUsable())->toBeFalse();
});

it('reply-to with pending status is not usable', function () {
    $config = OrganizationSenderConfig::factory()->create([
        'sender_level' => SenderLevel::ReplyTo,
        'status' => SenderConfigStatus::PendingVerification,
    ]);
    expect($config->isUsable())->toBeFalse();
});

it('reply-to with failed status is not usable', function () {
    $config = OrganizationSenderConfig::factory()->create([
        'sender_level' => SenderLevel::ReplyTo,
        'status' => SenderConfigStatus::Failed,
    ]);
    expect($config->isUsable())->toBeFalse();
});

it('sender signature with verified status is usable', function () {
    $config = OrganizationSenderConfig::factory()->senderSignature()->verified()->create();
    expect($config->isUsable())->toBeTrue();
});

it('sender signature with active status is not usable', function () {
    $config = OrganizationSenderConfig::factory()->senderSignature()->create([
        'status' => SenderConfigStatus::Active,
    ]);
    expect($config->isUsable())->toBeFalse();
});

it('sender signature with pending status is not usable', function () {
    $config = OrganizationSenderConfig::factory()->senderSignature()->create([
        'status' => SenderConfigStatus::PendingVerification,
    ]);
    expect($config->isUsable())->toBeFalse();
});

it('domain auth with verified status is usable', function () {
    $config = OrganizationSenderConfig::factory()->domainAuth()->verified()->create();
    expect($config->isUsable())->toBeTrue();
});

it('domain auth with pending status is not usable', function () {
    $config = OrganizationSenderConfig::factory()->domainAuth()->create([
        'status' => SenderConfigStatus::PendingVerification,
    ]);
    expect($config->isUsable())->toBeFalse();
});

// --- scopeUsable() ---

it('scope usable returns active and verified configs', function () {
    $org = Organization::factory()->create();
    OrganizationSenderConfig::factory()->create([
        'organization_id' => $org->id,
        'status' => SenderConfigStatus::Active,
    ]);
    $org2 = Organization::factory()->create();
    OrganizationSenderConfig::factory()->senderSignature()->verified()->create([
        'organization_id' => $org2->id,
    ]);
    $org3 = Organization::factory()->create();
    OrganizationSenderConfig::factory()->senderSignature()->create([
        'organization_id' => $org3->id,
        'status' => SenderConfigStatus::PendingVerification,
    ]);
    $org4 = Organization::factory()->create();
    OrganizationSenderConfig::factory()->create([
        'organization_id' => $org4->id,
        'status' => SenderConfigStatus::Failed,
    ]);

    $usable = OrganizationSenderConfig::usable()->get();

    expect($usable)->toHaveCount(2);
    expect($usable->pluck('organization_id')->toArray())
        ->toContain($org->id)
        ->toContain($org2->id)
        ->not->toContain($org3->id)
        ->not->toContain($org4->id);
});

// --- Relationship ---

it('belongs to organization', function () {
    $org = Organization::factory()->create();
    $config = OrganizationSenderConfig::factory()->create(['organization_id' => $org->id]);

    expect($config->organization->id)->toBe($org->id);
});

it('organization has sender config', function () {
    $org = Organization::factory()->create();
    OrganizationSenderConfig::factory()->create(['organization_id' => $org->id]);

    expect($org->senderConfig)->not->toBeNull();
    expect($org->senderConfig)->toBeInstanceOf(OrganizationSenderConfig::class);
});

// --- Casts ---

it('casts sender_level to enum', function () {
    $config = OrganizationSenderConfig::factory()->create([
        'sender_level' => SenderLevel::SenderSignature,
    ]);
    $config->refresh();

    expect($config->sender_level)->toBeInstanceOf(SenderLevel::class);
    expect($config->sender_level)->toBe(SenderLevel::SenderSignature);
});

it('casts status to enum', function () {
    $config = OrganizationSenderConfig::factory()->create([
        'status' => SenderConfigStatus::PendingVerification,
    ]);
    $config->refresh();

    expect($config->status)->toBeInstanceOf(SenderConfigStatus::class);
});

it('casts dns_records to array', function () {
    $records = [['type' => 'TXT', 'name' => 'test', 'value' => 'val', 'verified' => false]];
    $config = OrganizationSenderConfig::factory()->create(['dns_records' => $records]);
    $config->refresh();

    expect($config->dns_records)->toBeArray();
    expect($config->dns_records[0]['type'])->toBe('TXT');
});

it('casts verified_at to datetime', function () {
    $config = OrganizationSenderConfig::factory()->verified()->create();
    $config->refresh();

    expect($config->verified_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});
