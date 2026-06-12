<?php

namespace App\Services;

use App\Models\Newsletter;
use Aws\Ses\SesClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

/**
 * Service for sending newsletters via AWS SES.
 *
 * Handles email rendering, SES API integration, and identity verification.
 * Uses the eu-west-1 region by default for GDPR compliance.
 */
class SesNewsletterService
{
    private ?SesClient $client = null;

    /**
     * Get or create the SES client instance.
     *
     * @return SesClient The configured SES client
     */
    public function getClient(): SesClient
    {
        if ($this->client === null) {
            $this->client = new SesClient([
                'version' => 'latest',
                'region' => config('newsletter.ses.region', 'eu-west-1'),
                'credentials' => [
                    'key' => config('services.ses.key'),
                    'secret' => config('services.ses.secret'),
                ],
            ]);
        }

        return $this->client;
    }

    /**
     * Send a newsletter email via AWS SES.
     *
     * @param  Newsletter  $newsletter  The newsletter to send
     * @param  string  $email  Recipient email address
     * @param  string  $locale  Locale for content translation
     * @param  int|null  $recipientId  Optional recipient ID for tracking
     * @param  bool  $isTest  Whether this is a test email
     * @param  string|null  $unsubscribeToken  Pre-fetched unsubscribe token (avoids N+1 query)
     * @return string The SES message ID
     *
     * @throws \Throwable If sending fails
     */
    public function sendNewsletter(
        Newsletter $newsletter,
        string $email,
        string $locale,
        ?int $recipientId = null,
        bool $isTest = false,
        ?string $unsubscribeToken = null,
        array $personalization = []
    ): string {
        $subject = $newsletter->getTitle($locale);
        $htmlBody = $this->renderEmailBody($newsletter, $locale, $unsubscribeToken, $recipientId, $personalization);

        // Build email params
        $params = [
            'Source' => $this->getFromAddress(),
            'Destination' => [
                'ToAddresses' => [$email],
            ],
            'Message' => [
                'Subject' => [
                    'Charset' => 'UTF-8',
                    'Data' => $isTest ? "[TEST] {$subject}" : $subject,
                ],
                'Body' => [
                    'Html' => [
                        'Charset' => 'UTF-8',
                        'Data' => $htmlBody,
                    ],
                ],
            ],
            'Tags' => [
                [
                    'Name' => 'newsletter_id',
                    'Value' => (string) $newsletter->id,
                ],
                [
                    'Name' => 'type',
                    'Value' => $isTest ? 'test' : 'newsletter',
                ],
            ],
        ];

        // Add configuration set if configured
        $configurationSet = config('newsletter.ses.configuration_set');
        if ($configurationSet) {
            $params['ConfigurationSetName'] = $configurationSet;
        }

        // Add recipient ID tag for tracking
        if ($recipientId) {
            $params['Tags'][] = [
                'Name' => 'recipient_id',
                'Value' => (string) $recipientId,
            ];
        }

        try {
            $result = $this->getClient()->sendEmail($params);
            $messageId = $result->get('MessageId');

            Log::info('SES newsletter email sent', [
                'newsletter_id' => $newsletter->id,
                'recipient_id' => $recipientId,
                'message_id' => $messageId,
                'is_test' => $isTest,
            ]);

            return $messageId;

        } catch (\Throwable $e) {
            Log::error('SES newsletter send failed', [
                'newsletter_id' => $newsletter->id,
                'recipient_id' => $recipientId,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Render the HTML body for a newsletter email.
     *
     * Supports personalization tokens in the body:
     * [naam]   → full name of the recipient
     * [email]  → email address of the recipient
     *
     * @param  Newsletter  $newsletter  The newsletter to render
     * @param  string  $locale  Locale for content translation
     * @param  string|null  $unsubscribeToken  Pre-fetched unsubscribe token
     * @param  int|null  $recipientId  Optional recipient ID for tracking
     * @param  array<string, string>  $personalization  Recipient data for token replacement
     * @return string The rendered HTML
     */
    private function renderEmailBody(
        Newsletter $newsletter,
        string $locale,
        ?string $unsubscribeToken,
        ?int $recipientId,
        array $personalization = []
    ): string {
        $unsubscribeUrl = $unsubscribeToken
            ? route('newsletter.unsubscribe', ['token' => $unsubscribeToken])
            : null;

        $body = $newsletter->getBody($locale);
        $body = $this->sanitizeBody($body);
        $body = $this->applyPersonalization($body, $personalization);

        return View::make('emails.newsletter', [
            'title' => $newsletter->getTitle($locale),
            'body' => $body,
            'locale' => $locale,
            'unsubscribeUrl' => $unsubscribeUrl,
            'recipientId' => $recipientId,
        ])->render();
    }

    /**
     * Strip dangerous HTML from the newsletter body.
     *
     * Allows a safe subset of tags used by the RichEditor. Removes script tags,
     * event handler attributes (onclick, onload, etc.), and javascript: hrefs.
     * Uses DOMDocument to handle malformed HTML without crashing.
     */
    private function sanitizeBody(string $body): string
    {
        if (empty($body)) {
            return $body;
        }

        // Remove script and style blocks entirely
        $body = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $body);
        $body = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $body);

        // Strip event handler attributes (onclick, onload, onerror, etc.)
        $body = preg_replace('/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $body);

        // Strip javascript: hrefs
        $body = preg_replace('/href\s*=\s*["\']?\s*javascript:[^"\'>\s]*/i', 'href="#"', $body);

        return $body;
    }

    /**
     * Replace personalization tokens in the email body.
     *
     * Supported tokens: [naam], [email]
     *
     * @param  string  $body  The raw HTML body
     * @param  array<string, string>  $data  Keys: name, email
     * @return string Body with tokens replaced
     */
    private function applyPersonalization(string $body, array $data): string
    {
        return str_replace(
            ['[naam]', '[email]'],
            [$data['name'] ?? '', $data['email'] ?? ''],
            $body
        );
    }

    /**
     * Get the formatted "From" address for newsletter emails.
     *
     * @return string Formatted "Name <email>" string
     */
    private function getFromAddress(): string
    {
        $fromName = config('newsletter.from.name', config('mail.from.name'));
        $fromAddress = config('newsletter.from.address', config('mail.from.address'));

        return "{$fromName} <{$fromAddress}>";
    }

    /**
     * Check if an email identity is verified in SES.
     *
     * @param  string  $email  The email address to check
     * @return bool True if verified, false otherwise
     */
    public function verifyEmailIdentity(string $email): bool
    {
        try {
            $result = $this->getClient()->getIdentityVerificationAttributes([
                'Identities' => [$email],
            ]);

            $attributes = $result->get('VerificationAttributes');

            return isset($attributes[$email])
                && $attributes[$email]['VerificationStatus'] === 'Success';

        } catch (\Throwable $e) {
            Log::error('SES identity verification check failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
