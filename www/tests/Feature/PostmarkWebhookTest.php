<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostmarkWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $webhookSecret = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.postmark.webhook_secret' => $this->webhookSecret]);
    }

    private function generateSignature(array $payload): string
    {
        $jsonPayload = json_encode($payload);

        return base64_encode(hash_hmac('sha256', $jsonPayload, $this->webhookSecret, true));
    }

    public function test_rejects_request_without_signature(): void
    {
        $response = $this->postJson('/webhooks/postmark', [
            'RecordType' => 'Delivery',
        ]);

        $response->assertStatus(403);
    }

    public function test_rejects_request_with_invalid_signature(): void
    {
        $response = $this->postJson('/webhooks/postmark', [
            'RecordType' => 'Delivery',
        ], [
            'X-Postmark-Signature' => 'invalid-signature',
        ]);

        $response->assertStatus(403);
    }

    public function test_handles_delivery_webhook(): void
    {
        $payload = [
            'RecordType' => 'Delivery',
            'MessageID' => 'test-message-id',
            'Recipient' => 'user@example.com',
        ];

        $response = $this->postJson('/webhooks/postmark', $payload, [
            'X-Postmark-Signature' => $this->generateSignature($payload),
        ]);

        $response->assertStatus(200);
    }

    public function test_handles_open_webhook(): void
    {
        $payload = [
            'RecordType' => 'Open',
            'MessageID' => 'test-message-id',
            'Recipient' => 'user@example.com',
        ];

        $response = $this->postJson('/webhooks/postmark', $payload, [
            'X-Postmark-Signature' => $this->generateSignature($payload),
        ]);

        $response->assertStatus(200);
    }

    public function test_handles_hard_bounce_and_marks_user(): void
    {
        $user = User::factory()->create([
            'email' => 'bounced@example.com',
            'email_bounced_at' => null,
        ]);

        $payload = [
            'RecordType' => 'Bounce',
            'MessageID' => 'test-message-id',
            'Email' => 'bounced@example.com',
            'Type' => 'HardBounce',
            'Description' => 'The server was unable to deliver your message',
        ];

        $response = $this->postJson('/webhooks/postmark', $payload, [
            'X-Postmark-Signature' => $this->generateSignature($payload),
        ]);

        $response->assertStatus(200);

        $user->refresh();
        $this->assertNotNull($user->email_bounced_at);
        $this->assertEquals('HardBounce', $user->email_bounce_type);
        $this->assertEquals('The server was unable to deliver your message', $user->email_bounce_reason);
        $this->assertEquals('test-message-id', $user->last_postmark_message_id);
    }

    public function test_handles_spam_notification_and_marks_user(): void
    {
        $user = User::factory()->create([
            'email' => 'spam@example.com',
            'email_bounced_at' => null,
        ]);

        $payload = [
            'RecordType' => 'Bounce',
            'MessageID' => 'test-message-id',
            'Email' => 'spam@example.com',
            'Type' => 'SpamNotification',
            'Description' => 'User marked as spam',
        ];

        $response = $this->postJson('/webhooks/postmark', $payload, [
            'X-Postmark-Signature' => $this->generateSignature($payload),
        ]);

        $response->assertStatus(200);

        $user->refresh();
        $this->assertNotNull($user->email_bounced_at);
        $this->assertEquals('SpamNotification', $user->email_bounce_type);
    }

    public function test_ignores_soft_bounce(): void
    {
        $user = User::factory()->create([
            'email' => 'softbounce@example.com',
            'email_bounced_at' => null,
        ]);

        $payload = [
            'RecordType' => 'Bounce',
            'MessageID' => 'test-message-id',
            'Email' => 'softbounce@example.com',
            'Type' => 'SoftBounce',
            'Description' => 'Temporary delivery failure',
        ];

        $response = $this->postJson('/webhooks/postmark', $payload, [
            'X-Postmark-Signature' => $this->generateSignature($payload),
        ]);

        $response->assertStatus(200);

        $user->refresh();
        $this->assertNull($user->email_bounced_at);
    }

    public function test_handles_bounce_for_unknown_user(): void
    {
        // No user with this email exists

        $payload = [
            'RecordType' => 'Bounce',
            'MessageID' => 'test-message-id',
            'Email' => 'unknown@example.com',
            'Type' => 'HardBounce',
            'Description' => 'Invalid email',
        ];

        $response = $this->postJson('/webhooks/postmark', $payload, [
            'X-Postmark-Signature' => $this->generateSignature($payload),
        ]);

        // Should still return 200 (webhook processed, user just doesn't exist)
        $response->assertStatus(200);
    }
}
