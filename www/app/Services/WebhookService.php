<?php

namespace App\Services;

use App\Jobs\DispatchWebhookJob;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WebhookService
{
    /**
     * Dispatch webhooks for a specific event to all relevant users.
     */
    public function dispatchEvent(int $userId, string $event, array $data): void
    {
        $webhooks = Webhook::where('user_id', $userId)
            ->active()
            ->forEvent($event)
            ->get();

        foreach ($webhooks as $webhook) {
            $this->queueWebhookDelivery($webhook, $event, $data);
        }
    }

    /**
     * Queue a webhook delivery for processing.
     */
    public function queueWebhookDelivery(Webhook $webhook, string $event, array $data): WebhookDelivery
    {
        $payload = $this->buildPayload($event, $data);

        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'event' => $event,
            'payload' => $payload,
            'status' => WebhookDelivery::STATUS_PENDING,
            'created_at' => now(),
        ]);

        DispatchWebhookJob::dispatch($delivery);

        return $delivery;
    }

    /**
     * Deliver a webhook.
     */
    public function deliver(WebhookDelivery $delivery): bool
    {
        $webhook = $delivery->webhook;

        if (! $webhook || ! $webhook->is_active) {
            $delivery->markAsFailed(0, 'Webhook disabled or deleted', 0);

            return false;
        }

        $payload = $delivery->payload;
        $headers = $this->buildHeaders($webhook, $payload);

        $startTime = microtime(true);

        try {
            $response = Http::timeout(WebhookDelivery::TIMEOUT_SECONDS)
                ->withHeaders($headers)
                ->post($webhook->url, $payload);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $responseCode = $response->status();
            $responseBody = $response->body();

            $webhook->recordTrigger($responseCode);

            if ($response->successful()) {
                $delivery->markAsSuccess($responseCode, $responseBody, $durationMs);
                $webhook->resetFailureCount();

                return true;
            }

            // Failed response
            if ($delivery->shouldRetry($responseCode)) {
                $webhook->incrementFailureCount();
                $delivery->update([
                    'response_code' => $responseCode,
                    'response_body' => substr($responseBody, 0, 1000),
                    'duration_ms' => $durationMs,
                ]);

                if ($delivery->scheduleRetry()) {
                    DispatchWebhookJob::dispatch($delivery)
                        ->delay($delivery->next_retry_at);
                }
            } else {
                // Permanent failure (4xx except 429)
                $delivery->markAsFailed($responseCode, $responseBody, $durationMs);
                $webhook->incrementFailureCount();
            }

            return false;

        } catch (\Exception $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $webhook->incrementFailureCount();
            $delivery->update([
                'response_code' => 0,
                'response_body' => substr($e->getMessage(), 0, 1000),
                'duration_ms' => $durationMs,
            ]);

            if ($delivery->scheduleRetry()) {
                DispatchWebhookJob::dispatch($delivery)
                    ->delay($delivery->next_retry_at);
            }

            return false;
        }
    }

    /**
     * Build the webhook payload.
     */
    protected function buildPayload(string $event, array $data): array
    {
        return [
            'id' => 'wh_'.Str::random(16),
            'event' => $event,
            'created_at' => now()->toIso8601String(),
            'data' => $data,
        ];
    }

    /**
     * Build headers including HMAC signature if secret is set.
     */
    protected function buildHeaders(Webhook $webhook, array $payload): array
    {
        $timestamp = time();
        $payloadJson = json_encode($payload);
        $deliveryId = $payload['id'] ?? Str::uuid()->toString();

        $headers = [
            'Content-Type' => 'application/json',
            'X-App-Delivery-ID' => $deliveryId,
            'X-App-Timestamp' => (string) $timestamp,
        ];

        // Add HMAC signature if secret is configured
        if ($webhook->secret) {
            $signature = $this->generateSignature($timestamp, $payloadJson, $webhook->secret);
            $headers['X-App-Signature'] = $signature;
        }

        return $headers;
    }

    /**
     * Generate HMAC signature.
     */
    public function generateSignature(int $timestamp, string $payload, string $secret): string
    {
        $signedPayload = $timestamp.'.'.$payload;

        return 'sha256='.hash_hmac('sha256', $signedPayload, $secret);
    }

    /**
     * Verify a webhook signature (for documentation/testing purposes).
     */
    public function verifySignature(string $signature, int $timestamp, string $payload, string $secret): bool
    {
        $expected = $this->generateSignature($timestamp, $payload, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Send a test webhook event.
     */
    public function sendTestEvent(Webhook $webhook): WebhookDelivery
    {
        $testData = [
            'execution_id' => 0,
            'workflow_id' => 0,
            'workflow_name' => 'Test Webhook',
            'status' => 'test',
            'files_processed' => 1,
            'credits_charged' => 0,
            'output_file_type' => 'pdf',
            'output_file_size' => 1024,
            'download_url' => url('/api/v2/test/download'),
            'download_expires_at' => now()->addWeek()->toIso8601String(),
            'test' => true,
        ];

        return $this->queueWebhookDelivery($webhook, 'test', $testData);
    }
}
