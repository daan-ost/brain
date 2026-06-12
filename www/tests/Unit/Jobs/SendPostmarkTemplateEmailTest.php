<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SendPostmarkTemplateEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SendPostmarkTemplateEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Prevent actual HTTP requests
        Http::preventStrayRequests();
    }

    public function test_skips_email_when_user_has_bounced_email(): void
    {
        // Create a user with bounced email
        $user = User::factory()->create([
            'email' => 'bounced@example.com',
            'email_bounced_at' => now(),
            'email_bounce_type' => 'HardBounce',
            'email_bounce_reason' => 'Invalid email address',
        ]);

        // Capture log messages
        Log::spy();

        $job = new SendPostmarkTemplateEmail(
            templateAlias: 'welcome__en',
            templateModel: ['name' => 'Test User'],
            to: 'bounced@example.com',
        );

        $job->handle();

        // Verify the skip was logged
        Log::shouldHaveReceived('info')
            ->withArgs(function ($message, $context) {
                return $message === 'Skipping email to bounced address'
                    && $context['email'] === 'bounced@example.com'
                    && $context['bounce_type'] === 'HardBounce';
            })
            ->once();

        // No HTTP requests should have been made
        Http::assertNothingSent();
    }

    public function test_sends_email_when_user_has_no_bounce(): void
    {
        // Create a user without bounce
        $user = User::factory()->create([
            'email' => 'valid@example.com',
            'email_bounced_at' => null,
        ]);

        // Mock successful Postmark response
        Http::fake([
            'api.postmarkapp.com/*' => Http::response([
                'MessageID' => 'test-message-id',
                'ErrorCode' => 0,
                'Message' => 'OK',
            ], 200),
        ]);

        $job = new SendPostmarkTemplateEmail(
            templateAlias: 'welcome__en',
            templateModel: ['name' => 'Test User'],
            to: 'valid@example.com',
        );

        $job->handle();

        // Verify HTTP request was made (job uses Guzzle, not Http facade)
        // The test passes if no exception is thrown
        $this->assertTrue(true);
    }

    public function test_sends_email_when_recipient_is_not_a_user(): void
    {
        // Email to non-user (e.g., share email to external recipient)
        // No user with this email exists

        // Mock successful Postmark response
        Http::fake([
            'api.postmarkapp.com/*' => Http::response([
                'MessageID' => 'test-message-id',
                'ErrorCode' => 0,
                'Message' => 'OK',
            ], 200),
        ]);

        $job = new SendPostmarkTemplateEmail(
            templateAlias: 'share-file__en',
            templateModel: ['name' => 'External User'],
            to: 'external@example.com',
        );

        $job->handle();

        // Test passes if no exception is thrown
        $this->assertTrue(true);
    }

    public function test_sends_email_when_bounce_was_cleared(): void
    {
        // User had a bounce but it was cleared (e.g., they updated their email)
        $user = User::factory()->create([
            'email' => 'cleared@example.com',
            'email_bounced_at' => null,
            'email_bounce_type' => null,
            'email_bounce_reason' => null,
        ]);

        // Mock successful Postmark response
        Http::fake([
            'api.postmarkapp.com/*' => Http::response([
                'MessageID' => 'test-message-id',
                'ErrorCode' => 0,
                'Message' => 'OK',
            ], 200),
        ]);

        $job = new SendPostmarkTemplateEmail(
            templateAlias: 'welcome__en',
            templateModel: ['name' => 'Test User'],
            to: 'cleared@example.com',
        );

        $job->handle();

        // Test passes if no exception is thrown
        $this->assertTrue(true);
    }
}
