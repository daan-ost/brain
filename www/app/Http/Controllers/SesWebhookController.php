<?php

namespace App\Http\Controllers;

use App\Models\NewsletterRecipient;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Handles AWS SES webhook notifications via SNS.
 *
 * Processes email events (delivery, bounce, complaint, open, click)
 * and updates newsletter recipient tracking accordingly.
 */
class SesWebhookController extends Controller
{
    /**
     * Allowed AWS SNS domains for subscription confirmation.
     */
    private const ALLOWED_SNS_HOSTS = [
        'sns.us-east-1.amazonaws.com',
        'sns.us-west-2.amazonaws.com',
        'sns.eu-west-1.amazonaws.com',
        'sns.eu-central-1.amazonaws.com',
        'sns.ap-southeast-1.amazonaws.com',
        'sns.ap-northeast-1.amazonaws.com',
    ];

    /**
     * Handle incoming SNS notification from AWS SES.
     */
    public function handle(Request $request): Response
    {
        $payload = $request->all();

        // Handle SNS subscription confirmation
        if ($this->isSubscriptionConfirmation($request)) {
            return $this->handleSubscriptionConfirmation($payload);
        }

        // Validate SNS message
        if (! $this->validateSnsMessage($request)) {
            Log::warning('Invalid SES webhook signature', [
                'ip' => $request->ip(),
            ]);

            return response('Unauthorized', 403);
        }

        // Parse the SNS notification
        $message = $this->parseMessage($payload);
        if (! $message) {
            return response('OK', 200);
        }

        $eventType = $message['eventType'] ?? $message['notificationType'] ?? null;

        Log::info('SES webhook received', [
            'event_type' => $eventType,
            'message_id' => $message['mail']['messageId'] ?? null,
        ]);

        match ($eventType) {
            'Delivery' => $this->handleDelivery($message),
            'Bounce' => $this->handleBounce($message),
            'Complaint' => $this->handleComplaint($message),
            'Open' => $this->handleOpen($message),
            'Click' => $this->handleClick($message),
            default => Log::info('Unhandled SES event type', ['type' => $eventType]),
        };

        return response('OK', 200);
    }

    /**
     * Check if the request is an SNS subscription confirmation.
     */
    private function isSubscriptionConfirmation(Request $request): bool
    {
        $type = $request->header('X-Amz-Sns-Message-Type');

        return $type === 'SubscriptionConfirmation';
    }

    /**
     * Handle SNS subscription confirmation by verifying and visiting the subscribe URL.
     *
     * Security: Only allows URLs from whitelisted AWS SNS domains to prevent SSRF.
     */
    private function handleSubscriptionConfirmation(array $payload): Response
    {
        $subscribeUrl = $payload['SubscribeURL'] ?? null;

        if ($subscribeUrl && $this->isValidSnsUrl($subscribeUrl)) {
            try {
                // Use HTTP client with timeout to prevent hanging
                Http::timeout(10)->get($subscribeUrl);

                Log::info('SNS subscription confirmed', [
                    'topic_arn' => $payload['TopicArn'] ?? null,
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to confirm SNS subscription', [
                    'error' => $e->getMessage(),
                    'topic_arn' => $payload['TopicArn'] ?? null,
                ]);
            }
        } else {
            Log::warning('Invalid SNS subscription URL rejected', [
                'url' => $subscribeUrl,
            ]);
        }

        return response('OK', 200);
    }

    /**
     * Validate that a SubscribeURL is from an allowed AWS SNS domain.
     * Prevents SSRF attacks during subscription confirmation.
     */
    private function isValidSnsUrl(string $url): bool
    {
        $parsed = parse_url($url);

        return isset($parsed['scheme'], $parsed['host'])
            && $parsed['scheme'] === 'https'
            && in_array($parsed['host'], self::ALLOWED_SNS_HOSTS, true);
    }

