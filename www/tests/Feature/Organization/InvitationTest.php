<?php

use App\Mail\OrganizationInvitation;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->member = User::factory()->create();
    $this->organization = Organization::factory()->create();

    // Attach admin
    $this->organization->users()->attach($this->admin->id, [
        'role' => \App\Enums\OrganizationRole::Owner->value,
        'joined_at' => now(),
    ]);

    // Attach member
    $this->organization->users()->attach($this->member->id, [
        'role' => \App\Enums\OrganizationRole::Editor->value,
        'joined_at' => now(),
    ]);
});

it('admin can send invitation', function () {
    Mail::fake();

    $response = $this->actingAs($this->admin)
        ->post(route('profile.organization.users.invite'), [
            'email' => 'newuser@example.com',
            'role' => \App\Enums\OrganizationRole::Editor->value,
        ]);

    $response->assertRedirect(route('profile.organization.users'));
    $response->assertSessionHas('status', 'invitation-sent');

    expect(Invitation::where('email', 'newuser@example.com')->exists())->toBeTrue();

    Mail::assertQueued(OrganizationInvitation::class, function ($mail) {
        return $mail->hasTo('newuser@example.com');
    });
});

it('member cannot send invitation', function () {
    $response = $this->actingAs($this->member)
        ->post(route('profile.organization.users.invite'), [
            'email' => 'newuser@example.com',
            'role' => \App\Enums\OrganizationRole::Editor->value,
        ]);

    $response->assertRedirect(route('profile.organization.users'));
    $response->assertSessionHas('error');

    expect(Invitation::where('email', 'newuser@example.com')->exists())->toBeFalse();
});

it('guest cannot send invitation', function () {
    $response = $this->post(route('profile.organization.users.invite'), [
        'email' => 'newuser@example.com',
        'role' => \App\Enums\OrganizationRole::Editor->value,
    ]);

    $response->assertRedirect(route('login'));
});

it('prevents duplicate pending invitations', function () {
    // Create existing pending invitation
    Invitation::factory()->create([
        'organization_id' => $this->organization->id,
        'email' => 'newuser@example.com',
        'status' => 'pending',
        'expires_at' => now()->addDays(7),
    ]);

    $response = $this->actingAs($this->admin)
        ->post(route('profile.organization.users.invite'), [
            'email' => 'newuser@example.com',
            'role' => \App\Enums\OrganizationRole::Editor->value,
        ]);

    $response->assertRedirect(route('profile.organization.users'));
    $response->assertSessionHas('error');

    // Only 1 invitation should exist
    expect(Invitation::where('email', 'newuser@example.com')->count())->toBe(1);
});

it('prevents inviting existing organization member', function () {
    $response = $this->actingAs($this->admin)
        ->post(route('profile.organization.users.invite'), [
            'email' => $this->member->email,
            'role' => \App\Enums\OrganizationRole::Editor->value,
        ]);

    $response->assertRedirect(route('profile.organization.users'));
    $response->assertSessionHas('error');

    expect(Invitation::where('email', $this->member->email)->exists())->toBeFalse();
});

it('admin can resend invitation', function () {
    Mail::fake();

    $invitation = Invitation::factory()->create([
        'organization_id' => $this->organization->id,
        'email' => 'newuser@example.com',
        'invited_by' => $this->admin->id,
        'status' => 'pending',
        'expires_at' => now()->addDay(),
    ]);

    $oldExpiry = $invitation->expires_at;

    $response = $this->actingAs($this->admin)
        ->post(route('profile.organization.invitations.resend', $invitation));

    $response->assertRedirect(route('profile.organization.users'));
    $response->assertSessionHas('status', 'invitation-resent');

    $invitation->refresh();
    expect($invitation->expires_at->greaterThan($oldExpiry))->toBeTrue();

    Mail::assertQueued(OrganizationInvitation::class);
});

it('member cannot resend invitation', function () {
    $invitation = Invitation::factory()->create([
        'organization_id' => $this->organization->id,
        'email' => 'newuser@example.com',
        'invited_by' => $this->admin->id,
        'status' => 'pending',
    ]);

    $response = $this->actingAs($this->member)
        ->post(route('profile.organization.invitations.resend', $invitation));

    $response->assertRedirect(route('profile.organization.users'));
    $response->assertSessionHas('error');
});

it('admin can revoke invitation', function () {
    $invitation = Invitation::factory()->create([
        'organization_id' => $this->organization->id,
        'email' => 'newuser@example.com',
        'invited_by' => $this->admin->id,
        'status' => 'pending',
    ]);

    $response = $this->actingAs($this->admin)
        ->delete(route('profile.organization.invitations.revoke', $invitation));

    $response->assertRedirect(route('profile.organization.users'));
    $response->assertSessionHas('status', 'invitation-revoked');

    $invitation->refresh();
    expect($invitation->status)->toBe('revoked');
});

it('member cannot revoke invitation', function () {
    $invitation = Invitation::factory()->create([
        'organization_id' => $this->organization->id,
        'email' => 'newuser@example.com',
        'invited_by' => $this->admin->id,
        'status' => 'pending',
    ]);

    $response = $this->actingAs($this->member)
        ->delete(route('profile.organization.invitations.revoke', $invitation));

    $response->assertRedirect(route('profile.organization.users'));
    $response->assertSessionHas('error');

    $invitation->refresh();
    expect($invitation->status)->toBe('pending');
});

