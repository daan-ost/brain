<?php

use App\Enums\SenderConfigStatus;
use App\Enums\SenderLevel;
use App\Livewire\Organization\SenderEmailSettings;
use App\Models\Organization;
use App\Models\OrganizationSenderConfig;
use App\Models\User;
use Livewire\Livewire;

function createAdminWithOrg(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user, ['role' => \App\Enums\OrganizationRole::Owner->value]);
    session(['current_organization_id' => $org->id]);

    return [$user, $org];
}

it('renders for admin with feature flag enabled', function () {
    config(['features.send_email_functionality' => true]);
    [$user] = createAdminWithOrg();

    Livewire::actingAs($user)
        ->test(SenderEmailSettings::class)
        ->assertOk();
});

it('redirects non-admin users', function () {
    config(['features.send_email_functionality' => true]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user, ['role' => \App\Enums\OrganizationRole::Editor->value]);
    session(['current_organization_id' => $org->id]);

    Livewire::actingAs($user)
        ->test(SenderEmailSettings::class)
        ->assertRedirect(route('profile.organization'));
});

it('returns 404 when feature flag is disabled', function () {
    config(['features.send_email_functionality' => false]);
    [$user] = createAdminWithOrg();

    $this->actingAs($user)
        ->get(route('profile.organization.sender-email'))
        ->assertNotFound();
});

it('saves reply-to configuration', function () {
    config(['features.send_email_functionality' => true]);
    [$user, $org] = createAdminWithOrg();

    Livewire::actingAs($user)
        ->test(SenderEmailSettings::class)
        ->set('selectedLevel', 'reply_to')
        ->set('replyToEmail', 'info@company.com')
        ->set('fromName', 'Company Name')
        ->call('saveReplyTo')
        ->assertHasNoErrors();

    $config = OrganizationSenderConfig::where('organization_id', $org->id)->first();
    expect($config)->not->toBeNull();
    expect($config->sender_level)->toBe(SenderLevel::ReplyTo);
    expect($config->status)->toBe(SenderConfigStatus::Active);
    expect($config->reply_to_email)->toBe('info@company.com');
    expect($config->from_name)->toBe('Company Name');
});

it('rejects blocked domains for sender signature', function () {
    config(['features.send_email_functionality' => true]);
    [$user] = createAdminWithOrg();

    Livewire::actingAs($user)
        ->test(SenderEmailSettings::class)
        ->set('selectedLevel', 'sender_signature')
        ->set('fromEmail', 'user@gmail.com')
        ->set('fromName', 'Gmail User')
        ->call('saveSenderSignature')
        ->assertHasErrors('fromEmail');
});

it('removes sender configuration', function () {
    config(['features.send_email_functionality' => true]);
    [$user, $org] = createAdminWithOrg();

    OrganizationSenderConfig::factory()->create([
        'organization_id' => $org->id,
        'sender_level' => SenderLevel::ReplyTo,
        'status' => SenderConfigStatus::Active,
        'reply_to_email' => 'info@company.com',
    ]);

    Livewire::actingAs($user)
        ->test(SenderEmailSettings::class)
        ->call('remove');

    expect(OrganizationSenderConfig::where('organization_id', $org->id)->exists())->toBeFalse();
});

it('mounts with existing config and populates fields', function () {
    config(['features.send_email_functionality' => true]);
    [$user, $org] = createAdminWithOrg();

    OrganizationSenderConfig::factory()->create([
        'organization_id' => $org->id,
        'sender_level' => SenderLevel::SenderSignature,
        'status' => SenderConfigStatus::PendingVerification,
        'from_email' => 'sender@business.com',
        'from_name' => 'Business Inc',
        'reply_to_email' => 'sender@business.com',
    ]);

    Livewire::actingAs($user)
        ->test(SenderEmailSettings::class)
        ->assertSet('selectedLevel', 'sender_signature')
        ->assertSet('fromEmail', 'sender@business.com')
        ->assertSet('fromName', 'Business Inc');
});

it('validates reply-to requires email and name', function () {
    config(['features.send_email_functionality' => true]);
    [$user] = createAdminWithOrg();

    Livewire::actingAs($user)
        ->test(SenderEmailSettings::class)
        ->set('selectedLevel', 'reply_to')
        ->set('replyToEmail', '')
        ->set('fromName', '')
        ->call('saveReplyTo')
        ->assertHasErrors(['replyToEmail', 'fromName']);
});

