<?php

use App\Enums\OrganizationRole;
use App\Mail\OrganizationInvitation;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// store() — uitnodiging versturen
// ---------------------------------------------------------------------------

it('owner kan een uitnodiging versturen met de editor rol (standaard)', function () {
    Mail::fake();

    $owner = User::factory()->create();
    $org   = Organization::factory()->create();
    $org->users()->attach($owner->id, ['role' => OrganizationRole::Owner->value, 'joined_at' => now()]);

    $response = $this->actingAs($owner)->post(route('profile.organization.users.invite'), [
        'email' => 'new@example.com',
    ]);

    $response->assertRedirect(route('profile.organization.users'));
    $response->assertSessionHas('status', 'invitation-sent');

    $invitation = Invitation::where('email', 'new@example.com')->first();
    expect($invitation)->not->toBeNull()
        ->and($invitation->role)->toBe(OrganizationRole::Editor->value);

    Mail::assertQueued(OrganizationInvitation::class, fn ($mail) => $mail->hasTo('new@example.com'));
});

it('owner kan uitnodigen met elke geldige rol', function (OrganizationRole $role) {
    Mail::fake();

    $owner = User::factory()->create();
    $org   = Organization::factory()->create();
    $org->users()->attach($owner->id, ['role' => OrganizationRole::Owner->value, 'joined_at' => now()]);

    $email = "user-{$role->value}@example.com";

    $this->actingAs($owner)->post(route('profile.organization.users.invite'), [
        'email' => $email,
        'role'  => $role->value,
    ])->assertSessionHas('status', 'invitation-sent');

    expect(Invitation::where('email', $email)->value('role'))->toBe($role->value);
})->with([
    OrganizationRole::Owner,
    OrganizationRole::Editor,
    OrganizationRole::Reviewer,
    OrganizationRole::Viewer,
]);

it('editor kan geen uitnodiging versturen', function () {
    Mail::fake();

    $editor = User::factory()->create();
    $org    = Organization::factory()->create();
    $org->users()->attach($editor->id, ['role' => OrganizationRole::Editor->value, 'joined_at' => now()]);

    $this->actingAs($editor)->post(route('profile.organization.users.invite'), [
        'email' => 'other@example.com',
    ])->assertSessionHas('error');

    Mail::assertNothingSent();
});

it('reviewer en viewer kunnen geen uitnodiging versturen', function (OrganizationRole $role) {
    Mail::fake();

    $user = User::factory()->create();
    $org  = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => $role->value, 'joined_at' => now()]);

    $this->actingAs($user)->post(route('profile.organization.users.invite'), [
        'email' => 'other@example.com',
    ])->assertSessionHas('error');

    Mail::assertNothingSent();
})->with([OrganizationRole::Reviewer, OrganizationRole::Viewer]);

it('ongeldige rol wordt geblokkeerd door validatie', function () {
    Mail::fake();

    $owner = User::factory()->create();
    $org   = Organization::factory()->create();
    $org->users()->attach($owner->id, ['role' => OrganizationRole::Owner->value, 'joined_at' => now()]);

    $this->actingAs($owner)->post(route('profile.organization.users.invite'), [
        'email' => 'test@example.com',
        'role'  => 'superadmin',
    ])->assertSessionHasErrors('role');

    Mail::assertNothingSent();
});

it('dubbele uitnodiging wordt geblokkeerd', function () {
    Mail::fake();

    $owner = User::factory()->create();
    $org   = Organization::factory()->create();
    $org->users()->attach($owner->id, ['role' => OrganizationRole::Owner->value, 'joined_at' => now()]);

    Invitation::factory()->create([
        'organization_id' => $org->id,
        'email'           => 'dup@example.com',
        'invited_by'      => $owner->id,
        'role'            => OrganizationRole::Editor->value,
        'status'          => 'pending',
        'expires_at'      => now()->addDays(5),
    ]);

    $this->actingAs($owner)->post(route('profile.organization.users.invite'), [
        'email' => 'dup@example.com',
    ])->assertSessionHas('error');

    expect(Invitation::where('email', 'dup@example.com')->count())->toBe(1);
});

