<?php

namespace App\Http\Controllers;

use App\Models\WebhookEvent;
use App\Services\Payments\StripeWebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $secret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (\UnexpectedValueException $e) {
            Log::warning('Stripe webhook: invalid payload', ['error' => $e->getMessage()]);

            return response('Invalid payload', 400);
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook: invalid signature', ['ip' => $request->ip()]);

            return response('Invalid signature', 400);
        }

        // Idempotency check
        $webhookEvent = WebhookEvent::where('provider', 'stripe')
            ->where('event_id', $event->id)
            ->first();

        if ($webhookEvent && $webhookEvent->isProcessed()) {
            return response('Already processed', 200);
        }

        $webhookEvent = WebhookEvent::updateOrCreate(
            ['provider' => 'stripe', 'event_id' => $event->id],
            ['event_type' => $event->type, 'payload' => $event->toArray()]
        );

        try {
            app(StripeWebhookService::class)->handle($event);
            $webhookEvent->markProcessed();
        } catch (\Throwable $e) {
            Log::error('Stripe webhook processing failed', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $webhookEvent->markFailed($e->getMessage());

            return response('Processing failed', 500);
        }

        return response('OK', 200);
    }
}
