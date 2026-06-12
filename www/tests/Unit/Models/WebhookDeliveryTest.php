<?php

use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->webhook = Webhook::create([
        'user_id' => $this->user->id,
        'url' => 'https://example.com/webhook',
        'events' => [Webhook::EVENT_EXECUTION_COMPLETED],
    ]);
});

it('can create a delivery with valid data', function () {
    $delivery = WebhookDelivery::create([
        'webhook_id' => $this->webhook->id,
        'event' => Webhook::EVENT_EXECUTION_COMPLETED,
        'payload' => ['test' => 'data'],
        'status' => WebhookDelivery::STATUS_PENDING,
        'created_at' => now(),
    ]);

    expect($delivery->id)->toBeInt();
    expect($delivery->event)->toBe(Webhook::EVENT_EXECUTION_COMPLETED);
    expect($delivery->payload)->toBe(['test' => 'data']);
    expect($delivery->status)->toBe(WebhookDelivery::STATUS_PENDING);
    expect($delivery->attempt)->toBe(1);
});

it('casts payload to array', function () {
    $delivery = WebhookDelivery::create([
        'webhook_id' => $this->webhook->id,
        'event' => Webhook::EVENT_EXECUTION_COMPLETED,
        'payload' => ['id' => 'wh_123', 'event' => 'test', 'data' => ['key' => 'value']],
        'created_at' => now(),
    ]);

    expect($delivery->payload)->toBeArray();
    expect($delivery->payload['id'])->toBe('wh_123');
    expect($delivery->payload['data']['key'])->toBe('value');
});

it('belongs to a webhook', function () {
    $delivery = WebhookDelivery::create([
        'webhook_id' => $this->webhook->id,
        'event' => Webhook::EVENT_EXECUTION_COMPLETED,
        'payload' => ['test' => 'data'],
        'created_at' => now(),
    ]);

    expect($delivery->webhook)->toBeInstanceOf(Webhook::class);
    expect($delivery->webhook->id)->toBe($this->webhook->id);
});

it('scopes pending deliveries', function () {
    WebhookDelivery::create([
        'webhook_id' => $this->webhook->id,
        'event' => Webhook::EVENT_EXECUTION_COMPLETED,
        'payload' => ['test' => 'data'],
        'status' => WebhookDelivery::STATUS_PENDING,
        'created_at' => now(),
    ]);

    WebhookDelivery::create([
        'webhook_id' => $this->webhook->id,
        'event' => Webhook::EVENT_EXECUTION_COMPLETED,
        'payload' => ['test' => 'data'],
        'status' => WebhookDelivery::STATUS_SUCCESS,
        'created_at' => now(),
    ]);

    $pending = WebhookDelivery::pending()->get();

    expect($pending)->toHaveCount(1);
    expect($pending->first()->status)->toBe(WebhookDelivery::STATUS_PENDING);
});

it('scopes deliveries ready for retry', function () {
    // Not ready (in future)
    WebhookDelivery::create([
        'webhook_id' => $this->webhook->id,
        'event' => Webhook::EVENT_EXECUTION_COMPLETED,
        'payload' => ['test' => 'data'],
        'status' => WebhookDelivery::STATUS_RETRYING,
        'next_retry_at' => now()->addHour(),
        'created_at' => now(),
    ]);

    // Ready for retry
    WebhookDelivery::create([
        'webhook_id' => $this->webhook->id,
        'event' => Webhook::EVENT_EXECUTION_COMPLETED,
        'payload' => ['test' => 'data'],
        'status' => WebhookDelivery::STATUS_RETRYING,
        'next_retry_at' => now()->subMinute(),
        'created_at' => now(),
    ]);

    $ready = WebhookDelivery::readyForRetry()->get();

    expect($ready)->toHaveCount(1);
});

it('marks as success', function () {
    $delivery = WebhookDelivery::create([
        'webhook_id' => $this->webhook->id,
        'event' => Webhook::EVENT_EXECUTION_COMPLETED,
        'payload' => ['test' => 'data'],
        'status' => WebhookDelivery::STATUS_PENDING,
        'created_at' => now(),
    ]);

    $delivery->markAsSuccess(200, '{"ok":true}', 150);

    $delivery->refresh();
    expect($delivery->status)->toBe(WebhookDelivery::STATUS_SUCCESS);
    expect($delivery->response_code)->toBe(200);
    expect($delivery->response_body)->toBe('{"ok":true}');
    expect($delivery->duration_ms)->toBe(150);
    expect($delivery->next_retry_at)->toBeNull();
});