    /**
     * Allowed AWS SNS domains for certificate downloads.
     */
    private const ALLOWED_CERT_HOSTS = [
        'sns.us-east-1.amazonaws.com',
        'sns.us-west-2.amazonaws.com',
        'sns.eu-west-1.amazonaws.com',
        'sns.eu-central-1.amazonaws.com',
        'sns.ap-southeast-1.amazonaws.com',
        'sns.ap-northeast-1.amazonaws.com',
    ];

    /**
     * Validate the SNS message using RSA-SHA1 signature verification.
     *
     * Downloads and caches the signing certificate, builds the canonical string,
     * and verifies the payload signature. This prevents spoofed SNS notifications.
     */
    private function validateSnsMessage(Request $request): bool
    {
        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return false;
        }

        $messageType = $payload['Type'] ?? null;
        if (! in_array($messageType, ['Notification', 'SubscriptionConfirmation', 'UnsubscribeConfirmation'], true)) {
            return false;
        }

        $certUrl = $payload['SigningCertURL'] ?? null;
        if (! $certUrl || ! $this->isValidCertUrl($certUrl)) {
            Log::warning('SNS webhook: invalid or missing SigningCertURL', ['url' => $certUrl]);

            return false;
        }

        $cert = $this->fetchCertificate($certUrl);
        if (! $cert) {
            return false;
        }

        $canonical = $this->buildCanonicalString($messageType, $payload);
        $signature = base64_decode($payload['Signature'] ?? '', true);

        if (! $signature || ! $canonical) {
            return false;
        }

        $publicKey = openssl_get_publickey($cert);
        if (! $publicKey) {
            Log::warning('SNS webhook: could not extract public key from certificate');

            return false;
        }

