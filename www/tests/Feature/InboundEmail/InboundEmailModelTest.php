<?php

namespace Tests\Feature\InboundEmail;

use App\Models\InboundEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundEmailModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_uuid_on_create(): void
    {
        $user = User::factory()->create();

        $email = InboundEmail::create([
            'message_id' => 'test-message-id',
            'from_email' => 'sender@example.com',
            'to_email' => 'merge+token@inbound.test.com',
            'user_id' => $user->id,
            'status' => InboundEmail::STATUS_RECEIVED,
        ]);

        $this->assertNotNull($email->uuid);
        $this->assertEquals(36, strlen($email->uuid)); // UUID format
    }

    public function test_extract_action_from_email(): void
    {
        $this->assertEquals('merge', InboundEmail::extractActionFromEmail('merge+abc123@inbound.test.com'));
        $this->assertEquals('convert', InboundEmail::extractActionFromEmail('convert+xyz789@inbound.test.com'));
        $this->assertEquals('upload', InboundEmail::extractActionFromEmail('upload+token@domain.com'));

        // Edge cases
        $this->assertNull(InboundEmail::extractActionFromEmail('plain@example.com'));
        $this->assertNull(InboundEmail::extractActionFromEmail('invalid-format'));
        $this->assertEquals('merge', InboundEmail::extractActionFromEmail('MERGE+abc123@domain.com'));
    }

    public function test_extract_token_from_email(): void
    {
        $this->assertEquals('abc123', InboundEmail::extractTokenFromEmail('merge+abc123@inbound.test.com'));
        $this->assertEquals('xyz789', InboundEmail::extractTokenFromEmail('convert+xyz789@inbound.test.com'));
        $this->assertEquals('MixedCase123', InboundEmail::extractTokenFromEmail('action+MixedCase123@domain.com'));

        // Edge cases
        $this->assertNull(InboundEmail::extractTokenFromEmail('plain@example.com'));
        $this->assertNull(InboundEmail::extractTokenFromEmail('invalid-format'));
    }

    public function test_status_methods(): void
    {
        $user = User::factory()->create();

        $email = InboundEmail::create([
            'message_id' => 'test-message-id',
            'from_email' => 'sender@example.com',
            'to_email' => 'merge+token@inbound.test.com',
            'user_id' => $user->id,
            'status' => InboundEmail::STATUS_RECEIVED,
        ]);

        // Initially not processed
        $this->assertFalse($email->isProcessed());
        $this->assertFalse($email->hasFailed());

        // Mark as processing
        $email->markAsProcessing();
        $email->refresh();
        $this->assertEquals(InboundEmail::STATUS_PROCESSING, $email->status);

        // Mark as processed
        $email->markAsProcessed();
        $email->refresh();
        $this->assertTrue($email->isProcessed());
        $this->assertNotNull($email->processed_at);

        // Test failed status
        $email2 = InboundEmail::create([
            'message_id' => 'test-message-id-2',
            'from_email' => 'sender@example.com',
            'to_email' => 'merge+token@inbound.test.com',
            'user_id' => $user->id,
            'status' => InboundEmail::STATUS_RECEIVED,
        ]);

        $email2->markAsFailed('Test failure reason');
        $email2->refresh();
        $this->assertTrue($email2->hasFailed());
        $this->assertEquals('Test failure reason', $email2->processing_notes);
    }

    public function test_add_processing_note(): void
    {
        $user = User::factory()->create();

        $email = InboundEmail::create([
            'message_id' => 'test-message-id',
            'from_email' => 'sender@example.com',
            'to_email' => 'merge+token@inbound.test.com',
            'user_id' => $user->id,
            'status' => InboundEmail::STATUS_RECEIVED,
        ]);

        $email->addProcessingNote('First note');
        $email->refresh();
        $this->assertStringContainsString('First note', $email->processing_notes);

        $email->addProcessingNote('Second note');
        $email->refresh();
        $this->assertStringContainsString('First note', $email->processing_notes);
        $this->assertStringContainsString('Second note', $email->processing_notes);
    }

    public function test_virus_scan_status_methods(): void
    {
        $user = User::factory()->create();

        $email = InboundEmail::create([
            'message_id' => 'test-message-id',
            'from_email' => 'sender@example.com',
            'to_email' => 'merge+token@inbound.test.com',
            'user_id' => $user->id,
            'status' => InboundEmail::STATUS_RECEIVED,
            'virus_scan_status' => InboundEmail::VIRUS_SCAN_PENDING,
        ]);

        $this->assertFalse($email->isVirusClean());
        $this->assertFalse($email->hasVirus());

        $email->update(['virus_scan_status' => InboundEmail::VIRUS_SCAN_CLEAN]);
        $email->refresh();
        $this->assertTrue($email->isVirusClean());

        $email->update(['virus_scan_status' => InboundEmail::VIRUS_SCAN_INFECTED]);
        $email->refresh();
        $this->assertTrue($email->hasVirus());
    }

    public function test_scopes(): void
    {
        $user = User::factory()->create();

        // Create emails with different statuses
        InboundEmail::create([
            'message_id' => 'processed-1',
            'from_email' => 'sender@example.com',
            'to_email' => 'merge+token@inbound.test.com',
            'user_id' => $user->id,
            'status' => InboundEmail::STATUS_PROCESSED,
        ]);

        InboundEmail::create([
            'message_id' => 'failed-1',
            'from_email' => 'sender@example.com',
            'to_email' => 'merge+token@inbound.test.com',
            'user_id' => $user->id,
            'status' => InboundEmail::STATUS_FAILED,
        ]);

        InboundEmail::create([
            'message_id' => 'received-1',
            'from_email' => 'sender@example.com',
            'to_email' => 'merge+token@inbound.test.com',
            'user_id' => $user->id,
            'status' => InboundEmail::STATUS_RECEIVED,
        ]);

        $this->assertEquals(1, InboundEmail::processed()->count());
        $this->assertEquals(1, InboundEmail::failed()->count());
    }

    public function test_user_relationship(): void
    {
        $user = User::factory()->create();

        $email = InboundEmail::create([
            'message_id' => 'test-message-id',
            'from_email' => 'sender@example.com',
            'to_email' => 'merge+token@inbound.test.com',
            'user_id' => $user->id,
            'status' => InboundEmail::STATUS_RECEIVED,
        ]);

        $this->assertEquals($user->id, $email->user->id);
    }

    public function test_route_key_name_is_uuid(): void
    {
        $email = new InboundEmail;

        $this->assertEquals('uuid', $email->getRouteKeyName());
    }

    public function test_mark_as_completed(): void
    {
        $user = User::factory()->create();

        $email = InboundEmail::create([
            'message_id' => 'test-message-id',
            'from_email' => 'sender@example.com',
            'to_email' => 'merge+token@inbound.test.com',
            'user_id' => $user->id,
            'status' => InboundEmail::STATUS_PROCESSING,
        ]);

        $email->markAsCompleted(5, 'output/result.pdf');

        $email->refresh();
        $this->assertEquals(InboundEmail::STATUS_PROCESSED, $email->status);
        $this->assertEquals(5, $email->output_file_count);
        $this->assertEquals('output/result.pdf', $email->output_file_path);
        $this->assertNotNull($email->completed_at);
        $this->assertNotNull($email->processed_at);
    }

    public function test_schedule_cleanup(): void
    {
        $user = User::factory()->create();

        $email = InboundEmail::create([
            'message_id' => 'test-message-id',
            'from_email' => 'sender@example.com',
            'to_email' => 'merge+token@inbound.test.com',
            'user_id' => $user->id,
            'status' => InboundEmail::STATUS_PROCESSED,
        ]);

        $email->scheduleCleanup(7);

        $email->refresh();
        $this->assertNotNull($email->cleanup_scheduled_at);
        $this->assertTrue($email->cleanup_scheduled_at->isAfter(now()->addDays(6)));
        $this->assertTrue($email->cleanup_scheduled_at->isBefore(now()->addDays(8)));
    }

    public function test_is_output_available(): void
    {
        $user = User::factory()->create();

        // No output path
        $email1 = InboundEmail::create([
            'message_id' => 'test-1',
            'from_email' => 'sender@example.com',
            'to_email' => 'merge+token@inbound.test.com',
            'user_id' => $user->id,
            'status' => InboundEmail::STATUS_PROCESSED,
        ]);
        $this->assertFalse($email1->isOutputAvailable());

        // Has output path, not expired
        $email2 = InboundEmail::create([
            'message_id' => 'test-2',
            'from_email' => 'sender@example.com',
            'to_email' => 'merge+token@inbound.test.com',
            'user_id' => $user->id,
            'status' => InboundEmail::STATUS_PROCESSED,
            'output_file_path' => 'output/result.pdf',
            'cleanup_scheduled_at' => now()->addDays(5),
        ]);
        $this->assertTrue($email2->isOutputAvailable());

        // Has output path, expired
        $email3 = InboundEmail::create([
            'message_id' => 'test-3',
            'from_email' => 'sender@example.com',
            'to_email' => 'merge+token@inbound.test.com',
            'user_id' => $user->id,
            'status' => InboundEmail::STATUS_PROCESSED,
            'output_file_path' => 'output/result.pdf',
            'cleanup_scheduled_at' => now()->subDay(),
        ]);
        $this->assertFalse($email3->isOutputAvailable());
    }

    public function test_get_days_until_expiry(): void
    {
        $user = User::factory()->create();

        // No cleanup scheduled
        $email1 = InboundEmail::create([
            'message_id' => 'test-1',
            'from_email' => 'sender@example.com',
            'to_email' => 'merge+token@inbound.test.com',
            'user_id' => $user->id,
            'status' => InboundEmail::STATUS_PROCESSED,
        ]);
        $this->assertNull($email1->getDaysUntilExpiry());

        // Days until expiry (between 4-5 days due to time precision)
        $email2 = InboundEmail::create([
            'message_id' => 'test-2',
            'from_email' => 'sender@example.com',
            'to_email' => 'merge+token@inbound.test.com',
            'user_id' => $user->id,
            'status' => InboundEmail::STATUS_PROCESSED,
            'cleanup_scheduled_at' => now()->addDays(5),
        ]);
        $daysRemaining = $email2->getDaysUntilExpiry();
        $this->assertTrue($daysRemaining >= 4 && $daysRemaining <= 5);

        // Already expired
        $email3 = InboundEmail::create([
            'message_id' => 'test-3',
            'from_email' => 'sender@example.com',
            'to_email' => 'merge+token@inbound.test.com',
            'user_id' => $user->id,
            'status' => InboundEmail::STATUS_PROCESSED,
            'cleanup_scheduled_at' => now()->subDays(2),
        ]);
        $this->assertEquals(0, $email3->getDaysUntilExpiry());
    }

    public function test_for_user_history_scope(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        // Create emails for our user
        InboundEmail::create([
            'message_id' => 'user-email-1',
            'from_email' => 'sender@example.com',
            'to_email' => 'merge+token@inbound.test.com',
            'user_id' => $user->id,
            'status' => InboundEmail::STATUS_PROCESSED,
        ]);

        InboundEmail::create([
            'message_id' => 'user-email-2',
            'from_email' => 'sender@example.com',
            'to_email' => 'merge+token@inbound.test.com',
            'user_id' => $user->id,
            'status' => InboundEmail::STATUS_FAILED,
        ]);

        // Create email for other user
        InboundEmail::create([
            'message_id' => 'other-user-email',
            'from_email' => 'sender@example.com',
            'to_email' => 'merge+token@inbound.test.com',
            'user_id' => $otherUser->id,
            'status' => InboundEmail::STATUS_PROCESSED,
        ]);

        $history = InboundEmail::forUserHistory($user->id, 20)->get();

        $this->assertEquals(2, $history->count());
        $this->assertTrue($history->every(fn ($email) => $email->user_id === $user->id));
    }

    public function test_virus_detected_status_constant(): void
    {
        $this->assertEquals('virus_detected', InboundEmail::STATUS_VIRUS_DETECTED);
    }

    public function test_encrypted_fields_are_encrypted_in_database(): void
    {
        $user = User::factory()->create();

        $email = InboundEmail::create([
            'message_id' => 'test-encryption',
            'from_email' => 'sender@example.com',
            'from_name' => 'Test Sender',
            'to_email' => 'merge+token@inbound.test.com',
            'subject' => 'Test Subject',
            'body_text' => 'Test body text',
            'user_id' => $user->id,
            'status' => InboundEmail::STATUS_RECEIVED,
        ]);

        // Fetch raw from database
        $raw = \DB::table('inbound_emails')->where('id', $email->id)->first();

        // Encrypted values should not match plaintext (they should be base64 encoded)
        $this->assertNotEquals('sender@example.com', $raw->from_email);
        $this->assertNotEquals('Test Sender', $raw->from_name);
        $this->assertNotEquals('Test Subject', $raw->subject);

        // Verify encryption format (Laravel encrypted values start with eyJ)
        $this->assertStringStartsWith('eyJ', $raw->from_email);
        $this->assertStringStartsWith('eyJ', $raw->from_name);
        $this->assertStringStartsWith('eyJ', $raw->subject);

        // Fetch fresh model to verify automatic decryption
        $freshEmail = InboundEmail::find($email->id);
        $this->assertEquals('sender@example.com', $freshEmail->from_email);
        $this->assertEquals('Test Sender', $freshEmail->from_name);
        $this->assertEquals('Test Subject', $freshEmail->subject);
    }
}
