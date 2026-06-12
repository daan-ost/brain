<?php

namespace Tests\Feature\Organization;

use App\Models\Invitation;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Auto-Enrollment via EmailConfirmationController Tests
 *
 * These tests verify that the Verified event is properly fired
 * from EmailConfirmationController::confirm() to trigger auto-enrollment.
 *
 * This fixes the bug where auto-enrollment listener was implemented
 * but never triggered because the custom email confirmation flow
 * didn't fire the Verified event.
 *
 * Related:
 * - Bug fix: EmailConfirmationController now fires Verified event
 * - Event listener: app/Listeners/AutoEnrollUserInOrganization.php
 * - Documentation: /docs/todo_pocs/TODO_1B_autoenrollment_missing_event.md
 */
class AutoEnrollmentEmailConfirmationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: Verified event is fired from EmailConfirmationController
     *
     * This is the core bug fix test - verifies the event is fired.
     */
    public function test_verified_event_is_fired_from_email_confirmation_controller(): void
    {
        Event::fake([Verified::class]);

        // Create user
        $user = User::factory()->unverified()->create([
            'email' => 'test@example.com',
            'pending_license_assignment' => false,
        ]);

        // Generate confirmation URL
        $url = URL::temporarySignedRoute(
            'email.confirm',
            now()->addHours(24),
            ['user' => $user->id, 'hash' => sha1($user->email)]
        );

        // Visit confirmation URL
        $response = $this->actingAs($user)->get($url);

        // Assert Verified event was dispatched
        Event::assertDispatched(Verified::class, function ($event) use ($user) {
            return $event->user->id === $user->id;
        });
    }

    /**
     * Test: Normal signup → email confirm → auto-enroll
     *
     * SCENARIO 1: User signs up, confirms email, gets auto-enrolled
     */
    public function test_normal_signup_auto_enrolls_after_email_confirmation(): void
    {
        // Create organization with verified domain
        $org = Organization::factory()->create(['name' => 'ACME Corp']);
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'domain' => 'acme.com',
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        // User signs up with matching domain
        $user = User::factory()->unverified()->create([
            'email' => 'john@acme.com',
            'pending_license_assignment' => false,
        ]);

        // User should NOT be enrolled yet
        $this->assertFalse($org->users()->where('user_id', $user->id)->exists());

        // User confirms email via EmailConfirmationController
        $url = URL::temporarySignedRoute(
            'email.confirm',
            now()->addHours(24),
            ['user' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($url);

        // User should NOW be auto-enrolled
        $this->assertTrue(
            $org->users()->where('user_id', $user->id)->exists(),
            'User should be auto-enrolled after email confirmation'
        );

        // Verify role is 'member'
        $membership = $org->users()->where('user_id', $user->id)->first();
        $this->assertEquals(\App\Enums\OrganizationRole::Editor, $membership->pivot->role);
    }

    /**
     * Test: Invitation + auto-enroll (same org) → invitation role wins
     *
     * SCENARIO 3: User invited to Org A (admin), signs up with email matching Org A domain
     * Expected: User becomes admin (invitation role wins, no duplicate)
     */
    public function test_invitation_and_auto_enroll_same_org_invitation_role_wins(): void
    {
        // Create organization with verified domain
        $org = Organization::factory()->create(['name' => 'ACME Corp']);
        $admin = User::factory()->create();
        $org->users()->attach($admin->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        OrganizationDomain::create([
            'organization_id' => $org->id,
            'domain' => 'acme.com',
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        // Admin invites user with role 'admin'
        $invitation = Invitation::create([
            'organization_id' => $org->id,
            'email' => 'bob@acme.com',
            'invited_by' => $admin->id,
            'role' => \App\Enums\OrganizationRole::Owner->value, // Invitation role
            'status' => 'pending',
        ]);

        // User signs up with matching email
        $user = User::factory()->unverified()->create([
            'email' => 'bob@acme.com',
            'pending_license_assignment' => false,
        ]);

        // Store invitation token in session (simulates signup via invitation link)
        session(['pending_invitation_token' => $invitation->token]);

        // User confirms email
        $url = URL::temporarySignedRoute(
            'email.confirm',
            now()->addHours(24),
            ['user' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($url);

        // User should be member of organization
        $this->assertTrue($org->users()->where('user_id', $user->id)->exists());

        // Verify role is 'admin' (invitation wins)
        $membership = $org->users()->where('user_id', $user->id)->first();
        $this->assertEquals(\App\Enums\OrganizationRole::Owner, $membership->pivot->role, 'Invitation role should win over auto-enroll');

        // Verify no duplicate membership (only 1 pivot record)
        $membershipCount = $org->users()->where('user_id', $user->id)->count();
        $this->assertEquals(1, $membershipCount, 'Should have exactly 1 membership (no duplicates)');
    }

    /**
     * Test: Invitation (Org A) + auto-enroll (Org B) → both work
     *
     * SCENARIO 4: User invited to Org A, signs up with email matching both Org A and Org B domains
     * Expected: User becomes admin in Org A (invitation), member in Org B (auto-enroll)
     */
    public function test_invitation_one_org_auto_enroll_another_org_both_work(): void
    {
        // Create two organizations with same domain
        $orgA = Organization::factory()->create(['name' => 'Company A']);
        $orgB = Organization::factory()->create(['name' => 'Company B']);

        $admin = User::factory()->create();
        $orgA->users()->attach($admin->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        OrganizationDomain::create([
            'organization_id' => $orgA->id,
            'domain' => 'example.com',
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        OrganizationDomain::create([
            'organization_id' => $orgB->id,
            'domain' => 'example.com', // Same domain
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        // Admin of Org A invites user
        $invitation = Invitation::create([
            'organization_id' => $orgA->id,
            'email' => 'alice@example.com',
            'invited_by' => $admin->id,
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'status' => 'pending',
        ]);

        // User signs up
        $user = User::factory()->unverified()->create([
            'email' => 'alice@example.com',
            'pending_license_assignment' => false,
        ]);

        session(['pending_invitation_token' => $invitation->token]);

        // User confirms email
        $url = URL::temporarySignedRoute(
            'email.confirm',
            now()->addHours(24),
            ['user' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($url);

        // User should be in BOTH organizations
        $this->assertTrue($orgA->users()->where('user_id', $user->id)->exists(), 'Should be member of Org A');
        $this->assertTrue($orgB->users()->where('user_id', $user->id)->exists(), 'Should be member of Org B');

        // Verify roles
        $membershipA = $orgA->users()->where('user_id', $user->id)->first();
        $this->assertEquals(\App\Enums\OrganizationRole::Owner, $membershipA->pivot->role, 'Org A role should be admin (invitation)');

        $membershipB = $orgB->users()->where('user_id', $user->id)->first();
        $this->assertEquals(\App\Enums\OrganizationRole::Editor, $membershipB->pivot->role, 'Org B role should be member (auto-enroll)');
    }

    /**
     * Test: Multiple email confirmations don't cause duplicate enrollments
     *
     * Edge case: User clicks confirmation link multiple times
     */
    public function test_multiple_email_confirmations_dont_duplicate_enrollment(): void
    {
        // Create organization
        $org = Organization::factory()->create(['name' => 'ACME Corp']);
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'domain' => 'acme.com',
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        // User signs up
        $user = User::factory()->unverified()->create([
            'email' => 'john@acme.com',
            'pending_license_assignment' => false,
        ]);

        $url = URL::temporarySignedRoute(
            'email.confirm',
            now()->addHours(24),
            ['user' => $user->id, 'hash' => sha1($user->email)]
        );

        // First confirmation
        $this->actingAs($user)->get($url);
        $this->assertTrue($org->users()->where('user_id', $user->id)->exists());

        // Click link again (simulate user refreshing page)
        $this->actingAs($user)->get($url);

        // Should still have exactly 1 membership (no duplicates)
        $membershipCount = $org->users()->where('user_id', $user->id)->count();
        $this->assertEquals(1, $membershipCount, 'Should not create duplicate enrollment');
    }

    /**
     * Test: Public domain emails are NOT auto-enrolled
     *
     * Security test: gmail.com, yahoo.com, etc. should be blacklisted
     */
    public function test_public_domain_emails_not_auto_enrolled(): void
    {
        // Create organization with public domain (should be blocked)
        $org = Organization::factory()->create(['name' => 'Gmail Users']);
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'domain' => 'gmail.com', // Public domain
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        // User signs up with gmail
        $user = User::factory()->unverified()->create([
            'email' => 'test@gmail.com',
            'pending_license_assignment' => false,
        ]);

        $url = URL::temporarySignedRoute(
            'email.confirm',
            now()->addHours(24),
            ['user' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->actingAs($user)->get($url);

        // User should NOT be auto-enrolled (public domain blacklisted)
        $this->assertFalse(
            $org->users()->where('user_id', $user->id)->exists(),
            'Public domain emails should NOT be auto-enrolled'
        );
    }

    /**
     * Test: Analytics event is logged for auto-enrollment
     */
    public function test_analytics_event_logged_for_auto_enrollment(): void
    {
        // Create organization
        $org = Organization::factory()->create(['name' => 'ACME Corp']);
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'domain' => 'acme.com',
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        // User signs up
        $user = User::factory()->unverified()->create([
            'email' => 'john@acme.com',
            'pending_license_assignment' => false,
        ]);

        $url = URL::temporarySignedRoute(
            'email.confirm',
            now()->addHours(24),
            ['user' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->actingAs($user)->get($url);

        // Check analytics event was logged
        $this->assertDatabaseHas('analytics_events', [
            'user_id' => $user->id,
            'event' => 'user_auto_enrolled',
        ]);
    }
}