        return openssl_verify($canonical, $signature, $publicKey, OPENSSL_ALGO_SHA1) === 1;
    }

    /**
     * Build the canonical string for SNS signature verification.
     * Field order and inclusion are defined by AWS per message type.
     */
    private function buildCanonicalString(string $messageType, array $payload): string
    {
        $fields = $messageType === 'Notification'
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];

        $canonical = '';
        foreach ($fields as $field) {
            if (isset($payload[$field])) {
                $canonical .= $field . "\n" . $payload[$field] . "\n";
            }
        }

        return $canonical;
    }

    /**
     * Fetch and cache the SNS signing certificate.
     */
    private function fetchCertificate(string $certUrl): ?string
    {
        $cacheKey = 'sns_signing_cert_' . md5($certUrl);

        return cache()->remember($cacheKey, 3600, function () use ($certUrl) {
            try {
                $response = Http::timeout(5)->get($certUrl);

                return $response->successful() ? $response->body() : null;
            } catch (\Throwable $e) {
                Log::error('SNS webhook: failed to fetch signing certificate', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /**
     * Validate that a SigningCertURL is from an allowed AWS SNS domain.
     */
    private function isValidCertUrl(string $url): bool
    {
        $parsed = parse_url($url);

        return isset($parsed['scheme'], $parsed['host'])
            && $parsed['scheme'] === 'https'
            && in_array($parsed['host'], self::ALLOWED_CERT_HOSTS, true)
            && str_ends_with($parsed['path'] ?? '', '.pem');
    }

    /**
     * Parse the SNS message payload.
     *
     * @return array<string, mixed>|null
     */
    private function parseMessage(array $payload): ?array
    {
        // SNS wraps the message in a 'Message' field as a JSON string
        $messageJson = $payload['Message'] ?? null;

        if (! $messageJson) {
            return null;
        }

        if (is_string($messageJson)) {
            $decoded = json_decode($messageJson, true);

            return is_array($decoded) ? $decoded : null;
        }

        return is_array($messageJson) ? $messageJson : null;
    }

    /**
     * Get the newsletter recipient from the message tags or SES message ID.
     */
    private function getRecipientFromMessage(array $message): ?NewsletterRecipient
    {
        // Try to get recipient from tags first
        $tags = $message['mail']['tags'] ?? [];
        $recipientId = $tags['recipient_id'][0] ?? null;

        if ($recipientId && is_numeric($recipientId)) {
            return NewsletterRecipient::find((int) $recipientId);
        }

        // Fall back to SES message ID
        $sesMessageId = $message['mail']['messageId'] ?? null;

        if ($sesMessageId && is_string($sesMessageId)) {
            return NewsletterRecipient::where('ses_message_id', $sesMessageId)->first();
        }

        return null;
    }

    /**
     * Handle email delivery confirmation event.
     */
    private function handleDelivery(array $message): void
    {
        $recipient = $this->getRecipientFromMessage($message);

        if ($recipient) {
            Log::info('Newsletter delivered', [
                'recipient_id' => $recipient->id,
                'newsletter_id' => $recipient->newsletter_id,
            ]);
        }
    }

    /**
     * Handle email bounce event and mark users as bounced for hard bounces.
     */
    private function handleBounce(array $message): void
    {
        $bounceType = $message['bounce']['bounceType'] ?? null;
        $bouncedRecipients = $message['bounce']['bouncedRecipients'] ?? [];

        // Resolve once — the SNS message tags point to one newsletter recipient
        $recipient = $this->getRecipientFromMessage($message);
        if ($recipient) {
            $recipient->markAsBounced();
        }

        foreach ($bouncedRecipients as $bouncedRecipient) {
            $email = $bouncedRecipient['emailAddress'] ?? null;

            if (! $email || ! is_string($email)) {
                continue;
            }

            // Mark user as bounced for permanent (hard) bounces
            if ($bounceType === 'Permanent') {
                $user = User::where('email', $email)->first();
                if ($user) {
                    $user->update([
                        'email_bounced_at' => now(),
                        'email_bounce_type' => $bounceType,
                        'email_bounce_reason' => $bouncedRecipient['diagnosticCode'] ?? 'Hard bounce from SES',
                    ]);

                    Log::info('User marked as bounced from SES', [
                        'user_id' => $user->id,
                        'email' => $email,
                        'bounce_type' => $bounceType,
                    ]);
                }
            }
        }
    }

    /**
     * Handle spam complaint event and unsubscribe the user.
     */
    private function handleComplaint(array $message): void
    {
        $complainedRecipients = $message['complaint']['complainedRecipients'] ?? [];

        foreach ($complainedRecipients as $complained) {
            $email = $complained['emailAddress'] ?? null;

            if (! $email || ! is_string($email)) {
                continue;
            }

            // Unsubscribe user from newsletter
            $user = User::where('email', $email)->first();
            if ($user) {
                $user->update([
                    'newsletter_subscribed' => false,
                    'newsletter_unsubscribed_at' => now(),
                ]);

                Log::info('User unsubscribed due to complaint', [
                    'user_id' => $user->id,
                    'email' => $email,
                ]);
            }

            // Mark recipient as bounced
            $recipient = $this->getRecipientFromMessage($message);
            if ($recipient) {
                $recipient->markAsBounced();
            }
        }
    }

    /**
     * Handle email open tracking event.
     */
    private function handleOpen(array $message): void
    {
        $recipient = $this->getRecipientFromMessage($message);

        if ($recipient) {
            $recipient->markAsOpened();

            Log::info('Newsletter opened', [
                'recipient_id' => $recipient->id,
                'newsletter_id' => $recipient->newsletter_id,
            ]);
        }
    }

    /**
     * Handle link click tracking event.
     */
    private function handleClick(array $message): void
    {
        $recipient = $this->getRecipientFromMessage($message);
        $clickedUrl = $message['click']['link'] ?? null;

        if ($recipient && $clickedUrl && is_string($clickedUrl)) {
            $recipient->recordClick($clickedUrl);

            Log::info('Newsletter link clicked', [
                'recipient_id' => $recipient->id,
                'newsletter_id' => $recipient->newsletter_id,
                'url' => $clickedUrl,
            ]);
        }
    }
}
