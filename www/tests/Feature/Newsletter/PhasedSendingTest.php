<?php

namespace Tests\Feature\Newsletter;

use App\Jobs\SendNewsletterBatch;
use App\Models\Newsletter;
use App\Models\NewsletterRecipient;
use App\Models\User;
use App\Services\NewsletterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PhasedSendingTest extends TestCase
{
    use RefreshDatabase;

    private NewsletterService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(NewsletterService::class);
        Queue::fake();
    }

    private function newsletter(): Newsletter
    {
        $creator = User::factory()->create(['newsletter_subscribed' => false]);

        return Newsletter::factory()->create(['created_by' => $creator->id]);
    }

    /**
     * Simuleert dat de batch-pipeline alle PENDING recipients heeft afgewerkt
     * door ze direct als SENT te markeren. Geen echte SES-call.
     */
    private function markAllPendingAsSent(Newsletter $newsletter): void
    {
        $newsletter->recipients()
            ->where('status', NewsletterRecipient::STATUS_PENDING)
            ->update([
                'status' => NewsletterRecipient::STATUS_SENT,
                'sent_at' => now(),
            ]);

        $newsletter->update([
            'total_sent' => $newsletter->recipients()->where('status', NewsletterRecipient::STATUS_SENT)->count(),
        ]);
    }

    public function test_send_with_limit_pauses_when_more_eligible_remain(): void
    {
        User::factory()->count(5)->create();
        $newsletter = $this->newsletter();

        $this->service->startSending($newsletter, 2, 'all');

        $this->assertSame(2, $newsletter->refresh()->total_recipients);
        $this->assertSame(Newsletter::STATUS_SENDING, $newsletter->status);

        // Simuleer dat de eerste batch (limit-batch) gestuurd is.
        $this->markAllPendingAsSent($newsletter);
        $this->service->dispatchNextBatch($newsletter);

        $newsletter->refresh();
        $this->assertSame(Newsletter::STATUS_PAUSED, $newsletter->status);
        $this->assertSame('all', $newsletter->segment_key);
        $this->assertSame(2, $newsletter->send_limit);
    }

    public function test_send_without_limit_goes_to_sent_when_no_unprocessed_remain(): void
    {
        User::factory()->count(3)->create();
        $newsletter = $this->newsletter();

        $this->service->startSending($newsletter, null, 'all');
        $this->markAllPendingAsSent($newsletter);
        $this->service->dispatchNextBatch($newsletter);

        $newsletter->refresh();
        $this->assertSame(Newsletter::STATUS_SENT, $newsletter->status);
        $this->assertNotNull($newsletter->completed_at);
    }

    public function test_send_with_limit_equal_to_eligible_finishes_as_sent(): void
    {
        User::factory()->count(2)->create();
        $newsletter = $this->newsletter();

        $this->service->startSending($newsletter, 2, 'all');
        $this->markAllPendingAsSent($newsletter);
        $this->service->dispatchNextBatch($newsletter);

        $this->assertSame(Newsletter::STATUS_SENT, $newsletter->refresh()->status);
    }

    public function test_continue_sending_collects_new_recipients_excluding_already_sent(): void
    {
        $users = User::factory()->count(5)->create();
        $newsletter = $this->newsletter();

        $this->service->startSending($newsletter, 2, 'all');
        $firstBatchUserIds = $newsletter->recipients()->pluck('user_id')->all();
        $this->markAllPendingAsSent($newsletter);
        $this->service->dispatchNextBatch($newsletter);

        $this->assertSame(Newsletter::STATUS_PAUSED, $newsletter->refresh()->status);

        // Now continue.
        $this->service->continueSending($newsletter);
        $newsletter->refresh();

        $this->assertSame(Newsletter::STATUS_SENDING, $newsletter->status);
        $this->assertNull($newsletter->send_limit);
        $this->assertSame(5, $newsletter->total_recipients);

        // The newly added recipients must NOT overlap with the first batch.
        $newRecipientUserIds = $newsletter->recipients()
            ->whereNotIn('user_id', $firstBatchUserIds)
            ->pluck('user_id')
            ->all();
        $this->assertCount(3, $newRecipientUserIds);

        // And a batch job should be dispatched.
        Queue::assertPushed(SendNewsletterBatch::class);
    }

    public function test_continue_sending_then_finishing_results_in_sent(): void
    {
        User::factory()->count(4)->create();
        $newsletter = $this->newsletter();

        $this->service->startSending($newsletter, 2, 'all');
        $this->markAllPendingAsSent($newsletter);
        $this->service->dispatchNextBatch($newsletter);
        $this->assertSame(Newsletter::STATUS_PAUSED, $newsletter->refresh()->status);

        $this->service->continueSending($newsletter);
        // Finish the second batch
        $this->markAllPendingAsSent($newsletter);
        $this->service->dispatchNextBatch($newsletter);

        $newsletter->refresh();
        $this->assertSame(Newsletter::STATUS_SENT, $newsletter->status);
        $this->assertNotNull($newsletter->completed_at);
    }

    public function test_continue_sending_throws_when_not_paused(): void
    {
        User::factory()->count(2)->create();
        $newsletter = $this->newsletter();

        $this->service->startSending($newsletter, null, 'all');

        $this->expectException(\RuntimeException::class);
        $this->service->continueSending($newsletter);
    }

    public function test_cancel_during_paused_marks_pending_as_skipped_and_status_cancelled(): void
    {
        User::factory()->count(3)->create();
        $newsletter = $this->newsletter();

        $this->service->startSending($newsletter, 2, 'all');
        $this->markAllPendingAsSent($newsletter);
        $this->service->dispatchNextBatch($newsletter);
        $this->assertSame(Newsletter::STATUS_PAUSED, $newsletter->refresh()->status);

        // Force a pending recipient (simulates a half-processed scenario).
        $newsletter->recipients()->first()->update(['status' => NewsletterRecipient::STATUS_PENDING]);

        $this->service->cancelSending($newsletter);
        $newsletter->refresh();

        $this->assertSame(Newsletter::STATUS_CANCELLED, $newsletter->status);
        $this->assertNotNull($newsletter->completed_at);
        $this->assertSame(0, $newsletter->recipients()->where('status', NewsletterRecipient::STATUS_PENDING)->count());
        $this->assertSame(1, $newsletter->recipients()->where('status', NewsletterRecipient::STATUS_SKIPPED)->count());
    }

    public function test_has_unprocessed_recipients_returns_true_when_limit_excluded_users(): void
    {
        User::factory()->count(5)->create();
        $newsletter = $this->newsletter();

        $this->service->startSending($newsletter, 2, 'all');
        $this->markAllPendingAsSent($newsletter);

        $this->assertTrue($this->service->hasUnprocessedRecipients($newsletter));
        $this->assertSame(3, $this->service->unprocessedRecipientsCount($newsletter));
    }

    public function test_has_unprocessed_recipients_returns_false_when_segment_fully_covered(): void
    {
        User::factory()->count(2)->create();
        $newsletter = $this->newsletter();

        $this->service->startSending($newsletter, null, 'all');
        $this->markAllPendingAsSent($newsletter);

        $this->assertFalse($this->service->hasUnprocessedRecipients($newsletter));
        $this->assertSame(0, $this->service->unprocessedRecipientsCount($newsletter));
    }
}