it('validates reply-to email must be valid', function () {
    config(['features.send_email_functionality' => true]);
    [$user] = createAdminWithOrg();

    Livewire::actingAs($user)
        ->test(SenderEmailSettings::class)
        ->set('selectedLevel', 'reply_to')
        ->set('replyToEmail', 'not-an-email')
        ->set('fromName', 'Test')
        ->call('saveReplyTo')
        ->assertHasErrors('replyToEmail');
});

it('validates domain auth domain format', function () {
    config(['features.send_email_functionality' => true]);
    [$user] = createAdminWithOrg();

    Livewire::actingAs($user)
        ->test(SenderEmailSettings::class)
        ->set('selectedLevel', 'domain_auth')
        ->set('domain', 'not a domain!')
        ->set('fromEmail', 'user@example.com')
        ->call('saveDomainAuth')
        ->assertHasErrors('domain');
});

it('validates domain auth email must match domain', function () {
    config(['features.send_email_functionality' => true]);
    [$user] = createAdminWithOrg();

    Livewire::actingAs($user)
        ->test(SenderEmailSettings::class)
        ->set('selectedLevel', 'domain_auth')
        ->set('domain', 'company.com')
        ->set('fromEmail', 'user@otherdomain.com')
        ->call('saveDomainAuth')
        ->assertHasErrors('fromEmail');
});

it('checkVerification does nothing without config', function () {
    config(['features.send_email_functionality' => true]);
    [$user] = createAdminWithOrg();

    Livewire::actingAs($user)
        ->test(SenderEmailSettings::class)
        ->call('checkVerification')
        ->assertHasNoErrors();
});

it('resendVerification does nothing for non-signature config', function () {
    config(['features.send_email_functionality' => true]);
    [$user, $org] = createAdminWithOrg();

    OrganizationSenderConfig::factory()->create([
        'organization_id' => $org->id,
        'sender_level' => SenderLevel::ReplyTo,
        'status' => SenderConfigStatus::Active,
    ]);

    Livewire::actingAs($user)
        ->test(SenderEmailSettings::class)
        ->call('resendVerification')
        ->assertHasNoErrors();
});

it('remove does nothing without config', function () {
    config(['features.send_email_functionality' => true]);
    [$user] = createAdminWithOrg();

    Livewire::actingAs($user)
        ->test(SenderEmailSettings::class)
        ->call('remove')
        ->assertHasNoErrors();
});

it('redirects user without any organization', function () {
    config(['features.send_email_functionality' => true]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SenderEmailSettings::class)
        ->assertRedirect(route('profile.organization'));
});

it('rejects hotmail for sender signature', function () {
    config(['features.send_email_functionality' => true]);
    [$user] = createAdminWithOrg();

    Livewire::actingAs($user)
        ->test(SenderEmailSettings::class)
        ->set('selectedLevel', 'sender_signature')
        ->set('fromEmail', 'user@hotmail.com')
        ->set('fromName', 'Test')
        ->call('saveSenderSignature')
        ->assertHasErrors('fromEmail');
});

it('sender signature validates email and name required', function () {
    config(['features.send_email_functionality' => true]);
    [$user] = createAdminWithOrg();

    Livewire::actingAs($user)
        ->test(SenderEmailSettings::class)
        ->set('selectedLevel', 'sender_signature')
        ->set('fromEmail', '')
        ->set('fromName', '')
        ->call('saveSenderSignature')
        ->assertHasErrors(['fromEmail', 'fromName']);
});

it('mounts with domain auth config and populates domain field', function () {
    config(['features.send_email_functionality' => true]);
    [$user, $org] = createAdminWithOrg();

    OrganizationSenderConfig::factory()->domainAuth()->create([
        'organization_id' => $org->id,
        'status' => SenderConfigStatus::PendingVerification,
        'from_email' => 'info@mydomain.com',
        'domain' => 'mydomain.com',
    ]);

    Livewire::actingAs($user)
        ->test(SenderEmailSettings::class)
        ->assertSet('selectedLevel', 'domain_auth')
        ->assertSet('domain', 'mydomain.com')
        ->assertSet('fromEmail', 'info@mydomain.com');
});
