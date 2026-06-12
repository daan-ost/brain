<?php

namespace Tests\Feature\Auth;

use App\Models\CreditLedger;
use App\Models\License;
use App\Models\User;
use App\Models\UserLicense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CreditsAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create the free registration license that should exist in production
        License::create([
            'slug' => 'free-15',
            'name' => 'Free User License',
            'tier' => 'free',
            'amount' => 0,
            'currency' => 'EUR',
            'billing_cycle' => 'onetime',
            'credits' => 15,
            'period' => 15,
            'json_restrictions' => null,
            'ordering' => 100,
            'active' => true,
            'valid_from' => now(),
            'valid_until' => null,
        ]);
    }

    public function test_new_user_receives_15_credits_after_email_confirmation(): void
    {
        Notification::fake();

        // Create unverified user with pending license assignment
        $user = User::factory()->unverified()->create([
            'pending_license_assignment' => true,
            'credits' => 0,
        ]);

        // Verify user has no credits before confirmation
        $this->assertEquals(0, $user->credits);
        $this->assertTrue($user->pending_license_assignment);

        // Generate email confirmation URL
        $confirmationUrl = URL::temporarySignedRoute(
            'email.confirm',
            now()->addMinutes(60),
            ['user' => $user->id, 'hash' => sha1($user->email)]
        );

        // Visit confirmation URL
        $response = $this->get($confirmationUrl);

        // Should show confirmation page or redirect to dashboard
        $response->assertStatus(200);

        // Refresh user from database
        $user->refresh();

        // Assert credits were assigned
        $this->assertEquals(15, $user->credits);

        // Assert pending_license_assignment flag was cleared (will be 0 or false)
        $this->assertFalse((bool) $user->pending_license_assignment);

        // Assert email was verified
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_credit_ledger_entry_is_created_after_email_confirmation(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create([
            'pending_license_assignment' => true,
            'credits' => 0,
        ]);

        // Generate email confirmation URL
        $confirmationUrl = URL::temporarySignedRoute(
            'email.confirm',
            now()->addMinutes(60),
            ['user' => $user->id, 'hash' => sha1($user->email)]
        );

        // Visit confirmation URL
        $this->get($confirmationUrl);

        // Assert credit ledger entry was created
        $this->assertDatabaseHas('credit_ledger', [
            'user_id' => $user->id,
            'delta' => 15,
            'reason' => 'purchase',
            'balance_after' => 15,
        ]);

        // Assert ledger entry has correct metadata
        $ledgerEntry = CreditLedger::where('user_id', $user->id)->first();
        $this->assertNotNull($ledgerEntry);
        $this->assertArrayHasKey('license_slug', $ledgerEntry->meta);
        $this->assertEquals('free-15', $ledgerEntry->meta['license_slug']);
        $this->assertArrayHasKey('registration_bonus', $ledgerEntry->meta);
        $this->assertTrue($ledgerEntry->meta['registration_bonus']);
    }

    public function test_user_license_entry_is_created_after_email_confirmation(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create([
            'pending_license_assignment' => true,
            'credits' => 0,
        ]);

        $freeLicense = License::where('slug', 'free-15')->first();

        // Generate email confirmation URL
        $confirmationUrl = URL::temporarySignedRoute(
            'email.confirm',
            now()->addMinutes(60),
            ['user' => $user->id, 'hash' => sha1($user->email)]
        );

        // Visit confirmation URL
        $this->get($confirmationUrl);

        // Assert user license entry was created
        $this->assertDatabaseHas('user_licenses', [
            'user_id' => $user->id,
            'license_id' => $freeLicense->id,
            'status' => 'active',
            'source' => 'system_signup',
            'is_current' => true,
        ]);

        // Assert license has 15-day expiration
        $userLicense = UserLicense::where('user_id', $user->id)
            ->where('license_id', $freeLicense->id)
            ->first();

        $this->assertNotNull($userLicense);
        $this->assertNotNull($userLicense->starts_at);
        $this->assertNotNull($userLicense->ends_at);

        // Check that ends_at is approximately 15 days after starts_at
        $expectedEndDate = $userLicense->starts_at->copy()->addDays(15);
        $this->assertTrue($userLicense->ends_at->diffInMinutes($expectedEndDate) < 5);
    }

    public function test_credits_not_assigned_if_already_confirmed(): void
    {
        Notification::fake();

        // Create already verified user with credits
        $user = User::factory()->create([
            'email_verified_at' => now()->subDay(),
            'credits' => 50,
            'pending_license_assignment' => false,
        ]);

        $initialCredits = $user->credits;

        // Generate email confirmation URL
        $confirmationUrl = URL::temporarySignedRoute(
            'email.confirm',
            now()->addMinutes(60),
            ['user' => $user->id, 'hash' => sha1($user->email)]
        );

        // Visit confirmation URL again
        $response = $this->get($confirmationUrl);

        // Should redirect with message
        $response->assertRedirect(route('dashboard'));

        // Refresh user from database
        $user->refresh();

        // Credits should remain unchanged
        $this->assertEquals($initialCredits, $user->credits);

        // Should not create duplicate ledger entries
        $ledgerCount = CreditLedger::where('user_id', $user->id)->count();
        $this->assertEquals(0, $ledgerCount);
    }

    public function test_credits_not_assigned_if_license_not_found(): void
    {
        Notification::fake();

        // Remove the free license to simulate missing license scenario
        License::where('slug', 'free-15')->delete();

        $user = User::factory()->unverified()->create([
            'pending_license_assignment' => true,
            'credits' => 0,
        ]);

        // Generate email confirmation URL
        $confirmationUrl = URL::temporarySignedRoute(
            'email.confirm',
            now()->addMinutes(60),
            ['user' => $user->id, 'hash' => sha1($user->email)]
        );

        // Visit confirmation URL
        $this->get($confirmationUrl);

        // Refresh user from database
        $user->refresh();

        // Email should still be verified
        $this->assertNotNull($user->email_verified_at);

        // But no credits should be assigned (graceful failure)
        $this->assertEquals(0, $user->credits);

        // No ledger entry should be created
        $this->assertDatabaseMissing('credit_ledger', [
            'user_id' => $user->id,
        ]);
    }

    public function test_email_confirmation_fails_with_invalid_signature(): void
    {
        $user = User::factory()->unverified()->create([
            'pending_license_assignment' => true,
        ]);

        // Create URL without proper signature
        $invalidUrl = route('email.confirm', [
            'user' => $user->id,
            'hash' => sha1($user->email),
        ]);

        // Should fail with 403
        $response = $this->get($invalidUrl);
        $response->assertStatus(403);

        // User should remain unverified
        $user->refresh();
        $this->assertNull($user->email_verified_at);
        $this->assertEquals(0, $user->credits);
    }

    public function test_email_confirmation_fails_with_wrong_hash(): void
    {
        $user = User::factory()->unverified()->create([
            'pending_license_assignment' => true,
        ]);

        // Create URL with wrong hash
        $wrongHashUrl = URL::temporarySignedRoute(
            'email.confirm',
            now()->addMinutes(60),
            ['user' => $user->id, 'hash' => sha1('wrong@email.com')]
        );

        // Should fail with 403
        $response = $this->get($wrongHashUrl);
        $response->assertStatus(403);

        // User should remain unverified
        $user->refresh();
        $this->assertNull($user->email_verified_at);
        $this->assertEquals(0, $user->credits);
    }
}
