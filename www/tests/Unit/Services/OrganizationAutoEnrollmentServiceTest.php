<?php

use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\User;
use App\Services\OrganizationAutoEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new OrganizationAutoEnrollmentService;
});

// ============================================================================
// HAPPY PATH TESTS
// ============================================================================

it('enrolls user when email domain matches validated organization domain', function () {
    // Create organization with validated domain
    $org = Organization::factory()->create(['name' => 'ACME Corp']);
    OrganizationDomain::create([
        'organization_id' => $org->id,
        'domain' => 'acme.com',
        'validated' => true,
        'validated_at' => now(),
        'auto_enroll_with_verified_domain' => true,
        'active' => true,
    ]);

    // Create user with matching email domain
    $user = User::factory()->create(['email' => 'john@acme.com']);

    // Execute enrollment
    $enrolledOrganizations = $this->service->enrollUser($user);

    // Assert user was enrolled
    expect($enrolledOrganizations)->toHaveCount(1);
    expect($enrolledOrganizations->first()->id)->toBe($org->id);

    // Verify database
    expect($org->users()->where('user_id', $user->id)->exists())->toBeTrue();

    // Verify role is 'member'
    $membership = $org->users()->where('user_id', $user->id)->first();
    expect($membership->pivot->role)->toBe(\App\Enums\OrganizationRole::Editor);
    expect($membership->pivot->joined_at)->not->toBeNull();
});

it('enrolls user in multiple organizations with same domain', function () {
    // Create two organizations with same domain
    $org1 = Organization::factory()->create(['name' => 'Company A']);
    $org2 = Organization::factory()->create(['name' => 'Company B']);

    OrganizationDomain::create([
        'organization_id' => $org1->id,
        'domain' => 'shared.com',
        'validated' => true,
        'validated_at' => now(),
        'auto_enroll_with_verified_domain' => true,
        'active' => true,
    ]);

    OrganizationDomain::create([
        'organization_id' => $org2->id,
        'domain' => 'shared.com',
        'validated' => true,
        'validated_at' => now(),
        'auto_enroll_with_verified_domain' => true,
        'active' => true,
    ]);

    $user = User::factory()->create(['email' => 'alice@shared.com']);

    // Execute enrollment
    $enrolledOrganizations = $this->service->enrollUser($user);

    // Assert user was enrolled in BOTH organizations
    expect($enrolledOrganizations)->toHaveCount(2);
    expect($org1->users()->where('user_id', $user->id)->exists())->toBeTrue();
    expect($org2->users()->where('user_id', $user->id)->exists())->toBeTrue();
});

// ============================================================================
// NEGATIVE TESTS - SHOULD NOT ENROLL
// ============================================================================

