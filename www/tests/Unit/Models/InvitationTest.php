<?php

use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Tests\Helpers\assertInvitationIsAccepted;
use function Tests\Helpers\assertInvitationIsExpiredState;
use function Tests\Helpers\assertInvitationIsPending;
use function Tests\Helpers\assertInvitationIsRejected;
use function Tests\Helpers\assertInvitationIsRevoked;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->organization = Organization::factory()->create();
});

it('automatically generates unique token on creation', function () {
    $invitation = Invitation::create([
        'organization_id' => $this->organization->id,
        'email' => 'test@example.com',
        'invited_by' => $this->user->id,
        'role' => 'editor',
        'status' => 'pending',
    ]);

    assertInvitationIsPending($invitation);
    expect(strlen($invitation->token))->toBe(64);
});

it('automatically sets expiry to 7 days from now', function () {
    $before = now()->addDays(7)->subMinute();

    $invitation = Invitation::create([
        'organization_id' => $this->organization->id,
        'email' => 'test@example.com',
        'invited_by' => $this->user->id,
        'role' => 'editor',
        'status' => 'pending',
    ]);

    $after = now()->addDays(7)->addMinute();

    expect($invitation->expires_at)->toBeGreaterThanOrEqual($before);
    expect($invitation->expires_at)->toBeLessThanOrEqual($after);
});

it('belongs to an organization', function () {
    $invitation = Invitation::factory()->create([
        'organization_id' => $this->organization->id,
    ]);

    expect($invitation->organization)->toBeInstanceOf(Organization::class);
    expect($invitation->organization->id)->toBe($this->organization->id);
});

it('belongs to user who invited', function () {
    $invitation = Invitation::factory()->create([
        'invited_by' => $this->user->id,
    ]);

    expect($invitation->invitedBy)->toBeInstanceOf(User::class);
    expect($invitation->invitedBy->id)->toBe($this->user->id);
});

it('scopes pending invitations correctly', function () {
    // Create pending invitation
    Invitation::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => 'pending',
        'expires_at' => now()->addDays(1),
    ]);

    // Create expired pending invitation
    Invitation::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => 'pending',
        'expires_at' => now()->subDay(),
    ]);

    // Create accepted invitation
    Invitation::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => 'accepted',
    ]);

    $pending = Invitation::pending()->get();

    expect($pending)->toHaveCount(1);
    expect($pending->first()->status)->toBe('pending');
});

it('scopes expired invitations correctly', function () {
    // Create pending but not expired
    Invitation::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => 'pending',
        'expires_at' => now()->addDay(),
    ]);

    // Create expired invitation
    Invitation::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => 'pending',
        'expires_at' => now()->subDay(),
    ]);

    $expired = Invitation::expired()->get();

    expect($expired)->toHaveCount(1);
});

it('scopes invitations by email correctly', function () {
    $email = 'test@example.com';

    Invitation::factory()->create(['email' => $email]);
    Invitation::factory()->create(['email' => 'other@example.com']);

    $invitations = Invitation::forEmail($email)->get();

    expect($invitations)->toHaveCount(1);
    expect($invitations->first()->email)->toBe($email);
});

it('checks if invitation is expired', function () {
    $expiredInvitation = Invitation::factory()->create([
        'status' => 'pending',
        'expires_at' => now()->subDay(),
    ]);

    $validInvitation = Invitation::factory()->create([
        'status' => 'pending',
        'expires_at' => now()->addDay(),
    ]);

    expect($expiredInvitation->isExpired())->toBeTrue();
    expect($validInvitation->isExpired())->toBeFalse();
});

it('checks if invitation is pending', function () {
    $pendingInvitation = Invitation::factory()->create([
        'status' => 'pending',
        'expires_at' => now()->addDay(),
    ]);

    $expiredInvitation = Invitation::factory()->create([
        'status' => 'pending',
        'expires_at' => now()->subDay(),
    ]);

    $acceptedInvitation = Invitation::factory()->create([
        'status' => 'accepted',
    ]);

    expect($pendingInvitation->isPending())->toBeTrue();
    expect($expiredInvitation->isPending())->toBeFalse();
    expect($acceptedInvitation->isPending())->toBeFalse();
});

it('marks invitation as accepted', function () {
    $invitation = Invitation::factory()->create([
        'status' => 'pending',
    ]);

    $invitation->markAsAccepted();
    $invitation->refresh();

    assertInvitationIsAccepted($invitation);
});

it('marks invitation as expired', function () {
    $invitation = Invitation::factory()->create([
        'status' => 'pending',
    ]);

    $invitation->markAsExpired();
    $invitation->refresh();

    assertInvitationIsExpiredState($invitation);
});

it('marks invitation as revoked', function () {
    $invitation = Invitation::factory()->create([
        'status' => 'pending',
    ]);

    $invitation->markAsRevoked();
    $invitation->refresh();

    assertInvitationIsRevoked($invitation);
});

it('marks invitation as rejected', function () {
    $invitation = Invitation::factory()->create([
        'status' => 'pending',
    ]);

    $invitation->markAsRejected();
    $invitation->refresh();

    assertInvitationIsRejected($invitation);
});

it('extends expiration date', function () {
    $invitation = Invitation::factory()->create([
        'status' => 'pending',
        'expires_at' => now()->addDay(),
    ]);

    $newExpiry = $invitation->extendExpiration(7);
    $invitation->refresh();

    $expected = now()->addDays(7);

    expect($invitation->expires_at->format('Y-m-d H:i'))->toBe($expected->format('Y-m-d H:i'));
});

it('casts expires_at and accepted_at to datetime', function () {
    $invitation = Invitation::factory()->create();

    expect($invitation->expires_at)->toBeInstanceOf(\Carbon\Carbon::class);
    expect($invitation->created_at)->toBeInstanceOf(\Carbon\Carbon::class);
});
