<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\OrganizationSenderLog;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\DevMailboxService;
use App\Services\SenderConfigService;
use GuzzleHttp\Client;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendPostmarkTemplateEmail implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 2;

    public function __construct(
        private string $templateAlias,
        private array $templateModel,
        private string $to,
        private string $toName = '',
        private ?string $tag = null,
        private ?string $messageStream = null,
        private ?array $attachments = null,
        private ?int $organizationId = null,
        private ?string $bcc = null
    ) {
        $this->onQueue('default');
    }

    /**
     * Execute the job and return the Postmark MessageID on success.
     *
     * @return string|null The Postmark MessageID, or null if skipped/dev mode
     */
    public function handle(): ?string
    {
        // Check if recipient has a bounced email address
        $user = User::where('email', $this->to)->first();
        if ($user && $user->email_bounced_at) {
            Log::info('Skipping email to bounced address', [
                'email' => $this->to,
                'bounced_at' => $user->email_bounced_at,
                'bounce_type' => $user->email_bounce_type,
                'template' => $this->templateAlias,
            ]);

            AnalyticsService::log('email_skipped_bounced', [
                'type' => $this->templateAlias,
                'recipient' => $this->to,
                'bounce_type' => $user->email_bounce_type,
            ]);

            return null;
        }

        Log::info('SendPostmarkTemplateEmail job started', [
            'templateAlias' => $this->templateAlias,
            'to' => $this->to,
            'mock_enabled' => DevMailboxService::isEnabled(),
        ]);

        $sender = $this->resolveSenderDetails();

        // Check if dev mailbox is enabled (development mode with mocking)
        if (DevMailboxService::isEnabled()) {
            $this->handleDevMailbox($sender);

            return null;
        }

        // Production/real email flow
        $client = new Client;

        $from = $sender['from_name']
            ? "{$sender['from_name']} <{$sender['from']}>"
            : $sender['from'];

        $payload = [
            'From' => $from,
            'To' => $this->to,
            'TemplateAlias' => $this->templateAlias,
            'TemplateModel' => $this->templateModel,
        ];

        if ($sender['reply_to']) {
            $payload['ReplyTo'] = $sender['reply_to'];
        }

        if ($this->tag) {
            $payload['Tag'] = $this->tag;
        }

        if ($this->messageStream) {
            $payload['MessageStream'] = $this->messageStream;
        }

        if (! empty($this->attachments)) {
            $payload['Attachments'] = $this->attachments;
        }

        if ($this->bcc) {
            $payload['Bcc'] = $this->bcc;
        }

        try {
            Log::info('Sending to Postmark API', [
                'payload' => $payload,
                'url' => 'https://api.postmarkapp.com/email/withTemplate',
            ]);

            $response = $client->post('https://api.postmarkapp.com/email/withTemplate', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-Postmark-Server-Token' => config('services.postmark.token'),
                ],
                'json' => $payload,
            ]);

            $result = json_decode($response->getBody(), true);
            $messageId = $result['MessageID'] ?? null;

            Log::info('Postmark API Success', [
                'messageId' => $messageId,
                'to' => $this->to,
                'template' => $this->templateAlias,
                'result' => $result,
            ]);

            // Track email sending in analytics
            AnalyticsService::log('email_sent', [
                'type' => $this->templateAlias,
                'recipient' => $this->to,
                'postmark_message_id' => $messageId,
                'tag' => $this->tag,
                'message_stream' => $this->messageStream,
            ]);

            // Log to organization sender logs
            if ($this->organizationId) {
                OrganizationSenderLog::logSent($this->organizationId, $this->to, $this->templateAlias, $this->tag, $messageId);
            }

            // Track share email specifically
            if ($this->tag === 'share-file') {
                AnalyticsService::log('share_email_sent', [
                    'postmark_message_id' => $messageId,
                    'template_alias' => $this->templateAlias,
                    'locale' => str_contains($this->templateAlias, '__nl') ? 'nl' : 'en',
                ]);
            }

            return $messageId;

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            $postmarkError = json_decode($responseBody, true);
            $postmarkErrorCode = $postmarkError['ErrorCode'] ?? null;

            Log::error('Postmark API Client Error', [
                'error' => $e->getMessage(),
                'response' => $responseBody,
                'statusCode' => $e->getCode(),
                'postmark_error_code' => $postmarkErrorCode,
                'payload' => $payload,
                'to' => $this->to,
                'template' => $this->templateAlias,
            ]);

            // Handle permanent failures gracefully (no retries needed)
            // ErrorCode 406: Inactive recipient (email address is inactive in Postmark)
            // ErrorCode 300: Invalid email address
            // Log failure to organization sender logs
            if ($this->organizationId) {
                OrganizationSenderLog::logFailed($this->organizationId, $this->to, $e->getMessage(), (string) $postmarkErrorCode, $this->templateAlias, $this->tag);
            }

            if ($postmarkErrorCode === 406) {
                // Mark user as bounced so we don't keep trying to send to this address
                if ($user) {
                    $user->update([
                        'email_bounced_at' => now(),
                        'email_bounce_type' => 'inactive',
                    ]);

                    Log::info('User marked as bounced due to inactive email', [
                        'user_id' => $user->id,
                        'email' => $this->to,
                    ]);
                }

                AnalyticsService::log('email_skipped_inactive', [
                    'type' => $this->templateAlias,
                    'recipient' => $this->to,
                    'postmark_error_code' => $postmarkErrorCode,
                ]);

                return null; // Graceful exit, no retry
            }

            if ($postmarkErrorCode === 300) {
                Log::warning('Invalid email address, skipping without retry', [
                    'email' => $this->to,
                    'template' => $this->templateAlias,
                ]);

                AnalyticsService::log('email_skipped_invalid', [
                    'type' => $this->templateAlias,
                    'recipient' => $this->to,
                    'postmark_error_code' => $postmarkErrorCode,
                ]);

                return null; // Graceful exit, no retry
            }

            // Track email failure in analytics (for errors that will retry)
            AnalyticsService::log('email_failed', [
                'type' => $this->templateAlias,
                'recipient' => $this->to,
                'error' => $e->getMessage(),
                'status_code' => $e->getCode(),
                'postmark_error_code' => $postmarkErrorCode,
                'tag' => $this->tag,
                'message_stream' => $this->messageStream,
            ]);

            throw $e;
        } catch (\Exception $e) {
            Log::error('Postmark API Error', [
                'error' => $e->getMessage(),
                'payload' => $payload,
                'to' => $this->to,
                'template' => $this->templateAlias,
            ]);

            if ($this->organizationId) {
                OrganizationSenderLog::logFailed($this->organizationId, $this->to, $e->getMessage(), null, $this->templateAlias, $this->tag);
            }

            // Track email failure in analytics
            AnalyticsService::log('email_failed', [
                'type' => $this->templateAlias,
                'recipient' => $this->to,
                'error' => $e->getMessage(),
                'tag' => $this->tag,
                'message_stream' => $this->messageStream,
            ]);

            throw $e;
        }
    }

    /**
     * Handle email in development mode (store in dev mailbox)
     */
    private function handleDevMailbox(array $sender): void
    {
        $mailbox = app(DevMailboxService::class);

        // Use subject from template_model if available, otherwise determine from template alias
        $subject = $this->templateModel['subject'] ?? $this->getSubjectFromTemplate($this->templateAlias);

        // Extract verification URL or other action URLs from template model
        $verificationUrl = $this->extractActionUrl();

        $emailData = [
            'template_alias' => $this->templateAlias,
            'template_model' => $this->templateModel,
            'tag' => $this->tag,
            'message_stream' => $this->messageStream,
            'from' => $sender['from'],
            'from_name' => $sender['from_name'],
            'reply_to' => $sender['reply_to'],
            'to_name' => $this->toName,
        ];

        // Add verification/action URL if present
        if ($verificationUrl) {
            $emailData['verification_url'] = $verificationUrl;
        }

        // Determine if this contains sensitive data (tokens, verification URLs)
        $sensitive = $verificationUrl !== null || str_contains($this->templateAlias, 'verification');

        // Store in dev mailbox
        $emailId = $mailbox->store(
            to: $this->to,
            subject: $subject,
            data: $emailData,
            sensitive: $sensitive
        );

        Log::info('Email stored in dev mailbox', [
            'email_id' => $emailId,
            'to' => $this->to,
            'subject' => $subject,
            'template' => $this->templateAlias,
            'sensitive' => $sensitive,
        ]);

        // Track in analytics (dev mode)
        AnalyticsService::log('email_mocked', [
            'type' => $this->templateAlias,
            'recipient' => $this->to,
            'email_id' => $emailId,
            'tag' => $this->tag,
            'message_stream' => $this->messageStream,
        ]);
    }

    /**
     * Resolve sender details (from, from_name, reply_to) based on organization config.
     */
    private function resolveSenderDetails(): array
    {
        $default = [
            'from' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
            'reply_to' => null,
        ];

        if (! $this->organizationId) {
            return $default;
        }

        $org = Organization::find($this->organizationId);
        if (! $org) {
            return $default;
        }

        return app(SenderConfigService::class)->resolveSender($org);
    }

    /**
     * Get human-readable subject from template alias
     */
    private function getSubjectFromTemplate(string $templateAlias): string
    {
        // Extract base name (remove locale suffix)
        $baseName = preg_replace('/__[a-z]{2}$/', '', $templateAlias);

        $subjectMap = [
            'email-change-verification' => 'Verify Your New Email Address',
            'email-change-notification' => 'Email Address Change Request',
            'password-reset' => 'Password Reset Request',
            'welcome' => 'Welcome to ' . config('app.name') . '!',
            'verify-email' => 'Please Verify Your Email',
        ];

        return $subjectMap[$baseName] ?? ucwords(str_replace('-', ' ', $baseName));
    }

    /**
     * Extract verification/action URL from template model
     */
    private function extractActionUrl(): ?string
    {
        // Check common URL field names
        $urlFields = [
            'verification_url',
            'reset_url',
            'action_url',
            'confirm_url',
            'cancel_url',
        ];

        foreach ($urlFields as $field) {
            if (isset($this->templateModel[$field])) {
                return $this->templateModel[$field];
            }
        }

        return null;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendPostmarkTemplateEmail job failed permanently', [
            'template_alias' => $this->templateAlias,
            'recipient' => $this->to,
            'tag' => $this->tag,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        AnalyticsService::log('email_delivery_failed', [
            'type' => $this->templateAlias,
            'recipient' => $this->to,
            'error' => $exception->getMessage(),
        ]);
    }
}