it('does not enroll user when domain is not validated', function () {
    $org = Organization::factory()->create();
    OrganizationDomain::create([
        'organization_id' => $org->id,
        'domain' => 'unvalidated.com',
        'validated' => false, // Not validated
        'validated_at' => null,
        'auto_enroll_with_verified_domain' => true,
        'active' => true,
    ]);

    $user = User::factory()->create(['email' => 'test@unvalidated.com']);

    $enrolledOrganizations = $this->service->enrollUser($user);

    expect($enrolledOrganizations)->toBeEmpty();
    expect($org->users()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('does not enroll user when auto_enroll is disabled', function () {
    $org = Organization::factory()->create();
    OrganizationDomain::create([
        'organization_id' => $org->id,
        'domain' => 'noenroll.com',
        'validated' => true,
        'validated_at' => now(),
        'auto_enroll_with_verified_domain' => false, // Disabled
        'active' => true,
    ]);

    $user = User::factory()->create(['email' => 'test@noenroll.com']);

    $enrolledOrganizations = $this->service->enrollUser($user);

    expect($enrolledOrganizations)->toBeEmpty();
    expect($org->users()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('does not enroll user when domain is inactive', function () {
    $org = Organization::factory()->create();
    OrganizationDomain::create([
        'organization_id' => $org->id,
        'domain' => 'inactive.com',
        'validated' => true,
        'validated_at' => now(),
        'auto_enroll_with_verified_domain' => true,
        'active' => false, // Inactive
    ]);

    $user = User::factory()->create(['email' => 'test@inactive.com']);

    $enrolledOrganizations = $this->service->enrollUser($user);

    expect($enrolledOrganizations)->toBeEmpty();
    expect($org->users()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('does not enroll user with public domain email', function () {
    // Create organization with public domain (should be blacklisted)
    $org = Organization::factory()->create();
    OrganizationDomain::create([
        'organization_id' => $org->id,
        'domain' => 'gmail.com',
        'validated' => true,
        'validated_at' => now(),
        'auto_enroll_with_verified_domain' => true,
        'active' => true,
    ]);

    $user = User::factory()->create(['email' => 'test@gmail.com']);

    $enrolledOrganizations = $this->service->enrollUser($user);

    // User should NOT be enrolled (public domain)
    expect($enrolledOrganizations)->toBeEmpty();
    expect($org->users()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('does not enroll user when no matching domain exists', function () {
    $org = Organization::factory()->create();
    OrganizationDomain::create([
        'organization_id' => $org->id,
        'domain' => 'company.com',
        'validated' => true,
        'validated_at' => now(),
        'auto_enroll_with_verified_domain' => true,
        'active' => true,
    ]);

    // User with different domain
    $user = User::factory()->create(['email' => 'test@different.com']);

    $enrolledOrganizations = $this->service->enrollUser($user);

    expect($enrolledOrganizations)->toBeEmpty();
    expect($org->users()->where('user_id', $user->id)->exists())->toBeFalse();
});

// ============================================================================
// EDGE CASES
// ============================================================================

it('does not create duplicate enrollment if user already member', function () {
    $org = Organization::factory()->create();
    OrganizationDomain::create([
        'organization_id' => $org->id,
        'domain' => 'test.com',
        'validated' => true,
        'validated_at' => now(),
        'auto_enroll_with_verified_domain' => true,
        'active' => true,
    ]);

    $user = User::factory()->create(['email' => 'john@test.com']);

    // Manually add user to organization first
    $org->users()->attach($user->id, [
        'role' => \App\Enums\OrganizationRole::Editor->value,
        'joined_at' => now()->subDay(),
    ]);

    // Try to enroll again
    $enrolledOrganizations = $this->service->enrollUser($user);

    // Should return empty collection (already member)
    expect($enrolledOrganizations)->toBeEmpty();

    // Should still have exactly 1 membership (no duplicates)
    $membershipCount = $org->users()->where('user_id', $user->id)->count();
    expect($membershipCount)->toBe(1);
});

it('handles invalid email format gracefully', function () {
    $org = Organization::factory()->create();
    OrganizationDomain::create([
        'organization_id' => $org->id,
        'domain' => 'test.com',
        'validated' => true,
        'validated_at' => now(),
        'auto_enroll_with_verified_domain' => true,
        'active' => true,
    ]);

    // User with invalid email (no @ symbol)
    $user = User::factory()->create(['email' => 'invalid-email']);

    $enrolledOrganizations = $this->service->enrollUser($user);

    expect($enrolledOrganizations)->toBeEmpty();
});

it('handles email with no TLD gracefully', function () {
    $org = Organization::factory()->create();
    OrganizationDomain::create([
        'organization_id' => $org->id,
        'domain' => 'localhost',
        'validated' => true,
        'validated_at' => now(),
        'auto_enroll_with_verified_domain' => true,
        'active' => true,
    ]);

    // User with email without TLD
    $user = User::factory()->create(['email' => 'test@localhost']);

    $enrolledOrganizations = $this->service->enrollUser($user);

    // Should not enroll (domain has no dot)
    expect($enrolledOrganizations)->toBeEmpty();
});

it('is case-insensitive for email domains', function () {
    $org = Organization::factory()->create();
    OrganizationDomain::create([
        'organization_id' => $org->id,
        'domain' => 'example.com', // lowercase in database
        'validated' => true,
        'validated_at' => now(),
        'auto_enroll_with_verified_domain' => true,
        'active' => true,
    ]);

    // User with uppercase domain
    $user = User::factory()->create(['email' => 'test@EXAMPLE.COM']);

    $enrolledOrganizations = $this->service->enrollUser($user);

    // Should enroll (case-insensitive)
    expect($enrolledOrganizations)->toHaveCount(1);
    expect($org->users()->where('user_id', $user->id)->exists())->toBeTrue();
});

// ============================================================================
// PUBLIC DOMAIN BLACKLIST TESTS
// ============================================================================

it('blocks all common public domains', function () {
    $publicDomains = [
        'gmail.com',
        'yahoo.com',
        'hotmail.com',
        'outlook.com',
        'icloud.com',
        'protonmail.com',
    ];

    foreach ($publicDomains as $domain) {
        $org = Organization::factory()->create();
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'domain' => $domain,
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        $user = User::factory()->create(['email' => "test@{$domain}"]);

        $enrolledOrganizations = $this->service->enrollUser($user);

        expect($enrolledOrganizations)->toBeEmpty(
            "Public domain {$domain} should be blacklisted"
        );

        // Clean up for next iteration
        $user->delete();
        $org->delete();
    }
});
