<?php

namespace Tests\Feature\Organization;

use App\Models\Invitation;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Auto-Enrollment View Data Tests
 *
 * These tests verify that the email confirmation view receives the correct
 * data about organization enrollments (both invitation-based and domain-based).
 *
 * The email-confirmed.blade.php view should display:
 * - Accepted invitations (via $acceptedInvitation)
 * - Auto-enrolled organizations (via $autoEnrolledOrganizations)
 *
 * Related:
 * - Controller: EmailConfirmationController::confirm()
 * - View: resources/views/auth/email-confirmed.blade.php
 * - Service: OrganizationAutoEnrollmentService
 */
class AutoEnrollmentViewDataTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: View receives acceptedInvitation data when invitation is accepted
     */
    public function test_view_receives_accepted_invitation_data(): void
    {
        // Create organization and admin
        $org = Organization::factory()->create(['name' => 'ACME Corp']);
        $admin = User::factory()->create();
        $org->users()->attach($admin->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        // Create invitation
        $invitation = Invitation::create([
            'organization_id' => $org->id,
            'email' => 'invitee@example.com',
            'invited_by' => $admin->id,
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'status' => 'pending',
        ]);

        // User signs up
        $user = User::factory()->unverified()->create([
            'email' => 'invitee@example.com',
            'pending_license_assignment' => false,
        ]);

        // Store invitation token in session
        session(['pending_invitation_token' => $invitation->token]);

        // User confirms email
        $url = URL::temporarySignedRoute(
            'email.confirm',
            now()->addHours(24),
            ['user' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($url);

        // Assert view has acceptedInvitation data
        $response->assertViewHas('acceptedInvitation', function ($viewInvitation) use ($invitation, $org) {
            return $viewInvitation !== null
                && $viewInvitation->id === $invitation->id
                && $viewInvitation->organization->name === $org->name
                && $viewInvitation->role === \App\Enums\OrganizationRole::Owner->value;
        });

        // Assert view shows invitation acceptance message
        $response->assertSee(__('auth.organization_invitation_accepted'), false);
        $response->assertSee('ACME Corp', false);
    }

    /**
     * Test: View receives autoEnrolledOrganizations data when domain matches
     */
    public function test_view_receives_auto_enrolled_organizations_data(): void
    {
        // Create organization with validated domain
        $org = Organization::factory()->create(['name' => 'Tech Company']);
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'domain' => 'techcompany.com',
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        // User signs up with matching domain
        $user = User::factory()->unverified()->create([
            'email' => 'employee@techcompany.com',
            'pending_license_assignment' => false,
        ]);

        // User confirms email
        $url = URL::temporarySignedRoute(
            'email.confirm',
            now()->addHours(24),
            ['user' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($url);

        // Assert view has autoEnrolledOrganizations data
        $response->assertViewHas('autoEnrolledOrganizations', function ($organizations) use ($org) {
            return $organizations->count() === 1
                && $organizations->first()->id === $org->id
                && $organizations->first()->name === 'Tech Company';
        });

        // Assert view shows auto-enrollment message
        $response->assertSee(__('auth.auto_enrolled_title'), false);
        $response->assertSee('Tech Company', false);
    }

    /**
     * Test: View receives data for BOTH invitation and auto-enrollment
     */
    public function test_view_receives_both_invitation_and_auto_enrollment_data(): void
    {
        // Create two organizations
        $orgA = Organization::factory()->create(['name' => 'Company A']);
        $orgB = Organization::factory()->create(['name' => 'Company B']);

        $admin = User::factory()->create();
        $orgA->users()->attach($admin->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        // Setup domain for both organizations
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
            'domain' => 'example.com',
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        // Create invitation to Org A
        $invitation = Invitation::create([
            'organization_id' => $orgA->id,
            'email' => 'multi@example.com',
            'invited_by' => $admin->id,
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'status' => 'pending',
        ]);

        // User signs up
        $user = User::factory()->unverified()->create([
            'email' => 'multi@example.com',
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

        // Assert view has BOTH pieces of data
        $response->assertViewHas('acceptedInvitation', function ($viewInvitation) {
            return $viewInvitation !== null
                && $viewInvitation->organization->name === 'Company A';
        });

        $response->assertViewHas('autoEnrolledOrganizations', function ($organizations) {
            // Should only include Org B (Org A was via invitation, not auto-enroll)
            return $organizations->count() === 1
                && $organizations->first()->name === 'Company B';
        });

        // Assert both messages are shown
        $response->assertSee(__('auth.organization_invitation_accepted'), false);
        $response->assertSee(__('auth.auto_enrolled_title'), false);
    }

    /**
     * Test: View receives empty autoEnrolledOrganizations when no domain match
     */
    public function test_view_receives_empty_auto_enrolled_organizations_when_no_match(): void
    {
        // Create organization with domain that won't match
        $org = Organization::factory()->create(['name' => 'Other Company']);
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'domain' => 'othercompany.com',
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        // User with different domain
        $user = User::factory()->unverified()->create([
            'email' => 'user@different.com',
            'pending_license_assignment' => false,
        ]);

        $url = URL::temporarySignedRoute(
            'email.confirm',
            now()->addHours(24),
            ['user' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($url);

        // Assert view has empty collection
        $response->assertViewHas('autoEnrolledOrganizations', function ($organizations) {
            return $organizations->isEmpty();
        });

        // Assert auto-enrollment message is NOT shown
        $response->assertDontSee(__('auth.auto_enrolled_title'), false);
    }

    /**
     * Test: View receives null acceptedInvitation when no invitation exists
     */
    public function test_view_receives_null_accepted_invitation_when_no_invitation(): void
    {
        // User signs up without invitation
        $user = User::factory()->unverified()->create([
            'email' => 'regular@example.com',
            'pending_license_assignment' => false,
        ]);

        $url = URL::temporarySignedRoute(
            'email.confirm',
            now()->addHours(24),
            ['user' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($url);

        // Assert acceptedInvitation is null
        $response->assertViewHas('acceptedInvitation', null);

        // Assert invitation message is NOT shown
        $response->assertDontSee(__('auth.organization_invitation_accepted'), false);
    }

    /**
     * Test: View receives multiple auto-enrolled organizations
     */
    public function test_view_receives_multiple_auto_enrolled_organizations(): void
    {
        // Create 3 organizations with same domain
        $org1 = Organization::factory()->create(['name' => 'Alpha Corp']);
        $org2 = Organization::factory()->create(['name' => 'Beta LLC']);
        $org3 = Organization::factory()->create(['name' => 'Gamma Inc']);

        foreach ([$org1, $org2, $org3] as $org) {
            OrganizationDomain::create([
                'organization_id' => $org->id,
                'domain' => 'multiorg.com',
                'validated' => true,
                'validated_at' => now(),
                'auto_enroll_with_verified_domain' => true,
                'active' => true,
            ]);
        }

        $user = User::factory()->unverified()->create([
            'email' => 'employee@multiorg.com',
            'pending_license_assignment' => false,
        ]);

        $url = URL::temporarySignedRoute(
            'email.confirm',
            now()->addHours(24),
            ['user' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($url);

        // Assert all 3 organizations are in view data
        $response->assertViewHas('autoEnrolledOrganizations', function ($organizations) {
            return $organizations->count() === 3
                && $organizations->pluck('name')->contains('Alpha Corp')
                && $organizations->pluck('name')->contains('Beta LLC')
                && $organizations->pluck('name')->contains('Gamma Inc');
        });

        // Assert all organization names are visible
        $response->assertSee('Alpha Corp', false);
        $response->assertSee('Beta LLC', false);
        $response->assertSee('Gamma Inc', false);
    }

    /**
     * Test: No duplicate enrollments when clicking confirmation link multiple times
     */
    public function test_no_duplicate_enrollments_on_repeated_email_confirmation(): void
    {
        $org = Organization::factory()->create(['name' => 'Stable Company']);
        OrganizationDomain::create([
            'organization_id' => $org->id,
            'domain' => 'stable.com',
            'validated' => true,
            'validated_at' => now(),
            'auto_enroll_with_verified_domain' => true,
            'active' => true,
        ]);

        $user = User::factory()->unverified()->create([
            'email' => 'user@stable.com',
            'pending_license_assignment' => false,
        ]);

        $url = URL::temporarySignedRoute(
            'email.confirm',
            now()->addHours(24),
            ['user' => $user->id, 'hash' => sha1($user->email)]
        );

        // First confirmation
        $response1 = $this->actingAs($user)->get($url);
        $response1->assertViewHas('autoEnrolledOrganizations', function ($orgs) {
            return $orgs->count() === 1;
        });

        // Verify user was enrolled
        $this->assertTrue($org->users()->where('user_id', $user->id)->exists());

        // Second confirmation (user clicks link again)
        // Should redirect since email is already confirmed
        $response2 = $this->actingAs($user)->get($url);
        $response2->assertRedirect(route('dashboard'));

        // Should still have exactly 1 membership (no duplicates)
        $membershipCount = $org->users()->where('user_id', $user->id)->count();
        $this->assertEquals(1, $membershipCount, 'Should not create duplicate enrollment');
    }
}
