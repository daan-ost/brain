<?php

namespace Tests\Feature;

use App\Jobs\SendPostmarkTemplateEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailChangeFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // Don't send actual emails in tests
    }

    public function test_complete_email_change_flow_success(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'name' => 'Test User',
            'preferred_language' => 'en',
        ]);

        // Step 1: User requests email change via profile update
        $response = $this->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'new@example.com',
                'preferred_language' => 'en',
            ]);

        $response->assertRedirect('/profile/account')
            ->assertSessionHas('status', 'email-change-pending');

        $user->refresh();

        // Verify pending state
        $this->assertSame('old@example.com', $user->email);
        $this->assertSame('new@example.com', $user->pending_email);
        $this->assertNotNull($user->email_change_token);

        // Verify 2 emails sent: verification + notification
        Queue::assertPushed(SendPostmarkTemplateEmail::class, 2);

        // Step 2: User clicks verification link with token
        $rawToken = Str::random(64);
        $user->update([
            'email_change_token' => hash('sha256', $rawToken),
        ]);

        $response = $this->actingAs($user)
            ->get("/email/change/verify/{$rawToken}");

        $response->assertRedirect('/profile/account')
            ->assertSessionHas('status', 'email-changed');

        $user->refresh();

        // Verify email changed successfully
        $this->assertSame('new@example.com', $user->email);
        $this->assertNull($user->pending_email);
        $this->assertNull($user->email_change_token);
        $this->assertNotNull($user->email_verified_at);

        // Verify confirmation email sent to old address (3rd email)
        Queue::assertPushed(SendPostmarkTemplateEmail::class, 3);
    }

    public function test_email_verification_requires_authentication(): void
    {
        $token = Str::random(64);

        $response = $this->get("/email/change/verify/{$token}");

        // Guest should be redirected to login
        $response->assertRedirect('/login');
    }

    public function test_email_verification_fails_with_invalid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'pending_email' => 'new@example.com',
            'email_change_token' => hash('sha256', 'valid-token'),
            'email_change_token_expires_at' => now()->addHours(24),
        ]);

        $response = $this->actingAs($user)
            ->get('/email/change/verify/wrong-token');

        $response->assertRedirect('/profile/account')
            ->assertSessionHas('error');

        $user->refresh();

        // Email should remain unchanged
        $this->assertSame('old@example.com', $user->email);
        $this->assertSame('new@example.com', $user->pending_email);
    }

    public function test_email_verification_fails_without_pending_change(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        $token = Str::random(64);

        $response = $this->actingAs($user)
            ->get("/email/change/verify/{$token}");

        $response->assertRedirect('/profile/account')
            ->assertSessionHas('error');
    }

    public function test_cancel_pending_email_change_success(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'pending_email' => 'new@example.com',
            'email_change_token' => hash('sha256', 'some-token'),
            'email_change_token_expires_at' => now()->addHours(24),
        ]);

        $response = $this->actingAs($user)
            ->post('/email/change/cancel');

        $response->assertRedirect('/profile/account')
            ->assertSessionHas('status', 'email-change-cancelled');

        $user->refresh();

        $this->assertSame('old@example.com', $user->email);
        $this->assertNull($user->pending_email);
        $this->assertNull($user->email_change_token);
    }

    public function test_cancel_gracefully_handles_no_pending_change(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);

        $response = $this->actingAs($user)
            ->post('/email/change/cancel');

        $response->assertRedirect('/profile/account')
            ->assertSessionHas('info');
    }

    public function test_cancel_link_works_gracefully_after_verification_completed(): void
    {
        // Simulate scenario: user verified email, then clicks old cancel link
        $user = User::factory()->create([
            'email' => 'new@example.com', // Already changed
            'pending_email' => null, // No pending change
        ]);

        $response = $this->actingAs($user)
            ->post('/email/change/cancel');

        $response->assertRedirect('/profile/account')
            ->assertSessionHas('info');

        // Should not crash or show error
        $this->assertStringContainsString('pending', session('info'));
    }

    public function test_resend_verification_email_success(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'pending_email' => 'new@example.com',
            'email_change_token' => hash('sha256', 'some-token'),
            'email_change_token_expires_at' => now()->addHours(24),
            'last_email_change_request_at' => now()->subMinutes(10), // Rate limit passed
        ]);

        $response = $this->actingAs($user)
            ->post('/email/change/resend');

        $response->assertRedirect()
            ->assertSessionHas('status', 'verification-resent');

        // Verify emails sent again
        Queue::assertPushed(SendPostmarkTemplateEmail::class, 2);
    }

    public function test_resend_fails_without_pending_change(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);

        $response = $this->actingAs($user)
            ->post('/email/change/resend');

        $response->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_resend_respects_rate_limit(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'pending_email' => 'new@example.com',
            'email_change_token' => hash('sha256', 'some-token'),
            'email_change_token_expires_at' => now()->addHours(24),
            'last_email_change_request_at' => now()->subMinutes(2), // Only 2 minutes ago
        ]);

        $response = $this->actingAs($user)
            ->post('/email/change/resend');

        $response->assertRedirect()
            ->assertSessionHas('error');

        // Verify message mentions waiting
        $this->assertStringContainsString('wait', strtolower(session('error')));
    }

    public function test_email_change_race_condition_handled(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'preferred_language' => 'en',
        ]);

        // User 1 requests change to specific email
        $this->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'contested@example.com',
                'preferred_language' => 'en',
            ]);

        $user->refresh();

        // Simulate race condition: another user takes the email
        User::factory()->create(['email' => 'contested@example.com']);

        // User 1 tries to verify
        $rawToken = Str::random(64);
        $user->update([
            'email_change_token' => hash('sha256', $rawToken),
        ]);

        $response = $this->actingAs($user)
            ->get("/email/change/verify/{$rawToken}");

        $response->assertRedirect('/profile/account')
            ->assertSessionHas('error');

        $user->refresh();

        // Email should remain old, pending should be cleared
        $this->assertSame('old@example.com', $user->email);
        $this->assertNull($user->pending_email);
    }

    public function test_token_is_properly_hashed(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'preferred_language' => 'en',
        ]);

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'new@example.com',
                'preferred_language' => 'en',
            ]);

        $user->refresh();

        // Token should be 64 character SHA256 hex digest
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $user->email_change_token);
    }

    public function test_cannot_change_to_email_already_in_use(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $user = User::factory()->create(['email' => 'old@example.com']);

        $response = $this->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'taken@example.com',
                'preferred_language' => 'en',
            ]);

        // Should redirect back with validation errors
        $response->assertSessionHasErrors('email');

        $user->refresh();

        // No pending change should be created
        $this->assertNull($user->pending_email);
        $this->assertNull($user->email_change_token);
    }

    public function test_emails_are_sent_in_user_preferred_language(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'preferred_language' => 'nl',
        ]);

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'new@example.com',
                'preferred_language' => 'nl',
            ]);

        // Verify Dutch templates are used
        Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
            // Access job properties using reflection
            $reflection = new \ReflectionClass($job);
            $templateProperty = $reflection->getProperty('templateAlias');
            $templateProperty->setAccessible(true);
            $template = $templateProperty->getValue($job);

            return str_contains($template, '__nl');
        });
    }
}
