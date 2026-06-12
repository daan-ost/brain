<?php

namespace App\Http\Controllers;

use App\Services\MollieWebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MollieWebhookController extends Controller
{
    public function __construct(
        private MollieWebhookService $webhookService
    ) {}

    /**
     * Handle Mollie webhook notifications
     */
    public function handle(Request $request)
    {
        Log::info('Mollie webhook received', [
            'headers' => $request->headers->all(),
            'body' => $request->all(),
            'ip' => $request->ip(),
        ]);

        // Verify webhook signature for security
        if (! $this->verifyWebhookSignature($request)) {
            Log::warning('Mollie webhook signature verification failed', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json(['error' => 'Invalid signature'], 403);
        }

        try {
            // Get the payment or subscription ID from request
            $id = $request->input('id');

            if (! $id) {
                Log::warning('Mollie webhook missing ID parameter', [
                    'request_body' => $request->all(),
                ]);

                return response()->json(['error' => 'Missing ID parameter'], 400);
            }

            // Determine if this is a payment or subscription webhook
            $isSubscription = str_starts_with($id, 'sub_');
            $isPayment = str_starts_with($id, 'tr_');

            if ($isPayment) {
                $success = $this->webhookService->handlePaymentWebhook($id);

                Log::info('Payment webhook processed', [
                    'payment_id' => $id,
                    'success' => $success,
                ]);

            } elseif ($isSubscription) {
                $success = $this->webhookService->handleSubscriptionWebhook($id);

                Log::info('Subscription webhook processed', [
                    'subscription_id' => $id,
                    'success' => $success,
                ]);

            } else {
                Log::warning('Unknown webhook type', [
                    'id' => $id,
                    'request_body' => $request->all(),
                ]);

                return response()->json(['error' => 'Unknown webhook type'], 400);
            }

            if ($success) {
                return response()->json(['status' => 'ok']);
            } else {
                return response()->json(['error' => 'Webhook processing failed'], 500);
            }

        } catch (\Exception $e) {
            Log::error('Mollie webhook processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_body' => $request->all(),
            ]);

            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Verify Mollie webhook signature
     *
     * Security layers:
     * 1. IP whitelisting (configurable via MOLLIE_WEBHOOK_IPS)
     * 2. ID format validation
     * 3. Payment status verification via API (in MollieWebhookService)
     */
    private function verifyWebhookSignature(Request $request): bool
    {
        // Skip verification in local development
        if (app()->environment('local') && config('app.debug')) {
            Log::info('Skipping webhook signature verification (local environment)');

            return true;
        }

        // Get Mollie IPs from config (allows updating without code changes)
        $mollieIpsConfig = config('services.mollie.webhook_ips', '');
        $mollieIps = array_filter(array_map('trim', explode(',', $mollieIpsConfig)));

        if (empty($mollieIps)) {
            Log::error('Mollie webhook IPs not configured');

            return false;
        }

        $requestIp = $request->ip();

        // Check if request comes from Mollie's IP range
        if (! in_array($requestIp, $mollieIps)) {
            Log::channel('security')->warning('Mollie webhook from unauthorized IP', [
                'ip' => $requestIp,
                'allowed_ips' => $mollieIps,
                'user_agent' => $request->userAgent(),
            ]);

            return false;
        }

        // Additional check: Verify the ID format is valid
        $id = $request->input('id');
        if (! $id || ! preg_match('/^(tr_|sub_)[a-zA-Z0-9]+$/', $id)) {
            Log::channel('security')->warning('Invalid Mollie ID format in webhook', [
                'id' => $id,
                'ip' => $requestIp,
            ]);

            return false;
        }

        return true;
    }
}
