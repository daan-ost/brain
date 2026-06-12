<?php

namespace App\Services;

use App\Jobs\SendNewsletterBatch;
use App\Jobs\SendNewsletterEmail;
use App\Models\Newsletter;
use App\Models\NewsletterRecipient;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Service for managing newsletter campaigns and subscriptions.
 *
 * Handles the entire newsletter lifecycle including sending, pausing,
 * resuming, cancelling, and managing user subscriptions.
 */
class NewsletterService
{
    public function __construct(
        private SesNewsletterService $sesService,
        private NewsletterSegmentService $segmentService,
    ) {}

    /**
     * Start sending a newsletter to eligible subscribers.
     *
     * Collects recipients, sets the newsletter status to sending,
     * and dispatches the first batch for processing.
     *
     * @param  Newsletter  $newsletter  The newsletter to send
     * @param  int|null  $limit  Optional limit on number of recipients
     *
     * @throws \RuntimeException If newsletter cannot be sent in current state
     */
    public function startSending(Newsletter $newsletter, ?int $limit = null, string $segmentKey = NewsletterSegmentService::SEGMENT_ALL): void
    {
        if (! $newsletter->canBeSent()) {
            throw new \RuntimeException('Newsletter cannot be sent in its current state.');
        }

        if (! $this->segmentService->isValid($segmentKey)) {
            throw new \InvalidArgumentException("Unknown segment: {$segmentKey}");
        }

        $eligibleCount = $this->segmentService->count($segmentKey);
        if ($eligibleCount === 0) {
            throw new \RuntimeException('Geen ontvangers in dit segment.');
        }

        $newsletter->update([
            'send_limit' => $limit,
            'segment_key' => $segmentKey,
            'status' => Newsletter::STATUS_SENDING,
            'started_at' => now(),
        ]);

        $this->collectRecipients($newsletter, $limit, $segmentKey);

        $this->dispatchNextBatch($newsletter);

        Log::info('Newsletter sending started', [
            'newsletter_id' => $newsletter->id,
            'total_recipients' => $newsletter->fresh()->total_recipients,
            'limit' => $limit,
            'segment_key' => $segmentKey,
        ]);
    }

    /**
     * Pause an actively sending newsletter.
     *
     * @param  Newsletter  $newsletter  The newsletter to pause
     *
     * @throws \RuntimeException If newsletter cannot be paused in current state
     */
    public function pauseSending(Newsletter $newsletter): void
    {
        if (! $newsletter->canBePaused()) {
            throw new \RuntimeException('Newsletter cannot be paused in its current state.');
        }

        $newsletter->update(['status' => Newsletter::STATUS_PAUSED]);

        Log::info('Newsletter sending paused', [
            'newsletter_id' => $newsletter->id,
        ]);
    }

    /**
     * Resume a paused newsletter.
     *
     * @param  Newsletter  $newsletter  The newsletter to resume
     *
     * @throws \RuntimeException If newsletter cannot be resumed in current state
     */
    public function resumeSending(Newsletter $newsletter): void
    {
        if (! $newsletter->canBeResumed()) {
            throw new \RuntimeException('Newsletter cannot be resumed in its current state.');
        }

        $newsletter->update(['status' => Newsletter::STATUS_SENDING]);

        // Continue batch processing
        $this->dispatchNextBatch($newsletter);

        Log::info('Newsletter sending resumed', [
            'newsletter_id' => $newsletter->id,
        ]);
    }

    /**
     * Cancel a newsletter that is sending or paused.
     *
     * Marks all pending recipients as skipped.
     *
     * @param  Newsletter  $newsletter  The newsletter to cancel
     *
     * @throws \RuntimeException If newsletter cannot be cancelled in current state
     */
    public function cancelSending(Newsletter $newsletter): void
    {
        if (! $newsletter->canBeCancelled()) {
            throw new \RuntimeException('Newsletter cannot be cancelled in its current state.');
        }

        $newsletter->update([
            'status' => Newsletter::STATUS_CANCELLED,
            'completed_at' => now(),
        ]);

        // Mark pending recipients as skipped
        $newsletter->recipients()
            ->where('status', NewsletterRecipient::STATUS_PENDING)
            ->update([
                'status' => NewsletterRecipient::STATUS_SKIPPED,
                'error_message' => 'Newsletter cancelled',
            ]);

        Log::info('Newsletter sending cancelled', [
            'newsletter_id' => $newsletter->id,
        ]);
    }

