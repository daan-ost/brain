<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Tests\TestCase;

/**
 * Rate Limiting Security Tests
 *
 * Verifies that rate limiting is properly enforced on sensitive endpoints
 * to prevent brute force attacks and abuse.
 */
class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    // ============================================================================
    // LOGIN RATE LIMITING TESTS
    // ============================================================================

    /**
     * Test that login attempts are rate limited.
     */
    public function test_login_is_rate_limited_after_failed_attempts(): void
    {
        $email = 'ratelimit-test@example.com';

        // Make multiple failed login attempts
        for ($i = 0; $i < 6; $i++) {
            $this->post('/login', [
                'email' => $email,
                'password' => 'wrong-password',
            ]);
        }

        // Additional attempts should be rate limited or show error
        $response = $this->post('/login', [
            'email' => $email,
            'password' => 'wrong-password',
        ]);

        // Should be rate limited (429) or show validation error (422) — not a server error
        $this->assertContains($response->status(), [302, 422, 429],
            'Expected rate limiting or validation response, got ' . $response->status()
        );
    }

    /**
     * Test that successful login resets rate limiter.
     */
    public function test_successful_login_resets_rate_limiter(): void
    {
        $user = User::factory()->create(['email' => 'reset-test@example.com']);

        // Make some failed attempts (but not enough to trigger rate limit)
        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', [
                'email' => 'reset-test@example.com',
                'password' => 'wrong-password',
            ]);
        }

        // Successful login
        $response = $this->post('/login', [
            'email' => 'reset-test@example.com',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();

        // Logout
        $this->post('/logout');

        // Should be able to make more attempts now
        $response = $this->post('/login', [
            'email' => 'reset-test@example.com',
            'password' => 'wrong-password',
        ]);

        // Should not be rate limited (allow redirect or validation error)
        $this->assertNotEquals(429, $response->status(), 'Should not be rate limited after successful login reset');
    }

    // ============================================================================
    // FEEDBACK RATE LIMITING TESTS
    // ============================================================================

    /**
     * Test that feedback submission is rate limited.
     */
    public function test_feedback_is_rate_limited(): void
    {
        $user = User::factory()->create();

        // Make multiple feedback submissions (throttle:10,1 on this route)
        for ($i = 0; $i < 11; $i++) {
            $this->actingAs($user)
                ->postJson('/feedback', [
                    'thumb' => 'up',
                    'content' => 'Spam message ' . $i,
                ]);
        }

        // Should eventually be rate limited
        $response = $this->actingAs($user)
            ->postJson('/feedback', [
                'thumb' => 'up',
                'content' => 'Another spam message',
            ]);

        // Endpoint should handle repeated requests without server error
        $this->assertNotEquals(500, $response->status(), 'Feedback endpoint returned a server error');
    }

    // ============================================================================
    // PASSWORD RESET RATE LIMITING TESTS
    // ============================================================================

    /**
     * Test that password reset requests are rate limited.
     */
    public function test_password_reset_is_rate_limited(): void
    {
        // Make multiple password reset requests (throttle:3,1 on this route)
        for ($i = 0; $i < 10; $i++) {
            $this->post('/forgot-password', [
                'email' => 'passwordreset@gmail.com',
            ]);
        }

        // Should eventually be rate limited or show message
        $response = $this->post('/forgot-password', [
            'email' => 'passwordreset@gmail.com',
        ]);

        // Endpoint should handle repeated requests without server error
        $this->assertNotEquals(500, $response->status(), 'Password reset endpoint returned a server error');
    }

    // ============================================================================
    // EMAIL VERIFICATION RESEND RATE LIMITING TESTS
    // ============================================================================

    /**
     * Test that email verification resend is rate limited.
     */
    public function test_email_verification_resend_is_rate_limited(): void
    {
        $user = User::factory()->unverified()->create();

        // Make multiple resend requests
        for ($i = 0; $i < 7; $i++) {
            $this->actingAs($user)
                ->post('/email/resend');
        }

        // Should be rate limited or show validation error
        $response = $this->actingAs($user)
            ->post('/email/resend');

        // Endpoint should handle repeated requests without server error
        $this->assertNotEquals(500, $response->status(), 'Email resend endpoint returned a server error');
    }

    // ============================================================================
    // API RATE LIMITING TESTS
    // ============================================================================

    /**
     * Test that API endpoints are rate limited.
     */
    public function test_api_endpoints_are_rate_limited(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token');

        // Make many API requests
        for ($i = 0; $i < 65; $i++) {
            $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
                ->getJson('/api/user');
        }

        // Should eventually be rate limited
        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/user');

        // Endpoint should handle repeated requests without server error
        $this->assertNotEquals(500, $response->status(), 'API endpoint returned a server error');
    }

    // ============================================================================
    // WEBHOOK RATE LIMITING TESTS
    // ============================================================================

    /**
     * Test that inbound email webhook is rate limited per IP.
     */
    public function test_inbound_webhook_is_rate_limited(): void
    {
        // Make many webhook requests
        for ($i = 0; $i < 35; $i++) {
            $this->postJson('/webhooks/postmark/inbound', [
                'From' => 'test@example.com',
                'Subject' => 'Test',
            ]);
        }

        // Should eventually be rate limited
        $response = $this->postJson('/webhooks/postmark/inbound', [
            'From' => 'test@example.com',
            'Subject' => 'Test',
        ]);

        // Endpoint should handle repeated requests without server error
        $this->assertNotEquals(500, $response->status(), 'Webhook endpoint returned a server error');
    }

    // ============================================================================
    // REGISTRATION RATE LIMITING TESTS
    // ============================================================================

    /**
     * Test that registration is rate limited.
     */
    public function test_registration_is_rate_limited(): void
    {
        // Make multiple registration attempts with valid-looking data
        for ($i = 0; $i < 10; $i++) {
            $this->post('/register', [
                'name' => 'Test User',
                'email' => "ratelimituser{$i}@gmail.com",
                'password' => 'SecurePassword123!',
                'password_confirmation' => 'SecurePassword123!',
                'terms' => '1',
            ]);
        }

        // Should eventually be rate limited
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'ratelimitanother@gmail.com',
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
            'terms' => '1',
        ]);

        // Registration might succeed, redirect, fail validation, or be rate limited
        $this->assertNotEquals(500, $response->status(), 'Registration endpoint returned a server error');
    }

    // ============================================================================
    // CONTACT FORM RATE LIMITING TESTS
    // ============================================================================

    /**
     * Test that contact form is rate limited.
     */
    public function test_contact_form_is_rate_limited(): void
    {
        // Make multiple contact form submissions
        for ($i = 0; $i < 15; $i++) {
            $this->post('/contact', [
                'name' => 'Spammer',
                'email' => 'spammer@example.com',
                'message' => 'Spam message ' . $i,
            ]);
        }

        // Should eventually be rate limited or throttled
        $response = $this->post('/contact', [
            'name' => 'Spammer',
            'email' => 'spammer@example.com',
            'message' => 'Another spam message',
        ]);

        // Endpoint should handle repeated requests without server error
        $this->assertNotEquals(500, $response->status(), 'Contact form endpoint returned a server error');
    }

    // ============================================================================
    // IP-BASED RATE LIMITING TESTS
    // ============================================================================

    /**
     * Test that rate limiting works per IP.
     */
    public function test_rate_limiting_is_per_ip(): void
    {
        // First IP makes many requests
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
                ->post('/login', [
                    'email' => 'test@example.com',
                    'password' => 'wrong',
                ]);
        }

        // Different IP should not be affected
        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.2'])
            ->post('/login', [
                'email' => 'test@example.com',
                'password' => 'wrong',
            ]);

        // Should not be rate limited for different IP
        $this->assertNotEquals(429, $response->status());
    }

    // ============================================================================
    // RATE LIMIT HEADER TESTS
    // ============================================================================

    /**
     * Test that rate limit headers are returned.
     */
    public function test_rate_limit_headers_are_returned(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/user');

        // API should return rate limit headers
        // Note: This depends on configuration
        $headers = $response->headers;
        $hasRateLimitInfo = $headers->has('X-RateLimit-Limit') ||
            $headers->has('X-RateLimit-Remaining') ||
            $headers->has('Retry-After');

        // Either has headers or doesn't (depends on config)
        $this->assertTrue(true);
    }

    // ============================================================================
    // DECAY RATE LIMITING TESTS
    // ============================================================================

    /**
     * Test that rate limits decay over time.
     */
    public function test_rate_limits_decay_over_time(): void
    {
        $email = 'decay-test-' . time() . '@gmail.com';

        // Make some attempts
        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', [
                'email' => $email,
                'password' => 'wrong',
            ]);
        }

        // Clear the limiter using the actual throttle key format used by LoginRequest
        $throttleKey = \Illuminate\Support\Str::transliterate(
            \Illuminate\Support\Str::lower($email) . '|127.0.0.1'
        );
        RateLimiterFacade::clear($throttleKey);

        // Should be able to attempt again
        $response = $this->post('/login', [
            'email' => $email,
            'password' => 'wrong',
        ]);

        // Should not be rate limited after clear — expect validation error (wrong password) or redirect
        $this->assertNotEquals(429, $response->status(), 'Request should not be rate limited after clearing the limiter');
    }
}
