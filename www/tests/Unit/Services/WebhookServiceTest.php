<?php

use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->webhookService = new WebhookService;
    $this->user = User::factory()->create();
});

describe('dispatchEvent', function () {
    it('dispatches webhook to all matching active webhooks', function () {
        Queue::fake();

        Webhook::create([
            'user_id' => $this->user->id,
            'url' => 'https://active.example.com/webhook',
            'events' => [Webhook::EVENT_EXECUTION_COMPLETED],
            'is_active' => true,
        ]);

        Webhook::create([
            'user_id' => $this->user->id,
            'url' => 'https://inactive.example.com/webhook',
            'events' => [Webhook::EVENT_EXECUTION_COMPLETED],
            'is_active' => false,
        ]);

        $this->webhookService->dispatchEvent(
            $this->user->id,
            Webhook::EVENT_EXECUTION_COMPLETED,
            ['test' => 'data']
        );

        // Only 1 delivery should be created (for active webhook)
        expect(WebhookDelivery::count())->toBe(1);
    });

    it('only dispatches to webhooks subscribed to the event', function () {
        Queue::fake();

        Webhook::create([
            'user_id' => $this->user->id,
            'url' => 'https://completed.example.com/webhook',
            'events' => [Webhook::EVENT_EXECUTION_COMPLETED],
            'is_active' => true,
        ]);

        Webhook::create([
            'user_id' => $this->user->id,
            'url' => 'https://failed.example.com/webhook',
            'events' => [Webhook::EVENT_EXECUTION_FAILED],
            'is_active' => true,
        ]);

        $this->webhookService->dispatchEvent(
            $this->user->id,
            Webhook::EVENT_EXECUTION_COMPLETED,
            ['test' => 'data']
        );

        expect(WebhookDelivery::count())->toBe(1);
        expect(WebhookDelivery::first()->webhook->url)->toBe('https://completed.example.com/webhook');
    });

    it('does nothing when no webhooks match', function () {
        Queue::fake();

        $this->webhookService->dispatchEvent(
            $this->user->id,
            Webhook::EVENT_EXECUTION_COMPLETED,
            ['test' => 'data']
        );

        expect(WebhookDelivery::count())->toBe(0);
    });
});

describe('queueWebhookDelivery', function () {
    it('creates delivery record with correct payload', function () {
        Queue::fake();

        $webhook = Webhook::create([
            'user_id' => $this->user->id,
            'url' => 'https://example.com/webhook',
            'events' => [Webhook::EVENT_EXECUTION_COMPLETED],
        ]);

        $delivery = $this->webhookService->queueWebhookDelivery(
            $webhook,
            Webhook::EVENT_EXECUTION_COMPLETED,
            ['execution_id' => 123]
        );

        expect($delivery->webhook_id)->toBe($webhook->id);
        expect($delivery->event)->toBe(Webhook::EVENT_EXECUTION_COMPLETED);
        expect($delivery->status)->toBe(WebhookDelivery::STATUS_PENDING);
        expect($delivery->payload)->toHaveKey('id');
        expect($delivery->payload)->toHaveKey('event');
        expect($delivery->payload)->toHaveKey('created_at');
        expect($delivery->payload)->toHaveKey('data');
        expect($delivery->payload['data'])->toBe(['execution_id' => 123]);
    });

    it('generates unique delivery id in payload', function () {
        Queue::fake();

        $webhook = Webhook::create([
            'user_id' => $this->user->id,
            'url' => 'https://example.com/webhook',
            'events' => [Webhook::EVENT_EXECUTION_COMPLETED],
        ]);

        $delivery1 = $this->webhookService->queueWebhookDelivery($webhook, Webhook::EVENT_EXECUTION_COMPLETED, []);
        $delivery2 = $this->webhookService->queueWebhookDelivery($webhook, Webhook::EVENT_EXECUTION_COMPLETED, []);

        expect($delivery1->payload['id'])->not->toBe($delivery2->payload['id']);
        expect($delivery1->payload['id'])->toStartWith('wh_');
    });
});

describe('generateSignature', function () {
    it('generates valid HMAC-SHA256 signature', function () {
        $timestamp = 1234567890;
        $payload = '{"test":"data"}';
        $secret = 'my-secret-key';

        $signature = $this->webhookService->generateSignature($timestamp, $payload, $secret);

        expect($signature)->toStartWith('sha256=');
        expect(strlen($signature))->toBe(71); // 'sha256=' + 64 hex chars
    });

    it('generates different signatures for different timestamps', function () {
        $payload = '{"test":"data"}';
        $secret = 'my-secret-key';

        $sig1 = $this->webhookService->generateSignature(1000, $payload, $secret);
        $sig2 = $this->webhookService->generateSignature(2000, $payload, $secret);

        expect($sig1)->not->toBe($sig2);
    });

    it('generates different signatures for different secrets', function () {
        $timestamp = 1234567890;
        $payload = '{"test":"data"}';

        $sig1 = $this->webhookService->generateSignature($timestamp, $payload, 'secret1');
        $sig2 = $this->webhookService->generateSignature($timestamp, $payload, 'secret2');

        expect($sig1)->not->toBe($sig2);
    });
});

