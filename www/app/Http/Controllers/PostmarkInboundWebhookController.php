<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessInboundEmailJob;
use App\Models\InboundEmail;
use App\Models\UserInboundEmailPreference;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class PostmarkInboundWebhookController extends Controller
{
    /**
     * Handle inbound email webhook from Postmark
     */
    public function handle(Request $request)
    {
        // Rate limiting: 30 requests per IP per minute
        $rateLimitKey = 'postmark-inbound:'.$request->ip();
        if (RateLimiter::tooManyAttempts($rateLimitKey, 30)) {
            Log::warning('Postmark inbound rate limit exceeded', [
                'ip' => $request->ip(),
            ]);

            return response('Too Many Requests', 429);
        }
        RateLimiter::hit($rateLimitKey, 60);

        // Validate webhook signature
        if (! $this->validateSignature($request)) {
            Log::warning('Invalid Postmark inbound webhook signature', [
                'ip' => $request->ip(),
            ]);

            return response('Unauthorized', 401);
        }

        $payload = $request->all();

        // Validate required fields
        if (! isset($payload['To']) || ! isset($payload['From']) || ! isset($payload['MessageID'])) {
            Log::error('Invalid Postmark inbound payload: missing required fields', [
                'payload_keys' => array_keys($payload),
            ]);

            return response('Bad Request', 400);
        }

        // Extract recipient email (To field)
        $toEmail = $payload['To'] ?? '';
        $fromEmail = $payload['From'] ?? '';
        $messageId = $payload['MessageID'] ?? '';

        // Check if this message was already processed
        if (InboundEmail::where('message_id', $messageId)->exists()) {
            Log::info('Duplicate inbound email detected, skipping', [
                'message_id' => $messageId,
            ]);

            return response('OK', 200);
        }

        // Extract token and action from recipient email
        $token = InboundEmail::extractTokenFromEmail($toEmail);
        $actionType = InboundEmail::extractActionFromEmail($toEmail);

        if (! $token) {
            Log::warning('Invalid inbound email format: cannot extract token', [
                'to_email' => $toEmail,
            ]);

            $this->createRejectedEmail($payload, 'invalid_token', null, $actionType);

            return response('OK', 200);
        }

        // Find user preference by token
        $preference = UserInboundEmailPreference::findByToken($token);

        if (! $preference) {
            Log::warning('Inbound email for unknown token', [
                'token' => $token,
                'to_email' => $toEmail,
            ]);

            $this->createRejectedEmail($payload, 'invalid_token', null, $actionType);

            return response('OK', 200);
        }

        // Check if inbound is enabled
        if (! $preference->inbound_enabled) {
            Log::info('Inbound email rejected: user has disabled inbound', [
                'user_id' => $preference->user_id,
                'to_email' => $toEmail,
            ]);

            $this->createRejectedEmail($payload, 'inbound_disabled', $preference->user_id, $actionType);

            return response('OK', 200);
        }

        // Verify sender if enabled
        if ($preference->verify_sender) {
            $user = $preference->user;
            $fromEmailAddress = $this->extractEmailAddress($fromEmail);

            if (strtolower($fromEmailAddress) !== strtolower($user->email)) {
                Log::warning('Inbound email rejected: sender not trusted', [
                    'user_id' => $user->id,
                    'from_email' => $fromEmailAddress,
                    'expected_email' => $user->email,
                ]);

                $this->createRejectedEmail($payload, 'sender_not_trusted', $preference->user_id, $actionType);

                return response('OK', 200);
            }
        }

        // Check feature enabled globally
        if (! config('inbound.enabled', true)) {
            Log::warning('Inbound email rejected: feature disabled globally');

            $this->createRejectedEmail($payload, 'feature_disabled', $preference->user_id, $actionType);

            return response('OK', 200);
        }

        // Determine the action type from token
        $actionType = $preference->getActionForToken($token);

        // Create inbound email record
        try {
            $inboundEmail = $this->createInboundEmail($payload, $preference->user_id, $actionType);

            // Dispatch job to process email asynchronously (with payload for attachments)
            ProcessInboundEmailJob::dispatch($inboundEmail, $payload);

            Log::info('Inbound email queued for processing', [
                'inbound_email_id' => $inboundEmail->id,
                'user_id' => $preference->user_id,
                'action_type' => $actionType,
            ]);

            return response('OK', 200);
        } catch (\Exception $e) {
            Log::error('Failed to create inbound email', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);

            return response('Internal Server Error', 500);
        }
    }

    /**
     * Validate webhook signature
     */
    private function validateSignature(Request $request): bool
    {
        $webhookSecret = config('inbound.webhook_token');

        // If no secret configured in production, reject the webhook (fail-closed)
        if (! $webhookSecret) {
            if (app()->environment('production')) {
                Log::warning('Postmark inbound webhook rejected: no token configured in production');

                return false;
            }

            Log::info('Postmark inbound webhook token not configured, skipping validation in non-production');

            return true;
        }

        // Allow bypassing signature validation (useful before Postmark is fully configured)
        if ($webhookSecret === 'skip') {
            return true;
        }

        $signature = $request->header('X-Postmark-Signature');
        if (! $signature) {
            return false;
        }

        $payload = $request->getContent();
        $expectedSignature = base64_encode(hash_hmac('sha256', $payload, $webhookSecret, true));

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Create inbound email record
     */
    private function createInboundEmail(array $payload, int $userId, ?string $actionType): InboundEmail
    {
        // Extract headers and count hops
        $headers = [];
        $hopCount = 0;

        if (isset($payload['Headers'])) {
            foreach ($payload['Headers'] as $header) {
                $headers[$header['Name']] = $header['Value'];

                // Count 'Received' headers for hop detection (each mail server adds one)
                if (strcasecmp($header['Name'], 'Received') === 0) {
                    $hopCount++;
                }
            }
        }

        // Check for email loops
        if ($hopCount > config('inbound.limits.max_email_hops', 50)) {
            throw new \Exception('Email loop detected: too many hops');
        }

        // Extract nested email count (email-in-email)
        $nestedEmailCount = $this->detectNestedEmails($payload);

        return InboundEmail::create([
            'message_id' => $payload['MessageID'],
            'from_email' => $payload['From'] ?? '',
            'from_name' => $payload['FromName'] ?? null,
            'to_email' => $payload['To'] ?? '',
            'action_type' => $actionType,
            'subject' => $payload['Subject'] ?? null,
            'body_text' => $payload['TextBody'] ?? null,
            'body_html' => $payload['HtmlBody'] ?? null,
            'headers' => json_encode($headers),
            'user_id' => $userId,
            'status' => InboundEmail::STATUS_RECEIVED,
            'spam_score' => isset($payload['SpamScore']) ? min(1.0, max(0.0, $payload['SpamScore'])) : null,
            'nested_email_count' => $nestedEmailCount,
        ]);
    }

    /**
     * Create rejected email record
     */
    private function createRejectedEmail(array $payload, string $reason, ?int $userId, ?string $actionType): void
    {
        try {
            InboundEmail::create([
                'message_id' => $payload['MessageID'] ?? uniqid('rejected-'),
                'from_email' => $payload['From'] ?? '',
                'from_name' => $payload['FromName'] ?? null,
                'to_email' => $payload['To'] ?? '',
                'action_type' => $actionType,
                'subject' => $payload['Subject'] ?? null,
                'user_id' => $userId,
                'status' => InboundEmail::STATUS_BOUNCED,
                'processing_notes' => "Rejected: {$reason}",
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create rejected email record', [
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract email address from "Name <email@domain.com>" format
     */
    private function extractEmailAddress(string $emailString): string
    {
        if (preg_match('/<(.+?)>/', $emailString, $matches)) {
            return $matches[1];
        }

        return $emailString;
    }

    /**
     * Detect nested emails (email-in-email) up to 1 level deep
     */
    private function detectNestedEmails(array $payload): int
    {
        $count = 0;
        $maxDepth = config('inbound.limits.max_nested_email_depth', 1);

        // Check attachments for .eml or message/rfc822 MIME type
        if (isset($payload['Attachments'])) {
            foreach ($payload['Attachments'] as $attachment) {
                $contentType = $attachment['ContentType'] ?? '';
                $name = $attachment['Name'] ?? '';

                if (
                    $contentType === 'message/rfc822' ||
                    str_ends_with(strtolower($name), '.eml')
                ) {
                    $count++;
                    if ($count >= $maxDepth) {
                        break;
                    }
                }
            }
        }

        return min($count, $maxDepth);
    }
}
