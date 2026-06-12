<?php

namespace Tests\Feature\Profile;

use App\Models\License;
use App\Models\Organization;
use App\Models\OrganizationLicense;
use App\Models\User;
use App\Models\UserLicense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlanControllerTest extends TestCase
{
    use RefreshDatabase;

    // ===========================
    // INDEX METHOD TESTS
    // ===========================

    #[Test]
    public function it_displays_plans_page_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('profile.plans'));

        $response->assertOk();
        $response->assertViewIs('profile.plans');
        $response->assertViewHas('user');
        $response->assertViewHas('activeLicenses');
        $response->assertViewHas('creditSummary');
        $response->assertViewHas('pendingLicenses');
    }

    #[Test]
    public function guest_cannot_access_plans_page(): void
    {
        $response = $this->get(route('profile.plans'));

        $response->assertRedirect(route('login'));
    }

    // ===========================
    // PENDING LICENSES TESTS
    // ===========================

    #[Test]
    public function it_shows_pending_organizational_licenses(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $user->organizations()->attach($organization, ['role' => \App\Enums\OrganizationRole::Editor->value]);

        $license = License::factory()->create([
            'name' => 'Business License',
            'credits' => 500,
        ]);

        $pendingLicense = OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'pending',
            'billing_method' => 'invoice',
            'payment_status' => 'unpaid',
            'invoice_number' => 'INV-2025-001',
            'invoice_due_date' => now()->addDays(14),
        ]);

        $response = $this->actingAs($user)->get(route('profile.plans'));

        $response->assertOk();
        $pendingLicenses = $response->viewData('pendingLicenses');

        $this->assertCount(1, $pendingLicenses);
        $this->assertEquals($pendingLicense->id, $pendingLicenses->first()->id);
    }

    #[Test]
    public function it_does_not_show_active_licenses_in_pending_section(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $user->organizations()->attach($organization, ['role' => \App\Enums\OrganizationRole::Editor->value]);

        $license = License::factory()->create();

        // Create an active license (not pending)
        OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'active',
            'is_current' => true,
        ]);

        $response = $this->actingAs($user)->get(route('profile.plans'));

        $response->assertOk();
        $pendingLicenses = $response->viewData('pendingLicenses');

        $this->assertCount(0, $pendingLicenses);
    }

    #[Test]
    public function it_shows_pending_licenses_from_multiple_organizations(): void
    {
        $user = User::factory()->create();

        $org1 = Organization::factory()->create(['name' => 'Org 1']);
        $org2 = Organization::factory()->create(['name' => 'Org 2']);

        $user->organizations()->attach($org1, ['role' => \App\Enums\OrganizationRole::Editor->value]);
        $user->organizations()->attach($org2, ['role' => \App\Enums\OrganizationRole::Owner->value]);

        $license = License::factory()->create();

        OrganizationLicense::create([
            'organization_id' => $org1->id,
            'license_id' => $license->id,
            'status' => 'pending',
            'billing_method' => 'invoice',
            'payment_status' => 'unpaid',
        ]);

        OrganizationLicense::create([
            'organization_id' => $org2->id,
            'license_id' => $license->id,
            'status' => 'pending',
            'billing_method' => 'invoice',
            'payment_status' => 'unpaid',
        ]);

        $response = $this->actingAs($user)->get(route('profile.plans'));

        $response->assertOk();
        $pendingLicenses = $response->viewData('pendingLicenses');

        $this->assertCount(2, $pendingLicenses);
    }

    #[Test]
    public function pending_licenses_include_license_and_organization_relations(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create(['name' => 'Test Organization']);
        $user->organizations()->attach($organization, ['role' => \App\Enums\OrganizationRole::Editor->value]);

        $license = License::factory()->create([
            'name' => 'Premium License',
            'credits' => 1000,
        ]);

        OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'pending',
            'billing_method' => 'invoice',
            'payment_status' => 'unpaid',
            'invoice_number' => 'INV-2025-002',
        ]);

        $response = $this->actingAs($user)->get(route('profile.plans'));

        $response->assertOk();
        $pendingLicenses = $response->viewData('pendingLicenses');
        $pendingLicense = $pendingLicenses->first();

        // Check relations are loaded
        $this->assertTrue($pendingLicense->relationLoaded('license'));
        $this->assertTrue($pendingLicense->relationLoaded('organization'));
        $this->assertEquals('Premium License', $pendingLicense->license->name);
        $this->assertEquals('Test Organization', $pendingLicense->organization->name);
    }

    #[Test]
    public function pending_licenses_section_shows_invoice_details(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $user->organizations()->attach($organization, ['role' => \App\Enums\OrganizationRole::Editor->value]);

        $license = License::factory()->create([
            'name' => 'Business',
            'credits' => 500,
        ]);

        $dueDate = now()->addDays(14);

        OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'pending',
            'billing_method' => 'invoice',
            'payment_status' => 'unpaid',
            'invoice_number' => 'INV-2025-003',
            'invoice_due_date' => $dueDate,
        ]);

        $response = $this->actingAs($user)->get(route('profile.plans'));

        $response->assertOk();
        $response->assertSee('INV-2025-003');
        $response->assertSee(format_date($dueDate, $user));
    }

    #[Test]
    public function it_does_not_show_pending_licenses_from_organizations_user_is_not_member_of(): void
    {
        $user = User::factory()->create();
        $otherOrganization = Organization::factory()->create();

        $license = License::factory()->create();

        // Create pending license for organization user is NOT a member of
        OrganizationLicense::create([
            'organization_id' => $otherOrganization->id,
            'license_id' => $license->id,
            'status' => 'pending',
            'billing_method' => 'invoice',
            'payment_status' => 'unpaid',
        ]);

        $response = $this->actingAs($user)->get(route('profile.plans'));

        $response->assertOk();
        $pendingLicenses = $response->viewData('pendingLicenses');

        $this->assertCount(0, $pendingLicenses);
    }

    #[Test]
    public function pending_section_is_not_shown_when_no_pending_licenses(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $user->organizations()->attach($organization, ['role' => \App\Enums\OrganizationRole::Editor->value]);

        $license = License::factory()->create();

        // Only active license, no pending
        OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'active',
            'is_current' => true,
        ]);

        $response = $this->actingAs($user)->get(route('profile.plans'));

        $response->assertOk();
        $response->assertDontSee(__('profile.pending_licenses'));
    }

    #[Test]
    public function pending_section_is_shown_when_pending_licenses_exist(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $user->organizations()->attach($organization, ['role' => \App\Enums\OrganizationRole::Editor->value]);

        $license = License::factory()->create(['name' => 'Test License']);

        OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'pending',
            'billing_method' => 'invoice',
            'payment_status' => 'unpaid',
        ]);

        $response = $this->actingAs($user)->get(route('profile.plans'));

        $response->assertOk();
        $response->assertSee(__('profile.pending_licenses'));
        $response->assertSee(__('profile.awaiting_payment'));
    }

    // ===========================
    // CANCEL RENEWAL TESTS
    // ===========================

    #[Test]
    public function user_can_cancel_own_monthly_subscription(): void
    {
        $user = User::factory()->create();
        $license = License::factory()->create([
            'tier' => 'premium',
            'billing_cycle' => 'monthly',
            'credit_reset_interval' => 'monthly',
        ]);
        $userLicense = UserLicense::create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'is_current' => true,
            'starts_at' => now()->subDays(15),
            'ends_at' => null,
        ]);

        $response = $this->actingAs($user)->post(route('profile.plans.cancel-renewal', $userLicense->id));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // License should have ends_at set
        $userLicense->refresh();
        $this->assertNotNull($userLicense->ends_at);
    }

    #[Test]
    public function user_can_cancel_own_yearly_subscription(): void
    {
        $user = User::factory()->create();
        $license = License::factory()->create([
            'tier' => 'premium',
            'billing_cycle' => 'yearly',
            'credit_reset_interval' => 'yearly',
        ]);
        $userLicense = UserLicense::create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'is_current' => true,
            'starts_at' => now()->subMonths(6),
            'ends_at' => null,
        ]);

        $response = $this->actingAs($user)->post(route('profile.plans.cancel-renewal', $userLicense->id));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $userLicense->refresh();
        $this->assertNotNull($userLicense->ends_at);
    }

    #[Test]
    public function org_admin_can_cancel_organization_subscription(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $user->organizations()->attach($organization, ['role' => \App\Enums\OrganizationRole::Owner->value]);

        $license = License::factory()->create([
            'tier' => 'business',
            'billing_cycle' => 'monthly',
            'credit_reset_interval' => 'monthly',
        ]);
        $orgLicense = OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'active',
            'is_current' => true,
            'starts_at' => now()->subDays(15),
            'ends_at' => null,
        ]);

        $response = $this->actingAs($user)->post(route('profile.plans.cancel-renewal', $orgLicense->id));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $orgLicense->refresh();
        $this->assertNotNull($orgLicense->ends_at);
    }

    #[Test]
    public function org_member_cannot_cancel_organization_subscription(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $user->organizations()->attach($organization, ['role' => \App\Enums\OrganizationRole::Editor->value]);

        $license = License::factory()->create([
            'tier' => 'business',
            'billing_cycle' => 'monthly',
        ]);
        $orgLicense = OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'active',
            'is_current' => true,
            'starts_at' => now()->subDays(15),
        ]);

        $response = $this->actingAs($user)->post(route('profile.plans.cancel-renewal', $orgLicense->id));

        $response->assertRedirect();
        $response->assertSessionHas('error');

        // License should remain unchanged
        $orgLicense->refresh();
        $this->assertNull($orgLicense->ends_at);
    }

    #[Test]
    public function user_cannot_cancel_free_license(): void
    {
        $user = User::factory()->create();
        $license = License::factory()->create([
            'tier' => 'free',
            'billing_cycle' => 'monthly',
        ]);
        $userLicense = UserLicense::create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'is_current' => true,
            'starts_at' => now()->subDays(15),
        ]);

        $response = $this->actingAs($user)->post(route('profile.plans.cancel-renewal', $userLicense->id));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    #[Test]
    public function user_cannot_cancel_onetime_license(): void
    {
        $user = User::factory()->create();
        $license = License::factory()->create([
            'tier' => 'business',
            'billing_cycle' => 'onetime',
        ]);
        $userLicense = UserLicense::create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'is_current' => true,
            'starts_at' => now()->subDays(15),
        ]);

        $response = $this->actingAs($user)->post(route('profile.plans.cancel-renewal', $userLicense->id));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    #[Test]
    public function user_cannot_cancel_nonexistent_license(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('profile.plans.cancel-renewal', 99999));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    #[Test]
    public function user_cannot_cancel_other_users_license(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $license = License::factory()->create([
            'tier' => 'premium',
            'billing_cycle' => 'monthly',
        ]);
        $otherUserLicense = UserLicense::create([
            'user_id' => $otherUser->id,
            'license_id' => $license->id,
            'status' => 'active',
            'is_current' => true,
            'starts_at' => now()->subDays(15),
        ]);

        $response = $this->actingAs($user)->post(route('profile.plans.cancel-renewal', $otherUserLicense->id));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    #[Test]
    public function guest_cannot_cancel_renewal(): void
    {
        $user = User::factory()->create();
        $license = License::factory()->create([
            'tier' => 'premium',
            'billing_cycle' => 'monthly',
        ]);
        $userLicense = UserLicense::create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
        ]);

        $response = $this->post(route('profile.plans.cancel-renewal', $userLicense->id));

        $response->assertRedirect(route('login'));
    }
}
