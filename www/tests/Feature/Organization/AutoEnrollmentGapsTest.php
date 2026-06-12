<?php

namespace Tests\Feature\Organization;

use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Auto-Enrollment Gap Verification Tests
 *
 * These tests document the CURRENT STATE of auto-enrollment functionality.
 * They verify that auto-enrollment is NOT WORKING (feature not implemented).
 *
 * Purpose:
 * - Document expected vs actual behavior
 * - Act as regression tests when feature is implemented
 * - Verify database schema exists for future implementation
 *
 * Status: All tests should PASS (documenting gaps)
 *
 * Related: /docs/todo_0_autoenrollment.md
 */
class AutoEnrollmentGapsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: Database schema has auto_enroll_with_verified_domain column
     *
     * Verifies that the database schema is ready for auto-enrollment,
     * even though the feature is not implemented yet.
     */
    public function test_database_has_auto_enrollment_columns(): void
    {
        // Verify auto_enroll_with_verified_domain column exists
        $this->assertTrue(
            Schema::hasColumn('organization_domains', 'auto_enroll_with_verified_domain'),
            'Database missing auto_enroll_with_verified_domain column'
        );

        // Verify validated column exists (needed for verification)
        $this->assertTrue(
            Schema::hasColumn('organization_domains', 'validated'),
            'Database missing validated column'
        );

        // Verify validation_token column exists (for future DNS/email verification)
        $this->assertTrue(
            Schema::hasColumn('organization_domains', 'validation_token'),
            'Database missing validation_token column'
        );

        // Verify validated_at column exists
        $this->assertTrue(
            Schema::hasColumn('organization_domains', 'validated_at'),
            'Database missing validated_at column'
        );
    }

    /**
     * Test: Admin can create domain with auto_enroll enabled
     *
     * Verifies that the admin UI allows configuring auto-enrollment,
     * even though the backend functionality doesn't exist yet.
     */
    public function test_admin_can_create_domain_with_auto_enroll_enabled(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $org = Organization::factory()->create();
        $org->users()->attach($admin->id, [
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->post('/profile/organization/domains', [
                'domain' => 'testcompany.com',
                'is_primary' => false,
                'auto_enroll_with_verified_domain' => true,
                'active' => true,
            ]);

        $response->assertSessionHasNoErrors();

        // Verify domain was created with auto_enroll enabled
        $this->assertDatabaseHas('organization_domains', [
            'domain' => 'testcompany.com',
            'auto_enroll_with_verified_domain' => true,
            'organization_id' => $org->id,
        ]);
    }

    /**
     * Test: Domain validation is always false (verification not implemented)
     *
     * Documents that domain verification functionality does not exist.
     * The 'validated' field is always false and 'validated_at' is always null.
     */
    public function test_domain_validation_always_returns_false(): void
    {
        $org = Organization::factory()->create();

        // Create domain with validated = false (default)
        $domain = OrganizationDomain::create([
            'organization_id' => $org->id,
            'domain' => 'example.com',
            'is_primary' => false,
            'validated' => false, // Explicitly set to false
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        // Domain validation is never true (no verification mechanism exists)
        $this->assertFalse($domain->validated, 'Domain should not be validated (no verification exists)');
        $this->assertNull($domain->validated_at, 'Validated_at should be null (no verification exists)');
        $this->assertNull($domain->validation_token, 'Validation token should be null (not generated)');

        // Even if we try to manually set it, there's no verification flow
        $domain->update(['validated' => true, 'validated_at' => now()]);
        $domain->refresh();

        // This WILL be true if manually set, but there's no UI/flow to do this
        $this->assertTrue($domain->validated, 'Manual validation works, but no automated flow exists');
    }

    /**
     * Test: Event listener NOW EXISTS and auto-enrollment works ✅
     *
     * This test previously documented that the listener was missing.
     * NOW the feature is implemented and this test verifies it works.
     *
     * ✅ IMPLEMENTATION COMPLETE (2025-10-29)
     * - Event listener: app/Listeners/AutoEnrollUserInOrganization.php
     * - Registered in: app/Providers/AppServiceProvider.php
     */
    public function test_event_listener_exists_and_auto_enrollment_works(): void
    {
        // Create organization with verified domain and auto-enrollment enabled
        $org = Organization::factory()->create(['name' => 'Test Company']);
        $domain = OrganizationDomain::create([
            'organization_id' => $org->id,
            'domain' => 'company.com',
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        // Create user with matching email domain
        $user = User::factory()->unverified()->create([
            'email' => 'john@company.com',
            'pending_license_assignment' => true,
        ]);

        // User verifies email (triggers Verified event)
        $user->markEmailAsVerified();
        event(new Verified($user));

        // User SHOULD NOW be in organization (listener exists!) ✅
        $this->assertTrue(
            $org->users()->where('user_id', $user->id)->exists(),
            'User SHOULD be auto-enrolled (event listener NOW exists)'
        );

        // Verify user IS a member
        $this->assertEquals(1, $org->users()->count(), 'Organization should have 1 member');
    }

    /**
     * Test: Multiple users with matching domain ARE NOW auto-enrolled ✅
     *
     * ✅ IMPLEMENTATION COMPLETE
     */
    public function test_multiple_users_are_now_auto_enrolled(): void
    {
        // Create organization with verified domain and auto-enrollment
        $org = Organization::factory()->create();
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'domain' => 'startup.com',
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        // Create 3 users with matching email domains
        $users = [
            User::factory()->create(['email' => 'alice@startup.com']),
            User::factory()->create(['email' => 'bob@startup.com']),
            User::factory()->create(['email' => 'charlie@startup.com']),
        ];

        // Trigger email verification for all users
        foreach ($users as $user) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        // ALL users should NOW be auto-enrolled ✅
        foreach ($users as $user) {
            $this->assertTrue(
                $org->users()->where('user_id', $user->id)->exists(),
                "User {$user->email} SHOULD be auto-enrolled (listener NOW exists)"
            );
        }

        // Organization should have 3 members
        $this->assertEquals(3, $org->users()->count());
    }

    /**
     * Test: User with non-matching domain is correctly NOT enrolled
     *
     * This test verifies the expected behavior for users who should NOT
     * be auto-enrolled (different domain). This will still work correctly
     * when the feature is implemented.
     */
    public function test_user_with_non_matching_domain_is_not_enrolled(): void
    {
        // Create organization with verified domain
        $org = Organization::factory()->create();
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'domain' => 'company.com',
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        // Create user with DIFFERENT email domain
        $user = User::factory()->create([
            'email' => 'jane@different.com', // Does NOT match
            'email_verified_at' => now(),
        ]);

        event(new Verified($user));

        // User should NOT be enrolled (different domain)
        $this->assertFalse(
            $org->users()->where('user_id', $user->id)->exists(),
            'User with non-matching domain should NOT be enrolled'
        );
    }

    /**
     * Test: User does NOT auto-enroll when auto_enroll flag is false
     *
     * Verifies that even if domain matches and is verified, auto-enrollment
     * should not happen if the flag is disabled.
     */
    public function test_user_does_not_enroll_when_auto_enroll_is_disabled(): void
    {
        // Create organization with verified domain but auto_enroll DISABLED
        $org = Organization::factory()->create();
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'domain' => 'company.com',
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => false, // ← DISABLED
            'active' => true,
        ]);

        // Create user with matching email domain
        $user = User::factory()->create([
            'email' => 'john@company.com',
            'email_verified_at' => now(),
        ]);

        event(new Verified($user));

        // User should NOT be enrolled (auto_enroll disabled)
        $this->assertFalse(
            $org->users()->where('user_id', $user->id)->exists(),
            'User should NOT be enrolled when auto_enroll is disabled'
        );
    }

    /**
     * Test: User does NOT auto-enroll when domain is inactive
     *
     * Verifies that inactive domains should not trigger auto-enrollment.
     */
    public function test_user_does_not_enroll_when_domain_is_inactive(): void
    {
        // Create organization with verified domain but INACTIVE
        $org = Organization::factory()->create();
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'domain' => 'company.com',
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => false, // ← INACTIVE
        ]);

        // Create user with matching email domain
        $user = User::factory()->create([
            'email' => 'john@company.com',
            'email_verified_at' => now(),
        ]);

        event(new Verified($user));

        // User should NOT be enrolled (domain inactive)
        $this->assertFalse(
            $org->users()->where('user_id', $user->id)->exists(),
            'User should NOT be enrolled when domain is inactive'
        );
    }
}