it('al-lid gebruiker kan niet opnieuw worden uitgenodigd', function () {
    Mail::fake();

    $owner  = User::factory()->create();
    $member = User::factory()->create(['email' => 'lid@example.com']);
    $org    = Organization::factory()->create();

    $org->users()->attach($owner->id,  ['role' => OrganizationRole::Owner->value,  'joined_at' => now()]);
    $org->users()->attach($member->id, ['role' => OrganizationRole::Editor->value, 'joined_at' => now()]);

    $this->actingAs($owner)->post(route('profile.organization.users.invite'), [
        'email' => 'lid@example.com',
    ])->assertSessionHas('error');

    Mail::assertNothingSent();
});

// ---------------------------------------------------------------------------
// accept() — uitnodiging accepteren
// ---------------------------------------------------------------------------

it('gebruiker accepteert uitnodiging en krijgt de juiste rol als enum', function (OrganizationRole $role) {
    $invitee = User::factory()->create(['email' => 'accept@example.com']);
    $org     = Organization::factory()->create();

    $invitation = Invitation::factory()->create([
        'organization_id' => $org->id,
        'email'           => 'accept@example.com',
        'role'            => $role->value,
        'status'          => 'pending',
        'expires_at'      => now()->addDays(7),
    ]);

    $this->actingAs($invitee)->post(route('invitations.accept', $invitation->token))
        ->assertRedirect(route('profile.organization.users'))
        ->assertSessionHas('status', 'invitation-accepted');

    $pivot = $org->users()->where('user_id', $invitee->id)->first()->pivot;

    expect($pivot->role)->toBeInstanceOf(OrganizationRole::class)
        ->and($pivot->role)->toBe($role);

    expect($invitation->fresh()->status)->toBe('accepted');
})->with([
    OrganizationRole::Owner,
    OrganizationRole::Editor,
    OrganizationRole::Reviewer,
    OrganizationRole::Viewer,
]);

it('verkeerd e-mailadres bij accepteren geeft een fout', function () {
    $user       = User::factory()->create(['email' => 'wrong@example.com']);
    $org        = Organization::factory()->create();
    $invitation = Invitation::factory()->create([
        'organization_id' => $org->id,
        'email'           => 'correct@example.com',
        'status'          => 'pending',
        'expires_at'      => now()->addDays(7),
    ]);

    $this->actingAs($user)->post(route('invitations.accept', $invitation->token))
        ->assertSessionHas('error');

    expect($invitation->fresh()->status)->toBe('pending');
});

it('verlopen uitnodiging kan niet worden geaccepteerd', function () {
    $invitee    = User::factory()->create(['email' => 'expired@example.com']);
    $org        = Organization::factory()->create();
    $invitation = Invitation::factory()->create([
        'organization_id' => $org->id,
        'email'           => 'expired@example.com',
        'status'          => 'pending',
        'expires_at'      => now()->subDay(),
    ]);

    $this->actingAs($invitee)->post(route('invitations.accept', $invitation->token))
        ->assertSessionHas('error');

    expect($org->users()->where('user_id', $invitee->id)->exists())->toBeFalse();
});

it('al geaccepteerde uitnodiging geeft info redirect', function () {
    $invitee    = User::factory()->create(['email' => 'already@example.com']);
    $org        = Organization::factory()->create();
    $invitation = Invitation::factory()->create([
        'organization_id' => $org->id,
        'email'           => 'already@example.com',
        'status'          => 'accepted',
        'expires_at'      => now()->addDays(7),
    ]);

    $this->actingAs($invitee)->post(route('invitations.accept', $invitation->token))
        ->assertRedirect(route('profile.organization.users'))
        ->assertSessionHas('info');
});

