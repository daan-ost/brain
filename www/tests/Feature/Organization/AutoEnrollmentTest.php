<?php

namespace Tests\Feature\Organization;

use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Auto-Enrollment Positive Tests
 *
 * These tests verify that auto-enrollment WORKS CORRECTLY.
 * Users with verified emails matching organization domains should be
 * automatically enrolled into those organizations.
 *
 * Coverage:
 * - Happy path: user with matching domain is auto-enrolled
 * - Multiple organizations: user joins ALL matching orgs
 * - Edge cases: inactive domains, disabled auto_enroll, etc.
 *
 * Related:
 * - Event listener: app/Listeners/AutoEnrollUserInOrganization.php
 * - Gap tests: tests/Feature/Organization/AutoEnrollmentGapsTest.php
 * - Documentation: /docs/todo_0_autoenrollment.md
 */
class AutoEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: User is auto-enrolled when email is verified (happy path)
     *
     * This is the core functionality test.
     */
    public function test_user_is_auto_enrolled_when_email_is_verified(): void
    {
        // Create organization with verified domain and auto-enrollment enabled
        $org = Organization::factory()->create(['name' => 'Test Company']);
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'domain' => 'company.com',
            'validated' => true, // Manually validated by admin
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        // Create user with matching email domain
        $user = User::factory()->unverified()->create([
            'email' => 'john@company.com',
        ]);

        // User should NOT be enrolled yet (email not verified)
        $this->assertFalse($org->users()->where('user_id', $user->id)->exists());

        // User verifies email (triggers Verified event)
        $user->markEmailAsVerified();
        event(new Verified($user));

        // User should NOW be enrolled in organization
        $this->assertTrue(
            $org->users()->where('user_id', $user->id)->exists(),
            'User should be auto-enrolled after email verification'
        );

        // Verify user has 'member' role
        $membership = $org->users()->where('user_id', $user->id)->first();
        $this->assertEquals(\App\Enums\OrganizationRole::Editor, $membership->pivot->role);

        // Verify joined_at timestamp is set
        $this->assertNotNull($membership->pivot->joined_at);
    }

    /**
     * Test: User is enrolled into MULTIPLE organizations if multiple domains match
     */
    public function test_user_enrolls_in_multiple_organizations_with_same_domain(): void
    {
        // Create 2 organizations with the same verified domain
        $org1 = Organization::factory()->create(['name' => 'Company A']);
        $org2 = Organization::factory()->create(['name' => 'Company B']);

        OrganizationDomain::create([
            'organization_id' => $org1->id,
            'domain' => 'startup.com',
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        OrganizationDomain::create([
            'organization_id' => $org2->id,
            'domain' => 'startup.com', // Same domain
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        // Create user with matching email
        $user = User::factory()->create([
            'email' => 'alice@startup.com',
            'email_verified_at' => now(),
        ]);

        event(new Verified($user));

        // User should be enrolled in BOTH organizations
        $this->assertTrue($org1->users()->where('user_id', $user->id)->exists());
        $this->assertTrue($org2->users()->where('user_id', $user->id)->exists());

        // Verify user is member of 2 organizations
        $this->assertEquals(2, $user->organizations()->count());
    }

    /**
     * Test: User does NOT enroll when domain is NOT validated
     */
    public function test_user_does_not_enroll_when_domain_is_not_validated(): void
    {
        $org = Organization::factory()->create();
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'domain' => 'company.com',
            'validated' => false, // NOT validated
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        $user = User::factory()->create([
            'email' => 'john@company.com',
            'email_verified_at' => now(),
        ]);

        event(new Verified($user));

        // User should NOT be enrolled (domain not validated)
        $this->assertFalse($org->users()->where('user_id', $user->id)->exists());
    }

    /**
     * Test: User does NOT enroll when auto_enroll flag is disabled
     */
    public function test_user_does_not_enroll_when_auto_enroll_is_disabled(): void
    {
        $org = Organization::factory()->create();
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'domain' => 'company.com',
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => false, // DISABLED
            'active' => true,
        ]);

        $user = User::factory()->create([
            'email' => 'john@company.com',
            'email_verified_at' => now(),
        ]);

        event(new Verified($user));

        // User should NOT be enrolled (auto_enroll disabled)
        $this->assertFalse($org->users()->where('user_id', $user->id)->exists());
    }

    /**
     * Test: User does NOT enroll when domain is inactive
     */
    public function test_user_does_not_enroll_when_domain_is_inactive(): void
    {
        $org = Organization::factory()->create();
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'domain' => 'company.com',
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => false, // INACTIVE
        ]);

        $user = User::factory()->create([
            'email' => 'john@company.com',
            'email_verified_at' => now(),
        ]);

        event(new Verified($user));

        // User should NOT be enrolled (domain inactive)
        $this->assertFalse($org->users()->where('user_id', $user->id)->exists());
    }

    /**
     * Test: User with public domain email is NOT enrolled (security)
     */
    public function test_user_with_public_domain_is_not_enrolled(): void
    {
        $org = Organization::factory()->create();
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'domain' => 'gmail.com', // Public domain (blacklisted)
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        $user = User::factory()->create([
            'email' => 'john@gmail.com',
            'email_verified_at' => now(),
        ]);

        event(new Verified($user));

        // User should NOT be enrolled (public domain blacklist)
        $this->assertFalse($org->users()->where('user_id', $user->id)->exists());
    }

    /**
     * Test: User with non-matching domain is NOT enrolled
     */
    public function test_user_with_non_matching_domain_is_not_enrolled(): void
    {
        $org = Organization::factory()->create();
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'domain' => 'company.com',
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        $user = User::factory()->create([
            'email' => 'jane@different.com', // Different domain
            'email_verified_at' => now(),
        ]);

        event(new Verified($user));

        // User should NOT be enrolled (domain doesn't match)
        $this->assertFalse($org->users()->where('user_id', $user->id)->exists());
    }

    /**
     * Test: User who is already a member is NOT enrolled again (duplicate prevention)
     */
    public function test_user_already_member_is_not_enrolled_again(): void
    {
        $org = Organization::factory()->create();
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'domain' => 'company.com',
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        $user = User::factory()->create([
            'email' => 'john@company.com',
            'email_verified_at' => now(),
        ]);

        // User is already a member
        $org->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Owner->value, // Existing role
            'joined_at' => now()->subDays(10),
        ]);

        // Trigger event again
        event(new Verified($user));

        // User should still be a member (not duplicated)
        $this->assertEquals(1, $org->users()->where('user_id', $user->id)->count());

        // Role should NOT change (stays 'admin')
        $membership = $org->users()->where('user_id', $user->id)->first();
        $this->assertEquals(\App\Enums\OrganizationRole::Owner, $membership->pivot->role);
    }

    /**
     * Test: Multiple users with matching domain are all enrolled
     */
    public function test_multiple_users_with_matching_domain_are_all_enrolled(): void
    {
        $org = Organization::factory()->create();
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'domain' => 'startup.com',
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        // Create 3 users with matching emails
        $users = [
            User::factory()->create(['email' => 'alice@startup.com', 'email_verified_at' => now()]),
            User::factory()->create(['email' => 'bob@startup.com', 'email_verified_at' => now()]),
            User::factory()->create(['email' => 'charlie@startup.com', 'email_verified_at' => now()]),
        ];

        // Trigger email verification for all users
        foreach ($users as $user) {
            event(new Verified($user));
        }

        // ALL users should be enrolled
        foreach ($users as $user) {
            $this->assertTrue(
                $org->users()->where('user_id', $user->id)->exists(),
                "User {$user->email} should be auto-enrolled"
            );
        }

        // Organization should have 3 members
        $this->assertEquals(3, $org->users()->count());
    }

    /**
     * Test: Domain matching is case-insensitive
     */
    public function test_domain_matching_is_case_insensitive(): void
    {
        $org = Organization::factory()->create();
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'domain' => 'Company.COM', // Mixed case
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        $user = User::factory()->create([
            'email' => 'john@company.com', // Lowercase
            'email_verified_at' => now(),
        ]);

        event(new Verified($user));

        // User should be enrolled (case-insensitive match)
        $this->assertTrue($org->users()->where('user_id', $user->id)->exists());
    }
}