    /**
     * Send a test email for a newsletter to verify content.
     *
     * @param  Newsletter  $newsletter  The newsletter to test
     * @param  string  $email  Email address to send test to
     *
     * @throws \InvalidArgumentException If email address is invalid
     */
    public function sendTestEmail(Newsletter $newsletter, string $email): void
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email address.');
        }

        // Get the logged-in user for locale and unsubscribe token
        $user = auth()->user();
        $locale = $user?->preferred_language ?? 'nl';
        $unsubscribeToken = $user?->newsletter_unsubscribe_token;

        $this->sesService->sendNewsletter(
            newsletter: $newsletter,
            email: $email,
            locale: $locale,
            recipientId: null,
            isTest: true,
            unsubscribeToken: $unsubscribeToken,
            personalization: ['name' => $user?->name ?? '', 'email' => $email]
        );

        Log::info('Newsletter test email sent', [
            'newsletter_id' => $newsletter->id,
            'test_email' => $email,
            'locale' => $locale,
        ]);
    }

    /**
     * Collect eligible recipients for a newsletter.
     *
     * Creates NewsletterRecipient records for all eligible users
     * in batches of 500 for memory efficiency.
     *
     * @param  Newsletter  $newsletter  The newsletter to collect recipients for
     * @param  int|null  $limit  Optional limit on number of recipients
     */
    private function collectRecipients(Newsletter $newsletter, ?int $limit, ?string $segmentKey = null, array $excludeUserIds = []): void
    {
        $segmentKey = $segmentKey ?? $newsletter->segment_key ?? NewsletterSegmentService::SEGMENT_ALL;

        $query = $this->segmentService->query($segmentKey)
            ->select(['users.id', 'users.email', 'users.preferred_language']);

        if (! empty($excludeUserIds)) {
            $query->whereNotIn('users.id', $excludeUserIds);
        }

        $batchInsertSize = config('newsletter.batch_insert_size', 500);
        $recipients = [];

        // For small limits, avoid chunk() which ignores LIMIT clauses
        if ($limit !== null && $limit <= $batchInsertSize) {
            $users = $query->limit($limit)->get();
            foreach ($users as $user) {
                $recipients[] = [
                    'newsletter_id' => $newsletter->id,
                    'user_id'       => $user->id,
                    'email'         => $user->email,
                    'locale'        => $user->preferred_language ?? 'en',
                    'status'        => NewsletterRecipient::STATUS_PENDING,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ];
            }
            if (! empty($recipients)) {
                NewsletterRecipient::insert($recipients);
            }
            $newsletter->update(['total_recipients' => count($recipients)]);

            return;
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $query->chunk($batchInsertSize, function ($users) use ($newsletter, &$recipients, $batchInsertSize) {
            foreach ($users as $user) {
                $recipients[] = [
                    'newsletter_id' => $newsletter->id,
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'locale' => $user->preferred_language ?? 'en',
                    'status' => NewsletterRecipient::STATUS_PENDING,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                // Insert in batches
                if (count($recipients) >= $batchInsertSize) {
                    NewsletterRecipient::insert($recipients);
                    $recipients = [];
                }
            }
        });

        // Insert any remaining recipients
        if (! empty($recipients)) {
            NewsletterRecipient::insert($recipients);
        }

        // Update total recipients count
        $newsletter->update([
            'total_recipients' => $newsletter->recipients()->count(),
        ]);
    }

    /**
     * Dispatch the next batch of emails for processing.
     *
     * Checks if there are pending recipients and dispatches a batch job.
     * Marks the newsletter as sent when all recipients are processed.
     *
     * @param  Newsletter  $newsletter  The newsletter to process
     */
    public function dispatchNextBatch(Newsletter $newsletter): void
    {
        $newsletter->refresh();

        if ($newsletter->status !== Newsletter::STATUS_SENDING) {
            return;
        }

        $pendingCount = $newsletter->recipients()
            ->where('status', NewsletterRecipient::STATUS_PENDING)
            ->count();

        if ($pendingCount === 0) {
            $limitHit = $newsletter->send_limit !== null
                && $this->hasUnprocessedRecipients($newsletter);

            if ($limitHit) {
                $newsletter->update(['status' => Newsletter::STATUS_PAUSED]);

                Log::info('Newsletter paused after limit batch', [
                    'newsletter_id' => $newsletter->id,
                    'send_limit' => $newsletter->send_limit,
                    'segment_key' => $newsletter->segment_key,
                ]);

                return;
            }

            $newsletter->update([
                'status' => Newsletter::STATUS_SENT,
                'completed_at' => now(),
            ]);

            Log::info('Newsletter sending completed', [
                'newsletter_id' => $newsletter->id,
                'total_sent' => $newsletter->total_sent,
                'total_failed' => $newsletter->total_failed,
            ]);

            return;
        }

        // Dispatch the next batch
        $batchSize = $newsletter->batch_size ?? config('newsletter.batch_size', 100);

        SendNewsletterBatch::dispatch($newsletter, $batchSize)
            ->delay(now()->addSeconds(config('newsletter.batch_delay_seconds', 10)));

        $newsletter->increment('current_batch');
    }

    /**
     * Process a single recipient by sending them the newsletter email.
     *
     * Validates the user can still receive newsletters, sends the email,
     * and updates recipient status accordingly.
     *
     * @param  NewsletterRecipient  $recipient  The recipient to process
     */
    public function processRecipient(NewsletterRecipient $recipient): void
    {
        $newsletter = $recipient->newsletter;
        $user = $recipient->user;

        // Double-check user can still receive newsletters
        if (! $user || ! $user->canReceiveNewsletter()) {
            $recipient->markAsSkipped('User cannot receive newsletters');

            return;
        }

        try {
            $messageId = $this->sesService->sendNewsletter(
                newsletter: $newsletter,
                email: $recipient->email,
                locale: $recipient->locale,
                recipientId: $recipient->id,
                isTest: false,
                unsubscribeToken: $user->newsletter_unsubscribe_token,
                personalization: ['name' => $user->name, 'email' => $user->email]
            );

            $recipient->markAsSent($messageId);
            $newsletter->increment('total_sent');

        } catch (\Throwable $e) {
            Log::error('Failed to send newsletter email', [
                'newsletter_id' => $newsletter->id,
                'recipient_id' => $recipient->id,
                'error' => $e->getMessage(),
            ]);

            // markAsFailed handles attempt increment, retry logic, and status
            $recipient->markAsFailed($e->getMessage());

            if ($recipient->isFailed()) {
                $newsletter->increment('total_failed');
            }
        }
    }

    /**
     * Returns true als er nog eligible users in het segment zitten die
     * nog niet als recipient van deze newsletter zijn verwerkt.
     */
    public function hasUnprocessedRecipients(Newsletter $newsletter): bool
    {
        $segmentKey = $newsletter->segment_key ?? NewsletterSegmentService::SEGMENT_ALL;

        return $this->segmentService->query($segmentKey)
            ->whereNotIn('users.id', function ($sub) use ($newsletter) {
                $sub->select('user_id')
                    ->from('newsletter_recipients')
                    ->where('newsletter_id', $newsletter->id);
            })
            ->exists();
    }

    /**
     * Count nog niet verwerkte users in het segment (voor UI).
     */
    public function unprocessedRecipientsCount(Newsletter $newsletter): int
    {
        $segmentKey = $newsletter->segment_key ?? NewsletterSegmentService::SEGMENT_ALL;

        return $this->segmentService->query($segmentKey)
            ->whereNotIn('users.id', function ($sub) use ($newsletter) {
                $sub->select('user_id')
                    ->from('newsletter_recipients')
                    ->where('newsletter_id', $newsletter->id);
            })
            ->count();
    }

    /**
     * Hervat een gepauzeerde newsletter na limit-batch: verzamel nieuwe
     * recipients in hetzelfde segment (zonder limit, exclusief al verwerkten)
     * en start de batch-pipeline opnieuw.
     */
    public function continueSending(Newsletter $newsletter): void
    {
        if ($newsletter->status !== Newsletter::STATUS_PAUSED) {
            throw new \RuntimeException('Newsletter is niet gepauzeerd.');
        }

        $processedUserIds = $newsletter->recipients()->pluck('user_id')->all();

        $newsletter->update([
            'send_limit' => null,
            'status' => Newsletter::STATUS_SENDING,
        ]);

        $this->collectRecipients($newsletter, null, $newsletter->segment_key, $processedUserIds);

        $newsletter->update([
            'total_recipients' => $newsletter->recipients()->count(),
        ]);

        $this->dispatchNextBatch($newsletter);

        Log::info('Newsletter continue sending', [
            'newsletter_id' => $newsletter->id,
            'total_recipients' => $newsletter->fresh()->total_recipients,
            'segment_key' => $newsletter->segment_key,
        ]);
    }

    /**
     * Unsubscribe a user from the newsletter using their unique token.
     *
     * Rate limited to prevent brute force token guessing attacks.
     * Allows 10 attempts per minute per IP address.
     *
     * @param  string  $token  The user's unsubscribe token
     * @param  string|null  $ipAddress  Optional IP address for rate limiting
     * @return User|null The unsubscribed user, or null if token is invalid or rate limited
     *
     * @throws \Illuminate\Http\Exceptions\ThrottleRequestsException If rate limit exceeded
     */
    public function unsubscribeByToken(string $token, ?string $ipAddress = null): ?User
    {
        // Rate limit by IP to prevent brute force token guessing
        $key = 'newsletter-unsubscribe:' . ($ipAddress ?? request()->ip());
        $maxAttempts = config('newsletter.unsubscribe_rate_limit', 10);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            Log::warning('Newsletter unsubscribe rate limit exceeded', [
                'ip' => $ipAddress ?? request()->ip(),
                'retry_after' => $seconds,
            ]);

            throw new \Illuminate\Http\Exceptions\ThrottleRequestsException(
                "Too many attempts. Please try again in {$seconds} seconds."
            );
        }

        RateLimiter::hit($key, 60); // Decay after 60 seconds

        $user = User::where('newsletter_unsubscribe_token', $token)->first();

        if (! $user) {
            return null;
        }

        // Clear rate limit on successful unsubscribe
        RateLimiter::clear($key);

        $user->update([
            'newsletter_subscribed' => false,
            'newsletter_unsubscribed_at' => now(),
        ]);

        Log::info('User unsubscribed from newsletter via token', [
            'user_id' => $user->id,
        ]);

        return $user;
    }

    /**
     * Subscribe a user to the newsletter.
     *
     * @param  User  $user  The user to subscribe
     * @return void
     */
    public function subscribe(User $user): void
    {
        $user->update([
            'newsletter_subscribed' => true,
            'newsletter_unsubscribed_at' => null,
        ]);

        Log::info('User subscribed to newsletter', [
            'user_id' => $user->id,
        ]);
    }

    /**
     * Unsubscribe a user from the newsletter.
     *
     * @param  User  $user  The user to unsubscribe
     * @return void
     */
    public function unsubscribe(User $user): void
    {
        $user->update([
            'newsletter_subscribed' => false,
            'newsletter_unsubscribed_at' => now(),
        ]);

        Log::info('User unsubscribed from newsletter', [
            'user_id' => $user->id,
        ]);
    }
}
