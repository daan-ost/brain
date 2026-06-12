<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationCreditPool;
use App\Models\User;
use App\Services\VIESValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that organization page is displayed for authenticated users
     */
    public function test_organization_page_is_displayed(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this
            ->actingAs($user)
            ->get('/profile/organization');

        $response->assertOk();
        $response->assertSee(__('profile.create_organization_title'));
        $response->assertSee(__('profile.org_benefit_invite'));
    }

    /**
     * Test that unauthenticated users cannot access organization page
     */
    public function test_organization_page_requires_authentication(): void
    {
        $response = $this->get('/profile/organization');

        $response->assertRedirect('/login');
    }

    /**
     * Test organization creation with verified email
     */
    public function test_user_can_create_organization_with_verified_email(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        // Mock VIES service for VAT validation
        $this->mock(VIESValidationService::class, function ($mock) {
            $mock->shouldReceive('validateVatId')
                ->once()
                ->with('NL123456789B01')
                ->andReturn([
                    'valid' => true,
                    'vat_id' => 'NL123456789B01',
                    'country_code' => 'NL',
                    'company_name' => 'Test Company BV',
                    'checked_at' => now(),
                ]);
        });

        $response = $this
            ->actingAs($user)
            ->post('/profile/organization', [
                'name' => 'Acme Corporation',
                'billing_country_code' => 'NL',
                'currency_preference' => 'EUR',
                'vat_number' => 'NL123456789B01',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile/organization')
            ->assertSessionHas('status', 'organization-created');

        // Verify organization was created
        $organization = Organization::where('name', 'Acme Corporation')->first();
        $this->assertNotNull($organization);
        $this->assertEquals('NL', $organization->billing_country_code);
        $this->assertEquals('EUR', $organization->currency_preference);
        $this->assertEquals('NL123456789B01', $organization->vat_number);
        $this->assertNotNull($organization->vat_validated_at);

        // Verify user is admin of the organization
        $this->assertTrue(
            $organization->users()
                ->where('user_id', $user->id)
                ->wherePivot('role', \App\Enums\OrganizationRole::Owner)
                ->exists()
        );

        // Verify credit pool was created
        $creditPool = OrganizationCreditPool::where('organization_id', $organization->id)->first();
        $this->assertNotNull($creditPool);
        $this->assertEquals(0, $creditPool->balance_credits);
    }

    /**
     * Test organization creation without verified email is blocked
     */
    public function test_user_cannot_create_organization_without_verified_email(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null, // Email NOT verified
        ]);

        $response = $this
            ->actingAs($user)
            ->post('/profile/organization', [
                'name' => 'Acme Corporation',
                'billing_country_code' => 'NL',
                'currency_preference' => 'EUR',
            ]);

        $response
            ->assertRedirect('/profile/organization')
            ->assertSessionHas('error', 'You must verify your email address before creating an organization.');

        // Verify organization was NOT created
        $this->assertDatabaseMissing('organizations', [
            'name' => 'Acme Corporation',
        ]);
    }

    /**
     * Test user cannot create second organization if already member of one
     */
    public function test_user_cannot_create_organization_if_already_member(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        // Create an organization and attach user
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Editor->value,
            'joined_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->post('/profile/organization', [
                'name' => 'Second Organization',
                'billing_country_code' => 'NL',
                'currency_preference' => 'EUR',
            ]);

        $response
            ->assertRedirect('/profile/organization')
            ->assertSessionHas('error', 'You are already a member of an organization.');

        // Verify second organization was NOT created
        $this->assertDatabaseMissing('organizations', [
            'name' => 'Second Organization',
        ]);
    }

    /**
     * Test organization creation without VAT number
     */
    public function test_organization_can_be_created_without_vat_number(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this
            ->actingAs($user)
            ->post('/profile/organization', [
                'name' => 'Startup Inc',
                'billing_country_code' => 'US',
                'currency_preference' => 'USD',
                'vat_number' => '',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile/organization')
            ->assertSessionHas('status', 'organization-created');

        // Verify organization was created without VAT
        $organization = Organization::where('name', 'Startup Inc')->first();
        $this->assertNotNull($organization);
        $this->assertNull($organization->vat_number);
        $this->assertNull($organization->vat_validated_at);
    }

    /**
     * Test admin can update organization details
     */
    public function test_admin_can_update_organization(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::factory()->create([
            'name' => 'Old Name',
            'billing_country_code' => 'NL',
            'currency_preference' => 'EUR',
        ]);

        // Create credit pool
        OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 0,
        ]);

        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'joined_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->patch('/profile/organization', [
                'name' => 'New Name',
                'billing_country_code' => 'BE',
                'currency_preference' => 'EUR',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile/organization')
            ->assertSessionHas('status', 'organization-updated');

        $organization->refresh();
        $this->assertEquals('New Name', $organization->name);
        $this->assertEquals('BE', $organization->billing_country_code);
    }

    /**
     * Test member cannot update organization details
     */
    public function test_member_cannot_update_organization(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::factory()->create([
            'name' => 'Original Name',
        ]);

        // Create credit pool
        OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 0,
        ]);

        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Editor->value, // Not admin
            'joined_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->patch('/profile/organization', [
                'name' => 'Hacked Name',
                'billing_country_code' => 'NL',
                'currency_preference' => 'EUR',
            ]);

        // Should be redirected with error
        $response->assertRedirect('/profile/organization');
        $response->assertSessionHas('error', 'You do not have permission to update organization details.');

        // Organization should not be updated
        $organization->refresh();
        $this->assertEquals('Original Name', $organization->name);
    }

    /**
     * Test organization displays for members with role info
     */
    public function test_organization_displays_member_info(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $admin = User::factory()->create(['name' => 'Test Admin']);
        $organization = Organization::factory()->create([
            'name' => 'Test Org',
        ]);

        // Create credit pool
        OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 150,
        ]);

        // Attach admin first
        $organization->users()->attach($admin->id, [
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'joined_at' => now(),
        ]);

        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Editor->value,
            'joined_at' => now()->subDays(10),
        ]);

        $response = $this
            ->actingAs($user)
            ->get('/profile/organization');

        $response->assertOk();
        $response->assertSee('Test Org');
        $response->assertSee(__('profile.member'));
        $response->assertSee(__('profile.organization_credits_in_use')); // Credit usage message
    }

    /**
     * Test VAT number validation error handling
     */
    public function test_invalid_vat_number_shows_error(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::factory()->create();

        // Create credit pool
        OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 0,
        ]);

        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'joined_at' => now(),
        ]);

        // Mock VIES service to return invalid
        $this->mock(VIESValidationService::class, function ($mock) {
            $mock->shouldReceive('validateVatId')
                ->once()
                ->with('INVALID123')
                ->andReturn([
                    'valid' => false,
                    'error' => 'Invalid VAT ID format',
                    'vat_id' => 'INVALID123',
                    'checked_at' => now(),
                ]);
        });

        $response = $this
            ->actingAs($user)
            ->patch('/profile/organization', [
                'name' => $organization->name,
                'billing_country_code' => 'NL',
                'currency_preference' => 'EUR',
                'vat_number' => 'INVALID123',
            ]);

        $response
            ->assertRedirect('/profile/organization')
            ->assertSessionHas('error', 'Invalid VAT number. Please check and try again.');

        // VAT number should not be saved
        $organization->refresh();
        $this->assertNull($organization->vat_validated_at);
    }

    /**
     * Test required fields validation
     */
    public function test_organization_creation_requires_required_fields(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this
            ->actingAs($user)
            ->post('/profile/organization', [
                'name' => '',
                'billing_country_code' => '',
                'currency_preference' => '',
            ]);

        $response->assertSessionHasErrors(['name', 'billing_country_code', 'currency_preference']);
    }

    /**
     * Test organization sidebar is always visible
     */
    public function test_organization_sidebar_always_visible(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this
            ->actingAs($user)
            ->get('/profile/organization');

        // Should see the organization menu even with 0 organizations
        $response->assertOk();
        $response->assertSee(__('profile.create_organization_title'));
        $response->assertSee(__('profile.create_organization_subtitle'));
    }

    /**
     * Test member sees simplified member view
     */
    public function test_member_sees_member_view(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $admin = User::factory()->create(['email_verified_at' => now(), 'name' => 'Admin User']);

        $organization = Organization::factory()->create([
            'name' => 'Test Organization',
            'billing_country_code' => 'NL',
            'currency_preference' => 'EUR',
        ]);

        // Create credit pool with balance
        OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 100,
        ]);

        // Attach admin first
        $organization->users()->attach($admin->id, [
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'joined_at' => now()->subDays(30),
        ]);

        // Attach user as member
        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Editor->value,
            'joined_at' => now()->subDays(10),
        ]);

        $response = $this
            ->actingAs($user)
            ->get('/profile/organization');

        $response->assertOk();

        // Should see member-specific content
        $response->assertSee('Test Organization');
        $response->assertSee(__('profile.member'));
        $response->assertSee(__('profile.administrators'));
        $response->assertSee('Admin User');
        $response->assertSee($admin->email);
        $response->assertSee(__('profile.team_members'));
        $response->assertSee(__('profile.organization_credits_in_use'));

        // Should NOT see admin-only content
        $response->assertDontSee(__('profile.organization_settings'));
        $response->assertDontSee(__('profile.save_changes'));
    }

    /**
     * Test admin sees full admin view
     */
    public function test_admin_sees_admin_view(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $organization = Organization::factory()->create([
            'name' => 'Admin Test Org',
            'billing_country_code' => 'BE',
            'currency_preference' => 'EUR',
        ]);

        // Create credit pool
        OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 250,
        ]);

        // Attach user as admin
        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'joined_at' => now()->subDays(50),
        ]);

        $response = $this
            ->actingAs($user)
            ->get('/profile/organization');

        $response->assertOk();

        // Should see admin-specific content
        $response->assertSee('Admin Test Org');
        $response->assertSee(__('profile.admin'));
        $response->assertSee(__('profile.organization_settings'));
        $response->assertSee(__('profile.organization_name'));
        $response->assertSee(__('profile.country'));
        $response->assertSee(__('profile.currency'));
        $response->assertSee(__('profile.save_changes'));
        $response->assertSee('250'); // Credit balance

        // Should NOT see member-only content
        $response->assertDontSee(__('profile.administrators'));
    }

    /**
     * Test member view shows all admins
     */
    public function test_member_view_shows_all_admins(): void
    {
        $member = User::factory()->create(['email_verified_at' => now()]);
        $admin1 = User::factory()->create(['name' => 'Admin One', 'email' => 'admin1@test.com']);
        $admin2 = User::factory()->create(['name' => 'Admin Two', 'email' => 'admin2@test.com']);

        $organization = Organization::factory()->create(['name' => 'Multi Admin Org']);

        OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 0,
        ]);

        // Attach two admins
        $organization->users()->attach($admin1->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);
        $organization->users()->attach($admin2->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        // Attach member
        $organization->users()->attach($member->id, ['role' => \App\Enums\OrganizationRole::Editor->value, 'joined_at' => now()]);

        $response = $this
            ->actingAs($member)
            ->get('/profile/organization');

        $response->assertOk();

        // Should see both admins
        $response->assertSee('Admin One');
        $response->assertSee('admin1@test.com');
        $response->assertSee('Admin Two');
        $response->assertSee('admin2@test.com');
    }

    /**
     * Test member view shows correct team size
     */
    public function test_member_view_shows_correct_team_size(): void
    {
        $member = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::factory()->create(['name' => 'Team Size Test']);

        OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 0,
        ]);

        // Create 1 admin and 4 members (5 total)
        $admin = User::factory()->create();
        $organization->users()->attach($admin->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        for ($i = 0; $i < 4; $i++) {
            $user = User::factory()->create();
            $organization->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Editor->value, 'joined_at' => now()]);
        }

        // Attach test member
        $organization->users()->attach($member->id, ['role' => \App\Enums\OrganizationRole::Editor->value, 'joined_at' => now()]);

        $response = $this
            ->actingAs($member)
            ->get('/profile/organization');

        $response->assertOk();
        $response->assertSee(__('profile.team_members'));
        $response->assertSee('6'); // 1 admin + 5 members
    }

    /**
     * Test member view shows credit usage indicator when org has credits
     */
    public function test_member_view_shows_credit_usage_when_org_has_credits(): void
    {
        $member = User::factory()->create(['email_verified_at' => now()]);
        $admin = User::factory()->create();
        $organization = Organization::factory()->create(['name' => 'Credits Test']);

        // Create credit pool WITH balance
        OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 50,
        ]);

        $organization->users()->attach($admin->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);
        $organization->users()->attach($member->id, ['role' => \App\Enums\OrganizationRole::Editor->value, 'joined_at' => now()]);

        $response = $this
            ->actingAs($member)
            ->get('/profile/organization');

        $response->assertOk();
        $response->assertSee(__('profile.organization_credits_in_use'));
    }

    /**
     * Test member view does NOT show credit usage when org has zero credits
     */
    public function test_member_view_hides_credit_usage_when_org_has_zero_credits(): void
    {
        $member = User::factory()->create(['email_verified_at' => now()]);
        $admin = User::factory()->create();
        $organization = Organization::factory()->create(['name' => 'No Credits Test']);

        // Create credit pool WITHOUT balance
        OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 0,
        ]);

        $organization->users()->attach($admin->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);
        $organization->users()->attach($member->id, ['role' => \App\Enums\OrganizationRole::Editor->value, 'joined_at' => now()]);

        $response = $this
            ->actingAs($member)
            ->get('/profile/organization');

        $response->assertOk();
        $response->assertDontSee(__('profile.organization_credits_in_use'));
    }
}
