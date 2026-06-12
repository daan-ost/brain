<?php

namespace App\Jobs;

use App\Models\Newsletter;
use App\Models\NewsletterRecipient;
use App\Services\NewsletterService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Job for processing a batch of newsletter recipients.
 *
 * Dispatches individual SendNewsletterEmail jobs for each recipient
 * in the batch, then schedules the next batch.
 */
class SendNewsletterBatch implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /**
     * Only try once since individual emails handle their own retries.
     */
    public int $tries = 1;

    /**
     * Timeout in seconds for the batch processing.
     */
    public int $timeout = 300;

    /**
     * Unique lock duration matches the job timeout to prevent parallel batches
     * for the same newsletter from dispatching duplicate emails.
     */
    public int $uniqueFor = 300;

    /**
     * Create a new job instance.
     *
     * @param  Newsletter  $newsletter  The newsletter being sent
     * @param  int  $batchSize  Number of recipients to process in this batch
     */
    public function __construct(
        private Newsletter $newsletter,
        private int $batchSize
    ) {
        $this->onQueue('newsletters');
    }

    public function uniqueId(): string
    {
        return (string) $this->newsletter->id;
    }

    /**
     * Execute the job.
     *
     * @param  NewsletterService  $newsletterService  The newsletter service
     */
    public function handle(NewsletterService $newsletterService): void
    {
        $this->newsletter->refresh();

        // Check if newsletter is still in sending state
        if ($this->newsletter->status !== Newsletter::STATUS_SENDING) {
            Log::info('Newsletter batch skipped - newsletter not in sending state', [
                'newsletter_id' => $this->newsletter->id,
                'status' => $this->newsletter->status,
            ]);

            return;
        }

        Log::info('Processing newsletter batch', [
            'newsletter_id' => $this->newsletter->id,
            'batch_number' => $this->newsletter->current_batch,
            'batch_size' => $this->batchSize,
        ]);

        // Get pending recipients for this batch with user relationship eager loaded
        $recipients = $this->newsletter->recipients()
            ->with('user:id,name,newsletter_unsubscribe_token,newsletter_subscribed,email_bounced_at')
            ->where('status', NewsletterRecipient::STATUS_PENDING)
            ->where('attempts', '<', NewsletterRecipient::MAX_ATTEMPTS)
            ->limit($this->batchSize)
            ->get();

        if ($recipients->isEmpty()) {
            // No more recipients to process
            $newsletterService->dispatchNextBatch($this->newsletter);

            return;
        }

        // Dispatch individual email jobs
        foreach ($recipients as $recipient) {
            SendNewsletterEmail::dispatch($recipient);
        }

        // Schedule next batch check after a delay
        $newsletterService->dispatchNextBatch($this->newsletter);
    }

    /**
     * Handle a job failure.
     *
     * @param  \Throwable  $exception  The exception that caused the failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendNewsletterBatch job failed', [
            'newsletter_id' => $this->newsletter->id,
            'batch_number' => $this->newsletter->current_batch,
            'error' => $exception->getMessage(),
        ]);
    }
}