describe('verifySignature', function () {
    it('verifies valid signature', function () {
        $timestamp = 1234567890;
        $payload = '{"test":"data"}';
        $secret = 'my-secret-key';

        $signature = $this->webhookService->generateSignature($timestamp, $payload, $secret);
        $isValid = $this->webhookService->verifySignature($signature, $timestamp, $payload, $secret);

        expect($isValid)->toBeTrue();
    });

    it('rejects invalid signature', function () {
        $timestamp = 1234567890;
        $payload = '{"test":"data"}';
        $secret = 'my-secret-key';

        $isValid = $this->webhookService->verifySignature('sha256=invalid', $timestamp, $payload, $secret);

        expect($isValid)->toBeFalse();
    });

    it('rejects signature with wrong timestamp', function () {
        $payload = '{"test":"data"}';
        $secret = 'my-secret-key';

        $signature = $this->webhookService->generateSignature(1000, $payload, $secret);
        $isValid = $this->webhookService->verifySignature($signature, 2000, $payload, $secret);

        expect($isValid)->toBeFalse();
    });
});

describe('deliver', function () {
    it('marks delivery as success on 2xx response', function () {
        Http::fake([
            'example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $webhook = Webhook::create([
            'user_id' => $this->user->id,
            'url' => 'https://example.com/webhook',
            'events' => [Webhook::EVENT_EXECUTION_COMPLETED],
        ]);

        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'event' => Webhook::EVENT_EXECUTION_COMPLETED,
            'payload' => ['id' => 'wh_test', 'event' => 'test', 'data' => []],
            'status' => WebhookDelivery::STATUS_PENDING,
            'created_at' => now(),
        ]);

        $result = $this->webhookService->deliver($delivery);

        expect($result)->toBeTrue();
        $delivery->refresh();
        expect($delivery->status)->toBe(WebhookDelivery::STATUS_SUCCESS);
        expect($delivery->response_code)->toBe(200);
    });

    it('resets failure count on success', function () {
        Http::fake([
            'example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $webhook = Webhook::create([
            'user_id' => $this->user->id,
            'url' => 'https://example.com/webhook',
            'events' => [Webhook::EVENT_EXECUTION_COMPLETED],
            'failure_count' => 3,
        ]);

        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'event' => Webhook::EVENT_EXECUTION_COMPLETED,
            'payload' => ['id' => 'wh_test', 'event' => 'test', 'data' => []],
            'status' => WebhookDelivery::STATUS_PENDING,
            'created_at' => now(),
        ]);

        $this->webhookService->deliver($delivery);

        expect($webhook->fresh()->failure_count)->toBe(0);
    });

    it('records trigger on webhook after delivery', function () {
        Http::fake([
            'example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $webhook = Webhook::create([
            'user_id' => $this->user->id,
            'url' => 'https://example.com/webhook',
            'events' => [Webhook::EVENT_EXECUTION_COMPLETED],
        ]);

        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'event' => Webhook::EVENT_EXECUTION_COMPLETED,
            'payload' => ['id' => 'wh_test', 'event' => 'test', 'data' => []],
            'status' => WebhookDelivery::STATUS_PENDING,
            'created_at' => now(),
        ]);

        $this->webhookService->deliver($delivery);

        $webhook->refresh();
        expect($webhook->last_triggered_at)->not->toBeNull();
        expect($webhook->last_response_code)->toBe(200);
    });

    it('skips delivery for inactive webhook', function () {
        $webhook = Webhook::create([
            'user_id' => $this->user->id,
            'url' => 'https://example.com/webhook',
            'events' => [Webhook::EVENT_EXECUTION_COMPLETED],
            'is_active' => false,
        ]);

        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'event' => Webhook::EVENT_EXECUTION_COMPLETED,
            'payload' => ['id' => 'wh_test', 'event' => 'test', 'data' => []],
            'status' => WebhookDelivery::STATUS_PENDING,
            'created_at' => now(),
        ]);

        $result = $this->webhookService->deliver($delivery);

        expect($result)->toBeFalse();
        expect($delivery->fresh()->status)->toBe(WebhookDelivery::STATUS_FAILED);
    });
});

describe('sendTestEvent', function () {
    it('creates test delivery with correct data', function () {
        Queue::fake();

        $webhook = Webhook::create([
            'user_id' => $this->user->id,
            'url' => 'https://example.com/webhook',
            'events' => [Webhook::EVENT_EXECUTION_COMPLETED],
        ]);

        $delivery = $this->webhookService->sendTestEvent($webhook);

        expect($delivery->event)->toBe('test');
        expect($delivery->payload['data']['test'])->toBeTrue();
        expect($delivery->payload['data']['workflow_name'])->toBe('Test Webhook');
    });
});
