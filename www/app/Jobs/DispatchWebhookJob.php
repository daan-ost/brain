<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1; // Retries handled by WebhookService

    public int $timeout = 60;

    public function __construct(
        public WebhookDelivery $delivery
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(WebhookService $webhookService): void
    {
        // Skip if already processed
        if ($this->delivery->status === WebhookDelivery::STATUS_SUCCESS ||
            $this->delivery->status === WebhookDelivery::STATUS_FAILED) {
            return;
        }

        $webhookService->deliver($this->delivery);
    }

    public function tags(): array
    {
        return [
            'webhook',
            'webhook:'.$this->delivery->webhook_id,
            'delivery:'.$this->delivery->id,
        ];
    }
}
