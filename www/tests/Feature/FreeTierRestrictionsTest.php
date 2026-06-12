<?php

use App\Livewire\Profile\ApiTokenManager;
use App\Models\License;
use App\Models\Organization;
use App\Models\OrganizationLicense;
use App\Models\User;
use App\Models\UserLicense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

describe('User::isFreeTier()', function () {
    it('returns true when user has free tier license', function () {
        $license = License::factory()->create(['tier' => 'free']);
        $user = User::factory()->create();

        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'is_current' => true,
        ]);

        expect($user->isFreeTier())->toBeTrue();
    });

    it('returns false when user has premium tier license', function () {
        $license = License::factory()->create(['tier' => 'premium']);
        $user = User::factory()->create();

        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'is_current' => true,
        ]);

        expect($user->isFreeTier())->toBeFalse();
    });

    it('returns false when user has onetime tier license', function () {
        $license = License::factory()->create(['tier' => 'onetime']);
        $user = User::factory()->create();

        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'is_current' => true,
        ]);

        expect($user->isFreeTier())->toBeFalse();
    });

    it('returns true when user has no license (treated as free tier)', function () {
        $user = User::factory()->create();

        // Users without any license are treated as free tier
        expect($user->isFreeTier())->toBeTrue();
    });

    it('returns true when user license is not active (treated as no license)', function () {
        $license = License::factory()->create(['tier' => 'free']);
        $user = User::factory()->create();

        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'inactive',
            'is_current' => true,
        ]);

        // Inactive license is treated as no license → free tier
        expect($user->isFreeTier())->toBeTrue();
    });

    it('returns true when user license is not current (treated as no license)', function () {
        $license = License::factory()->create(['tier' => 'free']);
        $user = User::factory()->create();

        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'is_current' => false,
        ]);

        // Non-current license is treated as no license → free tier
        expect($user->isFreeTier())->toBeTrue();
    });

    it('returns false when user has free personal license but organization has paid license', function () {
        $freeLicense = License::factory()->create(['tier' => 'free']);
        $premiumLicense = License::factory()->create(['tier' => 'premium']);
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        // User has free personal license
        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $freeLicense->id,
            'status' => 'active',
            'is_current' => true,
        ]);

        // User belongs to organization
        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Editor->value,
            'joined_at' => now(),
        ]);

        // Organization has premium license
        OrganizationLicense::factory()->create([
            'organization_id' => $organization->id,
            'license_id' => $premiumLicense->id,
            'status' => 'active',
            'is_current' => true,
        ]);

        expect($user->fresh()->isFreeTier())->toBeFalse();
    });

    it('returns false when user has no personal license but organization has paid license', function () {
        $premiumLicense = License::factory()->create(['tier' => 'premium']);
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        // User belongs to organization
        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Editor->value,
            'joined_at' => now(),
        ]);

        // Organization has premium license
        OrganizationLicense::factory()->create([
            'organization_id' => $organization->id,
            'license_id' => $premiumLicense->id,
            'status' => 'active',
            'is_current' => true,
        ]);

        expect($user->fresh()->isFreeTier())->toBeFalse();
    });

    it('returns true when user has free personal license and organization has free license', function () {
        $freeLicense = License::factory()->create(['tier' => 'free']);
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        // User has free personal license
        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $freeLicense->id,
            'status' => 'active',
            'is_current' => true,
        ]);

        // User belongs to organization
        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Editor->value,
            'joined_at' => now(),
        ]);

        // Organization has free license
        OrganizationLicense::factory()->create([
            'organization_id' => $organization->id,
            'license_id' => $freeLicense->id,
            'status' => 'active',
            'is_current' => true,
        ]);

        expect($user->fresh()->isFreeTier())->toBeTrue();
    });

    it('returns true when user has no licenses and belongs to no organizations', function () {
        $user = User::factory()->create();

        expect($user->isFreeTier())->toBeTrue();
    });
});

