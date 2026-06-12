<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PostmarkWebhookController extends Controller
{
    public function handle(Request $request)
    {
        if (! $this->validateSignature($request)) {
            Log::warning('Invalid Postmark webhook signature', [
                'ip' => $request->ip(),
                'headers' => $request->headers->all(),
            ]);

            return response('Unauthorized', 403);
        }

        $payload = $request->all();
        $recordType = $payload['RecordType'] ?? null;

        Log::info('Postmark webhook received', [
            'record_type' => $recordType,
            'message_id' => $payload['MessageID'] ?? null,
        ]);

        switch ($recordType) {
            case 'Delivery':
                $this->handleDelivery($payload);
                break;

            case 'Open':
                $this->handleOpen($payload);
                break;

            case 'Bounce':
                $this->handleBounce($payload);
                break;

            default:
                Log::info('Unhandled Postmark webhook type', ['type' => $recordType]);
        }

        return response('OK', 200);
    }

    private function validateSignature(Request $request): bool
    {
        $webhookSecret = config('services.postmark.webhook_secret');

        // If no secret configured in production, reject the webhook (fail-closed)
        if (! $webhookSecret) {
            if (app()->environment('production')) {
                Log::warning('Postmark webhook rejected: no secret configured in production');

                return false;
            }

            Log::info('Postmark webhook secret not configured, skipping validation in non-production');

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

    private function handleDelivery(array $payload): void
    {
        Log::info('Email delivered', [
            'message_id' => $payload['MessageID'],
            'recipient' => $payload['Recipient'] ?? null,
        ]);
    }

    private function handleOpen(array $payload): void
    {
        Log::info('Email opened', [
            'message_id' => $payload['MessageID'],
            'recipient' => $payload['Recipient'] ?? null,
        ]);
    }

    private function handleBounce(array $payload): void
    {
        $email = $payload['Email'] ?? null;
        $bounceType = $payload['Type'] ?? null;
        $description = $payload['Description'] ?? null;
        $messageId = $payload['MessageID'] ?? null;

        Log::info('Email bounced', [
            'email' => $email,
            'type' => $bounceType,
            'description' => $description,
            'message_id' => $messageId,
        ]);

        if (! $email) {
            Log::warning('Bounce webhook missing email address');

            return;
        }

        $user = User::where('email', $email)->first();
        if (! $user) {
            Log::warning('Bounce webhook for unknown user', ['email' => $email]);

            return;
        }

        if (in_array($bounceType, ['HardBounce', 'SpamNotification', 'ManuallyDeactivated'])) {
            $user->update([
                'email_bounced_at' => now(),
                'email_bounce_type' => $bounceType,
                'email_bounce_reason' => $description,
                'last_postmark_message_id' => $messageId,
            ]);

            Log::info('User marked as bounced', [
                'user_id' => $user->id,
                'email' => $email,
                'type' => $bounceType,
            ]);
        } else {
            Log::info('Soft bounce ignored', [
                'user_id' => $user->id,
                'email' => $email,
                'type' => $bounceType,
            ]);
        }
    }
}
