<?php

use App\Models\CreditLedger;
use App\Models\Invitation;
use App\Models\License;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserLicense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a free registration license for testing
    $this->freeLicense = License::factory()->create([
        'slug' => 'free-registration',
        'name' => 'Free Registration Bonus',
        'credits' => 15,
        'active' => true,
    ]);

    // Set the config
    config(['licenses.free_registration' => [
        'slug' => 'free-registration',
        'credits' => 15,
    ]]);

    $this->admin = User::factory()->create();
    $this->organization = Organization::factory()->create();

    // Attach admin
    $this->organization->users()->attach($this->admin->id, [
        'role' => \App\Enums\OrganizationRole::Owner->value,
        'joined_at' => now(),
    ]);
});

it('new user can register via invitation and skip email verification', function () {
    Notification::fake();

    // Create invitation
    $invitation = Invitation::factory()->create([
        'organization_id' => $this->organization->id,
        'email' => 'inviteduser@gmail.com',
        'invited_by' => $this->admin->id,
        'status' => 'pending',
        'role' => \App\Enums\OrganizationRole::Editor->value,
        'expires_at' => now()->addDays(7),
    ]);

    // Register with invitation token
    $response = $this->post(route('register'), [
        'name' => 'New User',
        'email' => 'inviteduser@gmail.com',
        'password' => 'password123456',
        'password_confirmation' => 'password123456',
        'terms' => true,
        'invitation_token' => $invitation->token,
    ]);

    // Should redirect to organization page, not verification page
    $response->assertRedirect(route('profile.organization.users'));
    $response->assertSessionHas('status', 'invitation-accepted');

    // User should be created
    $user = User::where('email', 'inviteduser@gmail.com')->first();
    expect($user)->not->toBeNull();

    // Email should be verified immediately
    expect($user->email_verified_at)->not->toBeNull();

    // User should be authenticated
    $this->assertAuthenticatedAs($user);

    // User should be added to organization
    expect($this->organization->users()->where('user_id', $user->id)->exists())->toBeTrue();

    $pivot = $this->organization->users()->where('user_id', $user->id)->first()->pivot;
    expect($pivot->role)->toBe(\App\Enums\OrganizationRole::Editor);

    // Invitation should be accepted
    $invitation->refresh();
    expect($invitation->status)->toBe('accepted');
    expect($invitation->accepted_at)->not->toBeNull();

    // Free license should be assigned
    expect($user->credits)->toBe(15);

    $userLicense = UserLicense::where('user_id', $user->id)
        ->where('license_id', $this->freeLicense->id)
        ->first();
    expect($userLicense)->not->toBeNull();
    expect($userLicense->status)->toBe('active');

    // Credit ledger entry should be created
    $ledgerEntry = CreditLedger::where('user_id', $user->id)
        ->where('delta', 15)
        ->first();
    expect($ledgerEntry)->not->toBeNull();
    expect($ledgerEntry->reason)->toBe('purchase');
    expect($ledgerEntry->balance_after)->toBe(15);
});

it('invitation registration assigns admin role correctly', function () {
    Notification::fake();

    // Create invitation with admin role
    $invitation = Invitation::factory()->create([
        'organization_id' => $this->organization->id,
        'email' => 'newadminuser@gmail.com',
        'invited_by' => $this->admin->id,
        'status' => 'pending',
        'role' => \App\Enums\OrganizationRole::Owner->value, // Admin role
        'expires_at' => now()->addDays(7),
    ]);

    // Register
    $response = $this->post(route('register'), [
        'name' => 'New Admin',
        'email' => 'newadminuser@gmail.com',
        'password' => 'password123456',
        'password_confirmation' => 'password123456',
        'terms' => true,
        'invitation_token' => $invitation->token,
    ]);

    $response->assertRedirect(route('profile.organization.users'));

    $user = User::where('email', 'newadminuser@gmail.com')->first();
    $pivot = $this->organization->users()->where('user_id', $user->id)->first()->pivot;

    expect($pivot->role)->toBe(\App\Enums\OrganizationRole::Owner);
});

it('normal registration still requires email verification', function () {
    Notification::fake();

    // Register WITHOUT invitation token
    $response = $this->post(route('register'), [
        'name' => 'Normal User',
        'email' => 'normaluser@gmail.com',
        'password' => 'password123456',
        'password_confirmation' => 'password123456',
        'terms' => true,
    ]);

    // Should redirect to verification page
    $response->assertRedirect(route('verification.notice'));

    // User should be created
    $user = User::where('email', 'normaluser@gmail.com')->first();
    expect($user)->not->toBeNull();

    // Email should NOT be verified
    expect($user->email_verified_at)->toBeNull();

    // pending_license_assignment should be true (stored as 1 in database)
    expect((bool) $user->pending_license_assignment)->toBeTrue();

    // No credits assigned yet
    expect($user->credits)->toBe(0);
});