it('shows invitation accept page', function () {
    $invitation = Invitation::factory()->create([
        'organization_id' => $this->organization->id,
        'email' => 'newuser@example.com',
        'status' => 'pending',
        'expires_at' => now()->addDays(7),
    ]);

    $response = $this->get(route('invitations.accept.show', $invitation->token));

    $response->assertOk();
    $response->assertSee($this->organization->name);
    $response->assertSee('invited');
});

it('shows error for expired invitation', function () {
    $invitation = Invitation::factory()->create([
        'organization_id' => $this->organization->id,
        'email' => 'newuser@example.com',
        'status' => 'pending',
        'expires_at' => now()->subDay(),
    ]);

    $response = $this->get(route('invitations.accept.show', $invitation->token));

    $response->assertOk();
    $response->assertSee('expired');
});

it('shows error for revoked invitation', function () {
    $invitation = Invitation::factory()->create([
        'organization_id' => $this->organization->id,
        'email' => 'newuser@example.com',
        'status' => 'revoked',
    ]);

    $response = $this->get(route('invitations.accept.show', $invitation->token));

    $response->assertOk();
    $response->assertSee('revoked');
});

it('existing user can accept invitation', function () {
    $existingUser = User::factory()->create(['email' => 'existing@example.com']);

    $invitation = Invitation::factory()->create([
        'organization_id' => $this->organization->id,
        'email' => 'existing@example.com',
        'invited_by' => $this->admin->id,
        'status' => 'pending',
        'role' => \App\Enums\OrganizationRole::Editor->value,
        'expires_at' => now()->addDays(7),
    ]);

    $response = $this->actingAs($existingUser)
        ->post(route('invitations.accept', $invitation->token));

    $response->assertRedirect(route('profile.organization.users'));
    $response->assertSessionHas('status', 'invitation-accepted');

    expect($this->organization->users()->where('user_id', $existingUser->id)->exists())->toBeTrue();

    $invitation->refresh();
    expect($invitation->status)->toBe('accepted');
    expect($invitation->accepted_at)->not->toBeNull();
});

it('user cannot accept invitation meant for different email', function () {
    $wrongUser = User::factory()->create(['email' => 'wrong@example.com']);

    $invitation = Invitation::factory()->create([
        'organization_id' => $this->organization->id,
        'email' => 'correct@example.com',
        'invited_by' => $this->admin->id,
        'status' => 'pending',
        'expires_at' => now()->addDays(7),
    ]);

    $response = $this->actingAs($wrongUser)
        ->post(route('invitations.accept', $invitation->token));

    $response->assertRedirect();
    $response->assertSessionHas('error');

    expect($this->organization->users()->where('user_id', $wrongUser->id)->exists())->toBeFalse();
});

it('cannot accept expired invitation', function () {
    $user = User::factory()->create(['email' => 'user@example.com']);

    $invitation = Invitation::factory()->create([
        'organization_id' => $this->organization->id,
        'email' => 'user@example.com',
        'status' => 'pending',
        'expires_at' => now()->subDay(),
    ]);

    $response = $this->actingAs($user)
        ->post(route('invitations.accept', $invitation->token));

    $response->assertRedirect();
    $response->assertSessionHas('error');

    expect($this->organization->users()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('cannot accept already accepted invitation', function () {
    $user = User::factory()->create(['email' => 'user@example.com']);

    $invitation = Invitation::factory()->create([
        'organization_id' => $this->organization->id,
        'email' => 'user@example.com',
        'status' => 'accepted',
        'accepted_at' => now()->subDay(),
    ]);

    $response = $this->actingAs($user)
        ->post(route('invitations.accept', $invitation->token));

    $response->assertRedirect(route('profile.organization.users'));
    $response->assertSessionHas('info');
});

it('validates email format when sending invitation', function () {
    $response = $this->actingAs($this->admin)
        ->post(route('profile.organization.users.invite'), [
            'email' => 'invalid-email',
            'role' => \App\Enums\OrganizationRole::Editor->value,
        ]);

    $response->assertSessionHasErrors('email');
});

it('validates role when sending invitation', function () {
    $response = $this->actingAs($this->admin)
        ->post(route('profile.organization.users.invite'), [
            'email' => 'test@example.com',
            'role' => 'invalid-role',
        ]);

    $response->assertSessionHasErrors('role');
});

it('organization users page shows pending invitations for admin', function () {
    Invitation::factory()->count(3)->create([
        'organization_id' => $this->organization->id,
        'invited_by' => $this->admin->id,
        'status' => 'pending',
        'expires_at' => now()->addDays(7),
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('profile.organization.users'));

    $response->assertOk();
    $response->assertSee('Pending Invitations');
    $response->assertSee('3');
});

it('guest must login to view invitation', function () {
    $invitation = Invitation::factory()->create([
        'organization_id' => $this->organization->id,
        'email' => 'newuser@example.com',
        'status' => 'pending',
        'expires_at' => now()->addDays(7),
    ]);

    $response = $this->get(route('invitations.accept.show', $invitation->token));

    $response->assertOk();
    $response->assertSee('Login to Accept');
    $response->assertSee('Create Account');
});

it('404 for non-existent invitation token', function () {
    $response = $this->get(route('invitations.accept.show', 'invalid-token'));

    $response->assertNotFound();
});
