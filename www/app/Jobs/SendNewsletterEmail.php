<?php

namespace App\Jobs;

use App\Models\NewsletterRecipient;
use App\Services\NewsletterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Job for sending a single newsletter email to a recipient.
 *
 * Handles individual email sending with retries and failure tracking.
 */
class SendNewsletterEmail implements ShouldQueue
{
    use Queueable;

    /**
     * Number of times to retry the job.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retrying a failed job.
     */
    public int $backoff = 60;

    /**
     * Timeout in seconds for the job.
     */
    public int $timeout = 60;

    /**
     * Create a new job instance.
     *
     * @param  NewsletterRecipient  $recipient  The recipient to send email to
     */
    public function __construct(
        private NewsletterRecipient $recipient
    ) {
        $this->onQueue('newsletters');
    }

    /**
     * Execute the job.
     *
     * @param  NewsletterService  $newsletterService  The newsletter service
     */
    public function handle(NewsletterService $newsletterService): void
    {
        $this->recipient->refresh();

        // Skip if already processed
        if ($this->recipient->status !== NewsletterRecipient::STATUS_PENDING) {
            return;
        }

        // Check if newsletter is still active
        $newsletter = $this->recipient->newsletter;
        if ($newsletter->status !== \App\Models\Newsletter::STATUS_SENDING) {
            $this->recipient->markAsSkipped('Newsletter sending was stopped');

            return;
        }

        $newsletterService->processRecipient($this->recipient);
    }

    /**
     * Handle a job failure after all retries are exhausted.
     *
     * @param  \Throwable  $exception  The exception that caused the failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendNewsletterEmail job failed permanently', [
            'recipient_id' => $this->recipient->id,
            'newsletter_id' => $this->recipient->newsletter_id,
            'email' => $this->recipient->email,
            'error' => $exception->getMessage(),
        ]);

        // Refresh before checking: processRecipient may have already handled this
        $this->recipient->refresh();

        if ($this->recipient->status !== NewsletterRecipient::STATUS_FAILED) {
            $this->recipient->markAsFailed($exception->getMessage());

            if ($this->recipient->isFailed()) {
                $this->recipient->newsletter->increment('total_failed');
            }
        }
    }
}