it('ingetrokken uitnodiging kan niet worden geaccepteerd', function () {
    $invitee    = User::factory()->create(['email' => 'revoked@example.com']);
    $org        = Organization::factory()->create();
    $invitation = Invitation::factory()->create([
        'organization_id' => $org->id,
        'email'           => 'revoked@example.com',
        'status'          => 'revoked',
        'expires_at'      => now()->addDays(7),
    ]);

    $this->actingAs($invitee)->post(route('invitations.accept', $invitation->token))
        ->assertSessionHas('error');

    expect($org->users()->where('user_id', $invitee->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// revoke() — uitnodiging intrekken
// ---------------------------------------------------------------------------

it('owner kan een uitnodiging intrekken', function () {
    $owner = User::factory()->create();
    $org   = Organization::factory()->create();
    $org->users()->attach($owner->id, ['role' => OrganizationRole::Owner->value, 'joined_at' => now()]);

    $invitation = Invitation::factory()->create([
        'organization_id' => $org->id,
        'status'          => 'pending',
        'expires_at'      => now()->addDays(7),
    ]);

    $this->actingAs($owner)
        ->delete(route('profile.organization.invitations.revoke', $invitation))
        ->assertRedirect(route('profile.organization.users'))
        ->assertSessionHas('status', 'invitation-revoked');

    expect($invitation->fresh()->status)->toBe('revoked');
});

it('editor kan een uitnodiging niet intrekken', function () {
    $editor = User::factory()->create();
    $org    = Organization::factory()->create();
    $org->users()->attach($editor->id, ['role' => OrganizationRole::Editor->value, 'joined_at' => now()]);

    $invitation = Invitation::factory()->create([
        'organization_id' => $org->id,
        'status'          => 'pending',
        'expires_at'      => now()->addDays(7),
    ]);

    $this->actingAs($editor)
        ->delete(route('profile.organization.invitations.revoke', $invitation))
        ->assertSessionHas('error');

    expect($invitation->fresh()->status)->toBe('pending');
});

// ---------------------------------------------------------------------------
// resend() — uitnodiging opnieuw versturen
// ---------------------------------------------------------------------------

it('owner kan een uitnodiging opnieuw versturen', function () {
    Mail::fake();

    $owner = User::factory()->create();
    $org   = Organization::factory()->create();
    $org->users()->attach($owner->id, ['role' => OrganizationRole::Owner->value, 'joined_at' => now()]);

    $invitation = Invitation::factory()->create([
        'organization_id' => $org->id,
        'email'           => 'resend@example.com',
        'status'          => 'pending',
        'expires_at'      => now()->addDays(1),
    ]);

    $this->actingAs($owner)
        ->post(route('profile.organization.invitations.resend', $invitation))
        ->assertRedirect(route('profile.organization.users'))
        ->assertSessionHas('status', 'invitation-resent');

    Mail::assertQueued(OrganizationInvitation::class, fn ($mail) => $mail->hasTo('resend@example.com'));
    expect($invitation->fresh()->expires_at)->toBeGreaterThan(now()->addDays(6));
});

it('editor kan een uitnodiging niet opnieuw versturen', function () {
    Mail::fake();

    $editor = User::factory()->create();
    $org    = Organization::factory()->create();
    $org->users()->attach($editor->id, ['role' => OrganizationRole::Editor->value, 'joined_at' => now()]);

    $invitation = Invitation::factory()->create([
        'organization_id' => $org->id,
        'status'          => 'pending',
        'expires_at'      => now()->addDays(7),
    ]);

    $this->actingAs($editor)
        ->post(route('profile.organization.invitations.resend', $invitation))
        ->assertSessionHas('error');

    Mail::assertNothingSent();
});

it('niet-lopende uitnodiging kan niet opnieuw worden verstuurd', function () {
    Mail::fake();

    $owner = User::factory()->create();
    $org   = Organization::factory()->create();
    $org->users()->attach($owner->id, ['role' => OrganizationRole::Owner->value, 'joined_at' => now()]);

    $invitation = Invitation::factory()->create([
        'organization_id' => $org->id,
        'status'          => 'accepted',
        'expires_at'      => now()->addDays(7),
    ]);

    $this->actingAs($owner)
        ->post(route('profile.organization.invitations.resend', $invitation))
        ->assertSessionHas('error');

    Mail::assertNothingSent();
});
