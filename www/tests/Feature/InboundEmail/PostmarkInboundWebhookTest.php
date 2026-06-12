<?php

namespace Tests\Feature\InboundEmail;

use App\Models\InboundEmail;
use App\Models\User;
use App\Models\UserInboundEmailPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PostmarkInboundWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $webhookSecret = 'test-inbound-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['inbound.webhook_token' => $this->webhookSecret]);
        config(['inbound.enabled' => true]);
        config(['inbound.email_domain' => 'inbound.test.com']);
    }

    private function generateSignature(array $payload): string
    {
        $jsonPayload = json_encode($payload);

        return base64_encode(hash_hmac('sha256', $jsonPayload, $this->webhookSecret, true));
    }

    private function createUserWithInboundEnabled(): array
    {
        $user = User::factory()->create();
        $preference = UserInboundEmailPreference::create([
            'user_id' => $user->id,
            'inbound_enabled' => true,
            'verify_sender' => false,
        ]);

        // Get the generated token for merge action
        $token = $preference->available_actions['merge']['token'] ?? 'test-token';

        return [$user, $preference, $token];
    }

    private function buildPayload(string $token, string $action = 'merge', array $overrides = []): array
    {
        return array_merge([
            'MessageID' => 'test-message-'.uniqid(),
            'From' => 'sender@example.com',
            'FromName' => 'Test Sender',
            'To' => "{$action}+{$token}@inbound.test.com",
            'Subject' => 'Test Email Subject',
            'TextBody' => 'This is the text body',
            'HtmlBody' => '<p>This is the HTML body</p>',
            'Headers' => [
                ['Name' => 'Received', 'Value' => 'from mail.example.com'],
            ],
        ], $overrides);
    }

    public function test_rejects_request_without_signature_in_production(): void
    {
        // Simulate production environment
        $this->app->detectEnvironment(fn () => 'production');

        $payload = $this->buildPayload('any-token');

        $response = $this->postJson('/webhooks/postmark/inbound', $payload);

        $response->assertStatus(401);
    }

    public function test_rejects_request_with_invalid_signature(): void
    {
        $payload = $this->buildPayload('any-token');

        $response = $this->postJson('/webhooks/postmark/inbound', $payload, [
            'X-Postmark-Signature' => 'invalid-signature',
        ]);

        $response->assertStatus(401);
    }

    public function test_accepts_valid_signature(): void
    {
        Queue::fake();

        [$user, $preference, $token] = $this->createUserWithInboundEnabled();
        $payload = $this->buildPayload($token);

        $response = $this->postJson('/webhooks/postmark/inbound', $payload, [
            'X-Postmark-Signature' => $this->generateSignature($payload),
        ]);

        $response->assertStatus(200);
    }

    public function test_rejects_request_missing_required_fields(): void
    {
        $payload = ['SomeField' => 'value']; // Missing To, From, MessageID

        $response = $this->postJson('/webhooks/postmark/inbound', $payload, [
            'X-Postmark-Signature' => $this->generateSignature($payload),
        ]);

        $response->assertStatus(400);
    }

    public function test_rejects_invalid_token(): void
    {
        $payload = $this->buildPayload('invalid-nonexistent-token');

        $response = $this->postJson('/webhooks/postmark/inbound', $payload, [
            'X-Postmark-Signature' => $this->generateSignature($payload),
        ]);

        $response->assertStatus(200); // Returns 200 but creates rejected email

        $this->assertDatabaseHas('inbound_emails', [
            'status' => InboundEmail::STATUS_BOUNCED,
        ]);
    }

    public function test_rejects_when_user_has_disabled_inbound(): void
    {
        $user = User::factory()->create();
        $preference = UserInboundEmailPreference::create([
            'user_id' => $user->id,
            'inbound_enabled' => false, // Disabled
            'verify_sender' => false,
        ]);

        $token = $preference->available_actions['merge']['token'];
        $payload = $this->buildPayload($token);

        $response = $this->postJson('/webhooks/postmark/inbound', $payload, [
            'X-Postmark-Signature' => $this->generateSignature($payload),
        ]);

        $response->assertStatus(200);

        // When inbound is disabled, findByToken returns null (only searches enabled preferences)
        // This is correct behavior - we don't reveal that the token exists for a disabled user
        $this->assertDatabaseHas('inbound_emails', [
            'status' => InboundEmail::STATUS_BOUNCED,
            'processing_notes' => 'Rejected: invalid_token',
        ]);
    }

    public function test_rejects_when_sender_verification_fails(): void
    {
        $user = User::factory()->create(['email' => 'user@trusted.com']);
        $preference = UserInboundEmailPreference::create([
            'user_id' => $user->id,
            'inbound_enabled' => true,
            'verify_sender' => true, // Enabled
        ]);

        $token = $preference->available_actions['merge']['token'];
        $payload = $this->buildPayload($token, 'merge', [
            'From' => 'attacker@untrusted.com', // Different from user email
        ]);

        $response = $this->postJson('/webhooks/postmark/inbound', $payload, [
            'X-Postmark-Signature' => $this->generateSignature($payload),
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('inbound_emails', [
            'status' => InboundEmail::STATUS_BOUNCED,
            'processing_notes' => 'Rejected: sender_not_trusted',
        ]);
    }

    public function test_accepts_when_sender_verification_passes(): void
    {
        Queue::fake();

        $user = User::factory()->create(['email' => 'user@trusted.com']);
        $preference = UserInboundEmailPreference::create([
            'user_id' => $user->id,
            'inbound_enabled' => true,
            'verify_sender' => true,
        ]);

        $token = $preference->available_actions['merge']['token'];
        $payload = $this->buildPayload($token, 'merge', [
            'From' => 'User Name <user@trusted.com>', // Matches user email
        ]);

        $response = $this->postJson('/webhooks/postmark/inbound', $payload, [
            'X-Postmark-Signature' => $this->generateSignature($payload),
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('inbound_emails', [
            'user_id' => $user->id,
            'status' => InboundEmail::STATUS_RECEIVED,
        ]);
    }

    public function test_creates_inbound_email_record(): void
    {
        Queue::fake();

        [$user, $preference, $token] = $this->createUserWithInboundEnabled();
        $payload = $this->buildPayload($token, 'merge', [
            'Subject' => 'Important Document',
            'TextBody' => 'Please process this.',
        ]);

        $response = $this->postJson('/webhooks/postmark/inbound', $payload, [
            'X-Postmark-Signature' => $this->generateSignature($payload),
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('inbound_emails', [
            'user_id' => $user->id,
            'message_id' => $payload['MessageID'],
            'action_type' => 'merge',
            'status' => InboundEmail::STATUS_RECEIVED,
        ]);
    }

    public function test_prevents_duplicate_message_processing(): void
    {
        Queue::fake();

        [$user, $preference, $token] = $this->createUserWithInboundEnabled();
        $payload = $this->buildPayload($token, 'merge', [
            'MessageID' => 'duplicate-message-id',
        ]);

        // First request
        $response1 = $this->postJson('/webhooks/postmark/inbound', $payload, [
            'X-Postmark-Signature' => $this->generateSignature($payload),
        ]);
        $response1->assertStatus(200);

        // Second request with same MessageID
        $response2 = $this->postJson('/webhooks/postmark/inbound', $payload, [
            'X-Postmark-Signature' => $this->generateSignature($payload),
        ]);
        $response2->assertStatus(200);

        // Should only have one record
        $this->assertEquals(1, InboundEmail::where('message_id', 'duplicate-message-id')->count());
    }

    public function test_rejects_email_with_too_many_hops(): void
    {
        [$user, $preference, $token] = $this->createUserWithInboundEnabled();

        // Create headers with 51+ Received headers (over the limit)
        $headers = [];
        for ($i = 0; $i < 55; $i++) {
            $headers[] = ['Name' => 'Received', 'Value' => "from server{$i}.example.com"];
        }

        $payload = $this->buildPayload($token, 'merge', [
            'Headers' => $headers,
        ]);

        $response = $this->postJson('/webhooks/postmark/inbound', $payload, [
            'X-Postmark-Signature' => $this->generateSignature($payload),
        ]);

        $response->assertStatus(500); // Exception thrown
    }

    public function test_rejects_when_feature_disabled_globally(): void
    {
        config(['inbound.enabled' => false]);

        [$user, $preference, $token] = $this->createUserWithInboundEnabled();
        $payload = $this->buildPayload($token);

        $response = $this->postJson('/webhooks/postmark/inbound', $payload, [
            'X-Postmark-Signature' => $this->generateSignature($payload),
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('inbound_emails', [
            'status' => InboundEmail::STATUS_BOUNCED,
            'processing_notes' => 'Rejected: feature_disabled',
        ]);
    }

    public function test_detects_nested_emails_in_attachments(): void
    {
        Queue::fake();

        [$user, $preference, $token] = $this->createUserWithInboundEnabled();
        $payload = $this->buildPayload($token, 'merge', [
            'Attachments' => [
                [
                    'Name' => 'forwarded.eml',
                    'ContentType' => 'message/rfc822',
                    'Content' => base64_encode('nested email content'),
                ],
            ],
        ]);

        $response = $this->postJson('/webhooks/postmark/inbound', $payload, [
            'X-Postmark-Signature' => $this->generateSignature($payload),
        ]);

        $response->assertStatus(200);

        $email = InboundEmail::first();
        $this->assertEquals(1, $email->nested_email_count);
    }

    public function test_extracts_email_from_name_email_format(): void
    {
        Queue::fake();

        $user = User::factory()->create(['email' => 'john@example.com']);
        $preference = UserInboundEmailPreference::create([
            'user_id' => $user->id,
            'inbound_enabled' => true,
            'verify_sender' => true,
        ]);

        $token = $preference->available_actions['merge']['token'];
        $payload = $this->buildPayload($token, 'merge', [
            'From' => 'John Doe <john@example.com>',
        ]);

        $response = $this->postJson('/webhooks/postmark/inbound', $payload, [
            'X-Postmark-Signature' => $this->generateSignature($payload),
        ]);

        $response->assertStatus(200);

        // Should pass verification because email matches
        $this->assertDatabaseHas('inbound_emails', [
            'user_id' => $user->id,
            'status' => InboundEmail::STATUS_RECEIVED,
        ]);
    }
}
