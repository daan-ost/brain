<?php

namespace Tests\Unit\Commands;

use App\Models\InboundEmail;
use App\Models\InboundEmailAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InboundCleanupCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->user = User::factory()->create();
    }

    public function test_command_cleans_up_expired_emails(): void
    {
        $email = InboundEmail::create([
            'message_id' => 'msg-cleanup-1',
            'from_email' => 'test@test.com',
            'to_email' => 'convert@inbound.test.com',
            'user_id' => $this->user->id,
            'status' => InboundEmail::STATUS_PROCESSED,
            'output_file_path' => 'inbound-output/result.pdf',
            'cleanup_scheduled_at' => now()->subDay(),
        ]);

        Storage::disk('local')->put("inbound-attachments/{$email->id}/test.pdf", 'content');
        Storage::disk('local')->put('inbound-output/result.pdf', 'output content');

        $this->artisan('inbound:cleanup')
            ->assertSuccessful()
            ->expectsOutput("Found 1 expired inbound email(s) to clean up.");

        Storage::disk('local')->assertMissing("inbound-attachments/{$email->id}/test.pdf");

        $email->refresh();
        $this->assertNull($email->output_file_path);
    }

    public function test_command_skips_non_expired_emails(): void
    {
        $email = InboundEmail::create([
            'message_id' => 'msg-not-expired',
            'from_email' => 'test@test.com',
            'to_email' => 'convert@inbound.test.com',
            'user_id' => $this->user->id,
            'status' => InboundEmail::STATUS_PROCESSED,
            'output_file_path' => 'inbound-output/result.pdf',
            'cleanup_scheduled_at' => now()->addDays(5),
        ]);

        Storage::disk('local')->put("inbound-attachments/{$email->id}/test.pdf", 'content');

        $this->artisan('inbound:cleanup')
            ->assertSuccessful()
            ->expectsOutput('No expired inbound emails to clean up.');

        Storage::disk('local')->assertExists("inbound-attachments/{$email->id}/test.pdf");
    }

    public function test_command_reports_when_nothing_to_cleanup(): void
    {
        $this->artisan('inbound:cleanup')
            ->assertSuccessful()
            ->expectsOutput('No expired inbound emails to clean up.');
    }

    public function test_dry_run_does_not_delete_files(): void
    {
        $email = InboundEmail::create([
            'message_id' => 'msg-dry-run',
            'from_email' => 'test@test.com',
            'to_email' => 'convert@inbound.test.com',
            'user_id' => $this->user->id,
            'status' => InboundEmail::STATUS_PROCESSED,
            'output_file_path' => 'inbound-output/result.pdf',
            'cleanup_scheduled_at' => now()->subDay(),
        ]);

        Storage::disk('local')->put("inbound-attachments/{$email->id}/test.pdf", 'content');

        $this->artisan('inbound:cleanup', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutput('DRY RUN - No files will be deleted');

        Storage::disk('local')->assertExists("inbound-attachments/{$email->id}/test.pdf");

        $email->refresh();
        $this->assertEquals('inbound-output/result.pdf', $email->output_file_path);
    }

    public function test_command_deletes_attachment_records(): void
    {
        $email = InboundEmail::create([
            'message_id' => 'msg-with-attachments',
            'from_email' => 'test@test.com',
            'to_email' => 'convert@inbound.test.com',
            'user_id' => $this->user->id,
            'status' => InboundEmail::STATUS_PROCESSED,
            'output_file_path' => 'inbound-output/result.pdf',
            'cleanup_scheduled_at' => now()->subDay(),
        ]);

        InboundEmailAttachment::create([
            'inbound_email_id' => $email->id,
            'original_filename' => 'doc1.pdf',
            'stored_filename' => 'stored-1',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'file_path' => "inbound-attachments/{$email->id}/stored-1",
        ]);

        InboundEmailAttachment::create([
            'inbound_email_id' => $email->id,
            'original_filename' => 'doc2.pdf',
            'stored_filename' => 'stored-2',
            'mime_type' => 'application/pdf',
            'file_size' => 2048,
            'file_path' => "inbound-attachments/{$email->id}/stored-2",
        ]);

        $this->assertEquals(2, InboundEmailAttachment::where('inbound_email_id', $email->id)->count());

        $this->artisan('inbound:cleanup')
            ->assertSuccessful();

        $this->assertEquals(0, InboundEmailAttachment::where('inbound_email_id', $email->id)->count());
    }

    public function test_command_skips_emails_without_output_path(): void
    {
        InboundEmail::create([
            'message_id' => 'msg-no-output',
            'from_email' => 'test@test.com',
            'to_email' => 'convert@inbound.test.com',
            'user_id' => $this->user->id,
            'status' => InboundEmail::STATUS_PROCESSED,
            'output_file_path' => null,
            'cleanup_scheduled_at' => now()->subDay(),
        ]);

        $this->artisan('inbound:cleanup')
            ->assertSuccessful()
            ->expectsOutput('No expired inbound emails to clean up.');
    }

    public function test_command_rejects_suspicious_paths(): void
    {
        $email = InboundEmail::create([
            'message_id' => 'msg-suspicious',
            'from_email' => 'test@test.com',
            'to_email' => 'convert@inbound.test.com',
            'user_id' => $this->user->id,
            'status' => InboundEmail::STATUS_PROCESSED,
            'output_file_path' => '../../../etc/passwd',
            'cleanup_scheduled_at' => now()->subDay(),
        ]);

        Storage::disk('local')->put("inbound-attachments/{$email->id}/test.pdf", 'content');

        $this->artisan('inbound:cleanup')
            ->assertSuccessful();

        // Directory cleanup should still happen
        Storage::disk('local')->assertMissing("inbound-attachments/{$email->id}/test.pdf");
    }

    public function test_command_handles_multiple_expired_emails(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $email = InboundEmail::create([
                'message_id' => "msg-multi-{$i}",
                'from_email' => 'test@test.com',
                'to_email' => 'convert@inbound.test.com',
                'user_id' => $this->user->id,
                'status' => InboundEmail::STATUS_PROCESSED,
                'output_file_path' => "inbound-output/result-{$i}.pdf",
                'cleanup_scheduled_at' => now()->subDay(),
            ]);

            Storage::disk('local')->put("inbound-attachments/{$email->id}/test.pdf", "content {$i}");
        }

        $this->artisan('inbound:cleanup')
            ->assertSuccessful()
            ->expectsOutput('Found 3 expired inbound email(s) to clean up.')
            ->expectsOutputToContain('Cleanup complete: 3 cleaned, 0 errors.');
    }

    public function test_command_only_processes_allowed_output_paths(): void
    {
        $email = InboundEmail::create([
            'message_id' => 'msg-valid-path',
            'from_email' => 'test@test.com',
            'to_email' => 'convert@inbound.test.com',
            'user_id' => $this->user->id,
            'status' => InboundEmail::STATUS_PROCESSED,
            'output_file_path' => 'inbound-output/user-123/result.pdf',
            'cleanup_scheduled_at' => now()->subDay(),
        ]);

        Storage::disk('local')->put('inbound-output/user-123/result.pdf', 'content');

        $this->artisan('inbound:cleanup')
            ->assertSuccessful();

        Storage::disk('local')->assertMissing('inbound-output/user-123/result.pdf');
    }
}
