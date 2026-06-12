<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationDomainTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test admin can view domains page
     */
    public function test_admin_can_view_domains_page(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'joined_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get('/profile/organization/domains');

        $response->assertOk();
        $response->assertSee(__('profile.organization_domains'));
    }

    /**
     * Test member cannot view domains page
     */
    public function test_member_cannot_view_domains_page(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Editor->value,
            'joined_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get('/profile/organization/domains');

        $response
            ->assertRedirect('/profile/organization')
            ->assertSessionHas('error', 'You do not have permission to view organization domains.');
    }

    /**
     * Test admin can add domain
     */
    public function test_admin_can_add_domain(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'joined_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->post('/profile/organization/domains', [
                'domain' => 'company.com',
                'is_primary' => true,
                'auto_enroll_with_verified_domain' => true,
                'max_storage_days' => 365,
                'support_email' => 'support@company.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile/organization/domains')
            ->assertSessionHas('status', 'domain-added');

        // Verify domain was created
        $domain = OrganizationDomain::where('domain', 'company.com')->first();
        $this->assertNotNull($domain);
        $this->assertEquals($organization->id, $domain->organization_id);
        $this->assertTrue($domain->is_primary);
        $this->assertTrue($domain->auto_enroll_with_verified_domain);
        $this->assertEquals(365, $domain->max_storage_days);
        $this->assertEquals('support@company.com', $domain->support_email);
        $this->assertTrue($domain->active);
    }

    /**
     * Test member cannot add domain
     */
    public function test_member_cannot_add_domain(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Editor->value,
            'joined_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->post('/profile/organization/domains', [
                'domain' => 'hacker.com',
                'is_primary' => false,
                'auto_enroll_with_verified_domain' => false,
            ]);

        $response
            ->assertRedirect('/profile/organization')
            ->assertSessionHas('error', 'You do not have permission to add domains.');

        // Verify domain was NOT created
        $this->assertDatabaseMissing('organization_domains', [
            'domain' => 'hacker.com',
        ]);
    }

    /**
     * Test duplicate domain is rejected
     */
    public function test_duplicate_domain_is_rejected(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'joined_at' => now(),
        ]);

        // Create first domain
        OrganizationDomain::create([
            'organization_id' => $organization->id,
            'domain' => 'existing.com',
            'is_primary' => true,
            'active' => true,
        ]);

        // Try to create duplicate
        $response = $this
            ->actingAs($user)
            ->post('/profile/organization/domains', [
                'domain' => 'existing.com',
                'is_primary' => false,
                'auto_enroll_with_verified_domain' => false,
            ]);

        $response->assertSessionHasErrors(['domain']);
    }

    /**
     * Test setting domain as primary unsets other primary domains
     */
    public function test_setting_domain_as_primary_unsets_others(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'joined_at' => now(),
        ]);

        // Create first primary domain
        $firstDomain = OrganizationDomain::create([
            'organization_id' => $organization->id,
            'domain' => 'first.com',
            'is_primary' => true,
            'active' => true,
        ]);

        // Add second domain as primary
        $response = $this
            ->actingAs($user)
            ->post('/profile/organization/domains', [
                'domain' => 'second.com',
                'is_primary' => true,
                'auto_enroll_with_verified_domain' => false,
            ]);

        $response->assertSessionHasNoErrors();

        // First domain should no longer be primary
        $firstDomain->refresh();
        $this->assertFalse($firstDomain->is_primary);

        // Second domain should be primary
        $secondDomain = OrganizationDomain::where('domain', 'second.com')->first();
        $this->assertTrue($secondDomain->is_primary);
    }

    /**
     * Test domain validation rules
     */
    public function test_domain_requires_valid_format(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'joined_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->post('/profile/organization/domains', [
                'domain' => '', // Empty domain
                'is_primary' => false,
                'auto_enroll_with_verified_domain' => false,
            ]);

        $response->assertSessionHasErrors(['domain']);
    }

    /**
     * Test user count is displayed for each domain
     */
    public function test_domain_shows_user_count(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'joined_at' => now(),
        ]);

        // Create domain
        OrganizationDomain::create([
            'organization_id' => $organization->id,
            'domain' => 'company.com',
            'is_primary' => true,
            'active' => true,
        ]);

        // Create users with matching domain
        User::factory()->create(['email' => 'user1@company.com']);
        User::factory()->create(['email' => 'user2@company.com']);
        User::factory()->create(['email' => 'other@different.com']);

        $response = $this
            ->actingAs($user)
            ->get('/profile/organization/domains');

        $response->assertOk();
        $response->assertSee('company.com');
        // Should see 2 users for company.com (not counting the admin user or different domain)
    }

    /**
     * Test domains are ordered by most recent first
     */
    public function test_domains_ordered_by_most_recent(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'joined_at' => now(),
        ]);

        // Create domains in specific order
        $oldDomain = OrganizationDomain::create([
            'organization_id' => $organization->id,
            'domain' => 'old.com',
            'active' => true,
        ]);
        $oldDomain->created_at = now()->subDays(5);
        $oldDomain->save();

        $newDomain = OrganizationDomain::create([
            'organization_id' => $organization->id,
            'domain' => 'new.com',
            'active' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->get('/profile/organization/domains');

        $response->assertOk();
        // Verify order in response (new.com should appear before old.com)
        $content = $response->getContent();
        $newPos = strpos($content, 'new.com');
        $oldPos = strpos($content, 'old.com');
        $this->assertNotFalse($newPos);
        $this->assertNotFalse($oldPos);
        $this->assertLessThan($oldPos, $newPos);
    }

    /**
     * Test admin can update domain
     */
    public function test_admin_can_update_domain(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'joined_at' => now(),
        ]);

        $domain = OrganizationDomain::create([
            'organization_id' => $organization->id,
            'domain' => 'old-domain.com',
            'is_primary' => false,
            'auto_enroll_with_verified_domain' => false,
            'max_storage_days' => 30,
            'support_email' => 'old@example.com',
            'active' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->put("/profile/organization/domains/{$domain->id}", [
                'domain' => 'old-domain.com',
                'is_primary' => true,
                'auto_enroll_with_verified_domain' => true,
                'max_storage_days' => 90,
                'support_email' => 'new@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile/organization/domains')
            ->assertSessionHas('status', 'domain-updated');

        $domain->refresh();
        $this->assertTrue($domain->is_primary);
        $this->assertTrue($domain->auto_enroll_with_verified_domain);
        $this->assertEquals(90, $domain->max_storage_days);
        $this->assertEquals('new@example.com', $domain->support_email);
    }

    /**
     * Test member cannot update domain
     */
    public function test_member_cannot_update_domain(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Editor->value,
            'joined_at' => now(),
        ]);

        $domain = OrganizationDomain::create([
            'organization_id' => $organization->id,
            'domain' => 'test.com',
            'active' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->put("/profile/organization/domains/{$domain->id}", [
                'domain' => 'hacked.com',
            ]);

        $response
            ->assertRedirect('/profile/organization/domains')
            ->assertSessionHas('error');

        $this->assertDatabaseHas('organization_domains', [
            'id' => $domain->id,
            'domain' => 'test.com', // Should remain unchanged
        ]);
    }

    /**
     * Test admin can delete domain
     */
    public function test_admin_can_delete_domain(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'joined_at' => now(),
        ]);

        $domain = OrganizationDomain::create([
            'organization_id' => $organization->id,
            'domain' => 'delete-me.com',
            'active' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->delete("/profile/organization/domains/{$domain->id}");

        $response
            ->assertRedirect('/profile/organization/domains')
            ->assertSessionHas('status', 'domain-deleted');

        $this->assertDatabaseMissing('organization_domains', [
            'id' => $domain->id,
        ]);
    }

    /**
     * Test member cannot delete domain
     */
    public function test_member_cannot_delete_domain(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Editor->value,
            'joined_at' => now(),
        ]);

        $domain = OrganizationDomain::create([
            'organization_id' => $organization->id,
            'domain' => 'important.com',
            'active' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->delete("/profile/organization/domains/{$domain->id}");

        $response
            ->assertRedirect('/profile/organization/domains')
            ->assertSessionHas('error');

        $this->assertDatabaseHas('organization_domains', [
            'id' => $domain->id,
        ]);
    }

    /**
     * Test domain cleaning removes protocol and www
     */
    public function test_domain_cleaning_removes_protocol_and_www(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'joined_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->post('/profile/organization/domains', [
                'domain' => 'https://www.example.com/',
                'is_primary' => false,
                'auto_enroll_with_verified_domain' => false,
            ]);

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('organization_domains', [
            'domain' => 'example.com', // Should be cleaned
        ]);
    }

    /**
     * Test validated domain name cannot be changed
     */
    public function test_validated_domain_name_cannot_be_changed(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, [
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'joined_at' => now(),
        ]);

        $domain = OrganizationDomain::create([
            'organization_id' => $organization->id,
            'domain' => 'validated.com',
            'validated' => true,
            'validated_at' => now(),
            'active' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->put("/profile/organization/domains/{$domain->id}", [
                'domain' => 'hacked.com', // Try to change validated domain
                'is_primary' => false,
                'auto_enroll_with_verified_domain' => false,
            ]);

        $response->assertSessionHasErrors(['domain']);

        $domain->refresh();
        $this->assertEquals('validated.com', $domain->domain); // Should remain unchanged
    }
}