describe('API Token Manager - Free Tier Restrictions', function () {
    it('shows disabled form for free tier users', function () {
        $license = License::factory()->create(['tier' => 'free']);
        $user = User::factory()->create();

        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'is_current' => true,
        ]);

        $this->actingAs($user);

        $this->get('/profile/api-tokens')
            ->assertStatus(200)
            ->assertSee(__('common.upgrade_required'))
            ->assertSee('/pricing');
    });

    it('shows enabled form for paid tier users', function () {
        $license = License::factory()->create(['tier' => 'premium']);
        $user = User::factory()->create();

        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'is_current' => true,
        ]);

        $this->actingAs($user);

        $this->get('/profile/api-tokens')
            ->assertStatus(200)
            ->assertDontSee(__('common.upgrade_required'));
    });

    it('allows paid users to create tokens', function () {
        $license = License::factory()->create(['tier' => 'premium']);
        $user = User::factory()->create();

        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'is_current' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', 'Test Token')
            ->call('createToken')
            ->assertHasNoErrors();

        expect($user->tokens()->count())->toBe(1);
    });

    it('shows disabled form for users without any license', function () {
        $user = User::factory()->create();

        $this->actingAs($user);

        // Users without any license are treated as free tier (restricted)
        $this->get('/profile/api-tokens')
            ->assertStatus(200)
            ->assertSee(__('common.upgrade_required'));
    });

    it('shows enabled form for users with organization paid license but no personal license', function () {
        $premiumLicense = License::factory()->create(['tier' => 'premium']);
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        // User belongs to organization
        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Editor->value,
            'joined_at' => now(),
        ]);

        // Organization has premium license
        OrganizationLicense::factory()->create([
            'organization_id' => $organization->id,
            'license_id' => $premiumLicense->id,
            'status' => 'active',
            'is_current' => true,
        ]);

        $this->actingAs($user);

        // User should have access because organization has paid license
        $this->get('/profile/api-tokens')
            ->assertStatus(200)
            ->assertDontSee(__('common.upgrade_required'));
    });
});

describe('Organization Users - Free Tier Restrictions', function () {
    beforeEach(function () {
        $this->organization = \App\Models\Organization::factory()->create();
    });

    it('shows disabled invite button for free tier admin users', function () {
        $license = License::factory()->create(['tier' => 'free']);
        $user = User::factory()->create();

        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'is_current' => true,
        ]);

        // Make user an admin of the organization
        $this->organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'joined_at' => now(),
        ]);

        $this->actingAs($user);

        $this->get('/profile/organization/users')
            ->assertStatus(200)
            ->assertSee(__('common.upgrade_required'))
            ->assertSee('/pricing');
    });

    it('shows enabled invite button for paid tier admin users', function () {
        $license = License::factory()->create(['tier' => 'premium']);
        $user = User::factory()->create();

        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'is_current' => true,
        ]);

        // Make user an admin of the organization
        $this->organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'joined_at' => now(),
        ]);

        $this->actingAs($user);

        $this->get('/profile/organization/users')
            ->assertStatus(200)
            ->assertDontSee(__('common.upgrade_required'));
    });

    it('non-admin members are redirected from organization users page', function () {
        $license = License::factory()->create(['tier' => 'premium']);
        $user = User::factory()->create();

        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'is_current' => true,
        ]);

        // Make user a member (not admin) of the organization
        $this->organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Editor->value,
            'joined_at' => now(),
        ]);

        $this->actingAs($user);

        // Non-admin members are redirected (they cannot access the users page)
        $this->get('/profile/organization/users')
            ->assertRedirect();
    });

    it('shows enabled invite button for admin with organization paid license but no personal license', function () {
        $premiumLicense = License::factory()->create(['tier' => 'premium']);
        $user = User::factory()->create();

        // Make user an admin of the organization
        $this->organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'joined_at' => now(),
        ]);

        // Organization has premium license (user has no personal license)
        OrganizationLicense::factory()->create([
            'organization_id' => $this->organization->id,
            'license_id' => $premiumLicense->id,
            'status' => 'active',
            'is_current' => true,
        ]);

        $this->actingAs($user);

        // Admin should see enabled invite button because organization has paid license
        $this->get('/profile/organization/users')
            ->assertStatus(200)
            ->assertDontSee(__('common.upgrade_required'));
    });
});