it('marks as failed', function () {
    $delivery = WebhookDelivery::create([
        'webhook_id' => $this->webhook->id,
        'event' => Webhook::EVENT_EXECUTION_COMPLETED,
        'payload' => ['test' => 'data'],
        'status' => WebhookDelivery::STATUS_PENDING,
        'created_at' => now(),
    ]);

    $delivery->markAsFailed(500, 'Internal Server Error', 1200);

    $delivery->refresh();
    expect($delivery->status)->toBe(WebhookDelivery::STATUS_FAILED);
    expect($delivery->response_code)->toBe(500);
    expect($delivery->response_body)->toBe('Internal Server Error');
    expect($delivery->duration_ms)->toBe(1200);
});

it('truncates long response body', function () {
    $delivery = WebhookDelivery::create([
        'webhook_id' => $this->webhook->id,
        'event' => Webhook::EVENT_EXECUTION_COMPLETED,
        'payload' => ['test' => 'data'],
        'created_at' => now(),
    ]);

    $longResponse = str_repeat('x', 2000);
    $delivery->markAsSuccess(200, $longResponse, 100);

    $delivery->refresh();
    expect(strlen($delivery->response_body))->toBe(1000);
});

it('schedules retry with exponential backoff', function () {
    $delivery = WebhookDelivery::create([
        'webhook_id' => $this->webhook->id,
        'event' => Webhook::EVENT_EXECUTION_COMPLETED,
        'payload' => ['test' => 'data'],
        'attempt' => 1,
        'created_at' => now(),
    ]);

    $result = $delivery->scheduleRetry();

    expect($result)->toBeTrue();
    $delivery->refresh();
    expect($delivery->status)->toBe(WebhookDelivery::STATUS_RETRYING);
    expect($delivery->attempt)->toBe(2);
    expect($delivery->next_retry_at)->not->toBeNull();
});

it('fails after max retries', function () {
    $delivery = WebhookDelivery::create([
        'webhook_id' => $this->webhook->id,
        'event' => Webhook::EVENT_EXECUTION_COMPLETED,
        'payload' => ['test' => 'data'],
        'attempt' => WebhookDelivery::MAX_RETRIES,
        'created_at' => now(),
    ]);

    $result = $delivery->scheduleRetry();

    expect($result)->toBeFalse();
    $delivery->refresh();
    expect($delivery->status)->toBe(WebhookDelivery::STATUS_FAILED);
});

it('determines if should retry based on response code', function () {
    $delivery = WebhookDelivery::create([
        'webhook_id' => $this->webhook->id,
        'event' => Webhook::EVENT_EXECUTION_COMPLETED,
        'payload' => ['test' => 'data'],
        'created_at' => now(),
    ]);

    // 5xx should retry
    expect($delivery->shouldRetry(500))->toBeTrue();
    expect($delivery->shouldRetry(503))->toBeTrue();

    // 429 rate limit should retry
    expect($delivery->shouldRetry(429))->toBeTrue();

    // 4xx should not retry (permanent errors)
    expect($delivery->shouldRetry(400))->toBeFalse();
    expect($delivery->shouldRetry(401))->toBeFalse();
    expect($delivery->shouldRetry(403))->toBeFalse();
    expect($delivery->shouldRetry(404))->toBeFalse();
});

it('has correct retry delays', function () {
    expect(WebhookDelivery::RETRY_DELAYS[1])->toBe(60);      // 1 minute
    expect(WebhookDelivery::RETRY_DELAYS[2])->toBe(300);     // 5 minutes
    expect(WebhookDelivery::RETRY_DELAYS[3])->toBe(1800);    // 30 minutes
    expect(WebhookDelivery::RETRY_DELAYS[4])->toBe(7200);    // 2 hours
    expect(WebhookDelivery::RETRY_DELAYS[5])->toBe(28800);   // 8 hours
    expect(WebhookDelivery::RETRY_DELAYS[6])->toBe(86400);   // 24 hours
});

it('has correct status constants', function () {
    expect(WebhookDelivery::STATUS_PENDING)->toBe('pending');
    expect(WebhookDelivery::STATUS_SUCCESS)->toBe('success');
    expect(WebhookDelivery::STATUS_FAILED)->toBe('failed');
    expect(WebhookDelivery::STATUS_RETRYING)->toBe('retrying');
});
