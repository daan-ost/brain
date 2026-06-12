<?php

declare(strict_types=1);

use App\Filament\Resources\OrganizationResource;
use App\Filament\Resources\OrganizationResource\RelationManagers\InvitationsRelationManager;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin, 'admin');
});

describe('InvitationsRelationManager', function () {
    it('is registered on OrganizationResource', function () {
        $relations = OrganizationResource::getRelations();
        $relationClasses = array_map(fn ($r) => is_string($r) ? $r : get_class($r), $relations);

        expect($relationClasses)->toContain(InvitationsRelationManager::class);
    });

    it('renders without errors', function () {
        $organization = Organization::factory()->create();

        Livewire::test(InvitationsRelationManager::class, [
            'ownerRecord' => $organization,
            'pageClass' => \App\Filament\Resources\OrganizationResource\Pages\ViewOrganization::class,
        ])->assertSuccessful();
    });

    it('has correct relationship name', function () {
        expect(InvitationsRelationManager::getRelationshipName())->toBe('invitations');
    });

    it('has correct title', function () {
        $organization = Organization::factory()->create();
        expect(InvitationsRelationManager::getTitle($organization, \App\Filament\Resources\OrganizationResource\Pages\ViewOrganization::class))->toBe('Invitations');
    });

    it('creates invitation correctly', function () {
        $organization = Organization::factory()->create();

        $invitation = Invitation::create([
            'organization_id' => $organization->id,
            'email' => 'test@example.com',
            'role' => \App\Enums\OrganizationRole::Editor->value,
            'status' => 'pending',
            'invited_by' => $this->admin->id,
            'expires_at' => now()->addDays(7),
        ]);

        expect($invitation->organization_id)->toBe($organization->id);
        expect($invitation->email)->toBe('test@example.com');
        expect($invitation->role)->toBe(\App\Enums\OrganizationRole::Editor->value);
        expect($invitation->status)->toBe('pending');
    });

    it('organization has invitations relationship', function () {
        $organization = Organization::factory()->create();

        Invitation::create([
            'organization_id' => $organization->id,
            'email' => 'test1@example.com',
            'role' => \App\Enums\OrganizationRole::Editor->value,
            'status' => 'pending',
            'invited_by' => $this->admin->id,
            'expires_at' => now()->addDays(7),
        ]);

        Invitation::create([
            'organization_id' => $organization->id,
            'email' => 'test2@example.com',
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'status' => 'accepted',
            'invited_by' => $this->admin->id,
            'expires_at' => now()->addDays(7),
            'accepted_at' => now(),
        ]);

        expect($organization->invitations()->count())->toBe(2);
    });

    it('invitation can be pending', function () {
        $organization = Organization::factory()->create();

        $invitation = Invitation::create([
            'organization_id' => $organization->id,
            'email' => 'pending@example.com',
            'role' => \App\Enums\OrganizationRole::Editor->value,
            'status' => 'pending',
            'invited_by' => $this->admin->id,
            'expires_at' => now()->addDays(7),
        ]);

        expect($invitation->isPending())->toBeTrue();
        expect($invitation->isExpired())->toBeFalse();
    });

    it('invitation can be expired', function () {
        $organization = Organization::factory()->create();

        $invitation = Invitation::create([
            'organization_id' => $organization->id,
            'email' => 'expired@example.com',
            'role' => \App\Enums\OrganizationRole::Editor->value,
            'status' => 'pending',
            'invited_by' => $this->admin->id,
            'expires_at' => now()->subDays(1),
        ]);

        expect($invitation->isExpired())->toBeTrue();
        expect($invitation->isPending())->toBeFalse();
    });

    it('invitation can be accepted', function () {
        $organization = Organization::factory()->create();

        $invitation = Invitation::create([
            'organization_id' => $organization->id,
            'email' => 'accept@example.com',
            'role' => \App\Enums\OrganizationRole::Editor->value,
            'status' => 'pending',
            'invited_by' => $this->admin->id,
            'expires_at' => now()->addDays(7),
        ]);

        $invitation->markAsAccepted();
        $invitation->refresh();

        expect($invitation->status)->toBe('accepted');
        expect($invitation->accepted_at)->not->toBeNull();
    });

    it('invitation can be revoked', function () {
        $organization = Organization::factory()->create();

        $invitation = Invitation::create([
            'organization_id' => $organization->id,
            'email' => 'revoke@example.com',
            'role' => \App\Enums\OrganizationRole::Editor->value,
            'status' => 'pending',
            'invited_by' => $this->admin->id,
            'expires_at' => now()->addDays(7),
        ]);

        $invitation->markAsRevoked();
        $invitation->refresh();

        expect($invitation->status)->toBe('revoked');
    });

    it('invitation expiration can be extended', function () {
        $organization = Organization::factory()->create();

        $invitation = Invitation::create([
            'organization_id' => $organization->id,
            'email' => 'extend@example.com',
            'role' => \App\Enums\OrganizationRole::Editor->value,
            'status' => 'pending',
            'invited_by' => $this->admin->id,
            'expires_at' => now()->addDays(1),
        ]);

        $originalExpiry = $invitation->expires_at;
        $newExpiry = $invitation->extendExpiration(7);
        $invitation->refresh();

        expect($invitation->expires_at->gt($originalExpiry))->toBeTrue();
    });

    it('invitation belongs to inviter', function () {
        $organization = Organization::factory()->create();
        $inviter = User::factory()->create(['name' => 'John Inviter']);

        $invitation = Invitation::create([
            'organization_id' => $organization->id,
            'email' => 'test@example.com',
            'role' => \App\Enums\OrganizationRole::Editor->value,
            'status' => 'pending',
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
        ]);

        expect($invitation->invitedBy->name)->toBe('John Inviter');
    });

    it('invitation can have admin role', function () {
        $organization = Organization::factory()->create();

        $invitation = Invitation::create([
            'organization_id' => $organization->id,
            'email' => 'admin@example.com',
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'status' => 'pending',
            'invited_by' => $this->admin->id,
            'expires_at' => now()->addDays(7),
        ]);

        expect($invitation->role)->toBe(\App\Enums\OrganizationRole::Owner->value);
    });

    it('invitation can have member role', function () {
        $organization = Organization::factory()->create();

        $invitation = Invitation::create([
            'organization_id' => $organization->id,
            'email' => 'member@example.com',
            'role' => \App\Enums\OrganizationRole::Editor->value,
            'status' => 'pending',
            'invited_by' => $this->admin->id,
            'expires_at' => now()->addDays(7),
        ]);

        expect($invitation->role)->toBe(\App\Enums\OrganizationRole::Editor->value);
    });
});