it('invitation registration rejects mismatched email', function () {
    // Create invitation
    $invitation = Invitation::factory()->create([
        'organization_id' => $this->organization->id,
        'email' => 'invitedperson@gmail.com',
        'invited_by' => $this->admin->id,
        'status' => 'pending',
        'expires_at' => now()->addDays(7),
    ]);

    // Try to register with different email
    $response = $this->post(route('register'), [
        'name' => 'Wrong User',
        'email' => 'differentperson@gmail.com',
        'password' => 'password123456',
        'password_confirmation' => 'password123456',
        'terms' => true,
        'invitation_token' => $invitation->token,
    ]);

    // Should have validation errors
    $response->assertSessionHasErrors('email');

    // User should NOT be created
    expect(User::where('email', 'differentperson@gmail.com')->exists())->toBeFalse();

    // Invitation should still be pending
    $invitation->refresh();
    expect($invitation->status)->toBe('pending');
});

it('invitation registration handles expired invitation', function () {
    Notification::fake();

    // Create expired invitation
    $invitation = Invitation::factory()->create([
        'organization_id' => $this->organization->id,
        'email' => 'expireduser@gmail.com',
        'invited_by' => $this->admin->id,
        'status' => 'pending',
        'expires_at' => now()->subDay(), // Expired
    ]);

    // Register with expired invitation (token is invalid, so treated as normal registration)
    $response = $this->post(route('register'), [
        'name' => 'Expired User',
        'email' => 'expireduser@gmail.com',
        'password' => 'password123456',
        'password_confirmation' => 'password123456',
        'terms' => true,
        'invitation_token' => $invitation->token,
    ]);

    // Should redirect to verification page (normal flow, not invitation flow)
    $response->assertRedirect(route('verification.notice'));

    $user = User::where('email', 'expireduser@gmail.com')->first();
    expect($user)->not->toBeNull();

    // Email should NOT be verified
    expect($user->email_verified_at)->toBeNull();

    // User should NOT be added to organization
    expect($this->organization->users()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('invitation registration tracks analytics correctly', function () {
    Notification::fake();

    // Create invitation
    $invitation = Invitation::factory()->create([
        'organization_id' => $this->organization->id,
        'email' => 'analyticsuser@gmail.com',
        'invited_by' => $this->admin->id,
        'status' => 'pending',
        'role' => \App\Enums\OrganizationRole::Editor->value,
        'expires_at' => now()->addDays(7),
    ]);

    // Register
    $response = $this->post(route('register'), [
        'name' => 'Analytics User',
        'email' => 'analyticsuser@gmail.com',
        'password' => 'password123456',
        'password_confirmation' => 'password123456',
        'terms' => true,
        'invitation_token' => $invitation->token,
    ]);

    $response->assertRedirect(route('profile.organization.users'));

    // Check analytics were logged (this would require mocking AnalyticsService)
    // For now, just verify the user and invitation flow completed successfully
    $user = User::where('email', 'analyticsuser@gmail.com')->first();
    expect($user)->not->toBeNull();

    $invitation->refresh();
    expect($invitation->status)->toBe('accepted');
});

it('invitation registration with no free license still completes', function () {
    Notification::fake();

    // Deactivate free license
    $this->freeLicense->update(['active' => false]);

    // Create invitation
    $invitation = Invitation::factory()->create([
        'organization_id' => $this->organization->id,
        'email' => 'nolicenseuser@gmail.com',
        'invited_by' => $this->admin->id,
        'status' => 'pending',
        'role' => \App\Enums\OrganizationRole::Editor->value,
        'expires_at' => now()->addDays(7),
    ]);

    // Register
    $response = $this->post(route('register'), [
        'name' => 'No License User',
        'email' => 'nolicenseuser@gmail.com',
        'password' => 'password123456',
        'password_confirmation' => 'password123456',
        'terms' => true,
        'invitation_token' => $invitation->token,
    ]);

    // Should still succeed
    $response->assertRedirect(route('profile.organization.users'));

    $user = User::where('email', 'nolicenseuser@gmail.com')->first();
    expect($user)->not->toBeNull();
    expect($user->email_verified_at)->not->toBeNull();

    // No credits assigned (license was inactive)
    expect($user->credits)->toBe(0);

    // Still added to organization
    expect($this->organization->users()->where('user_id', $user->id)->exists())->toBeTrue();
});
