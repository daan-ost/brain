<?php

use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('can create a webhook with valid data', function () {
    $webhook = Webhook::create([
        'user_id' => $this->user->id,
        'url' => 'https://example.com/webhook',
        'events' => [Webhook::EVENT_EXECUTION_COMPLETED],
        'is_active' => true,
    ]);

    expect($webhook->id)->toBeInt();
    expect($webhook->url)->toBe('https://example.com/webhook');
    expect($webhook->events)->toBe([Webhook::EVENT_EXECUTION_COMPLETED]);
    expect($webhook->is_active)->toBeTrue();
    expect($webhook->failure_count)->toBe(0);
});

it('casts events to array', function () {
    $webhook = Webhook::create([
        'user_id' => $this->user->id,
        'url' => 'https://example.com/webhook',
        'events' => [Webhook::EVENT_EXECUTION_COMPLETED, Webhook::EVENT_EXECUTION_FAILED],
    ]);

    expect($webhook->events)->toBeArray();
    expect($webhook->events)->toContain(Webhook::EVENT_EXECUTION_COMPLETED);
    expect($webhook->events)->toContain(Webhook::EVENT_EXECUTION_FAILED);
});

it('hides secret in array/json output', function () {
    $webhook = Webhook::create([
        'user_id' => $this->user->id,
        'url' => 'https://example.com/webhook',
        'events' => [Webhook::EVENT_EXECUTION_COMPLETED],
        'secret' => 'my-secret-key',
    ]);

    $array = $webhook->toArray();

    expect($array)->not->toHaveKey('secret');
    expect($webhook->secret)->toBe('my-secret-key');
});

it('belongs to a user', function () {
    $webhook = Webhook::create([
        'user_id' => $this->user->id,
        'url' => 'https://example.com/webhook',
        'events' => [Webhook::EVENT_EXECUTION_COMPLETED],
    ]);

    expect($webhook->user)->toBeInstanceOf(User::class);
    expect($webhook->user->id)->toBe($this->user->id);
});

it('has many deliveries', function () {
    $webhook = Webhook::create([
        'user_id' => $this->user->id,
        'url' => 'https://example.com/webhook',
        'events' => [Webhook::EVENT_EXECUTION_COMPLETED],
    ]);

    WebhookDelivery::create([
        'webhook_id' => $webhook->id,
        'event' => Webhook::EVENT_EXECUTION_COMPLETED,
        'payload' => ['test' => 'data'],
        'created_at' => now(),
    ]);

    expect($webhook->deliveries)->toHaveCount(1);
    expect($webhook->deliveries->first())->toBeInstanceOf(WebhookDelivery::class);
});

it('scopes active webhooks', function () {
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

    $activeWebhooks = Webhook::active()->get();

    expect($activeWebhooks)->toHaveCount(1);
    expect($activeWebhooks->first()->url)->toBe('https://active.example.com/webhook');
});

it('scopes webhooks for specific event', function () {
    Webhook::create([
        'user_id' => $this->user->id,
        'url' => 'https://completed.example.com/webhook',
        'events' => [Webhook::EVENT_EXECUTION_COMPLETED],
    ]);

    Webhook::create([
        'user_id' => $this->user->id,
        'url' => 'https://failed.example.com/webhook',
        'events' => [Webhook::EVENT_EXECUTION_FAILED],
    ]);

    $completedWebhooks = Webhook::forEvent(Webhook::EVENT_EXECUTION_COMPLETED)->get();

    expect($completedWebhooks)->toHaveCount(1);
    expect($completedWebhooks->first()->url)->toBe('https://completed.example.com/webhook');
});

it('determines if should receive event', function () {
    $webhook = Webhook::create([
        'user_id' => $this->user->id,
        'url' => 'https://example.com/webhook',
        'events' => [Webhook::EVENT_EXECUTION_COMPLETED, Webhook::EVENT_EXECUTION_FAILED],
        'is_active' => true,
    ]);

    expect($webhook->shouldReceiveEvent(Webhook::EVENT_EXECUTION_COMPLETED))->toBeTrue();
    expect($webhook->shouldReceiveEvent(Webhook::EVENT_EXECUTION_FAILED))->toBeTrue();
    expect($webhook->shouldReceiveEvent(Webhook::EVENT_EXECUTION_STARTED))->toBeFalse();

    $webhook->update(['is_active' => false]);

    expect($webhook->shouldReceiveEvent(Webhook::EVENT_EXECUTION_COMPLETED))->toBeFalse();
});

it('increments failure count', function () {
    $webhook = Webhook::create([
        'user_id' => $this->user->id,
        'url' => 'https://example.com/webhook',
        'events' => [Webhook::EVENT_EXECUTION_COMPLETED],
        'failure_count' => 0,
    ]);

    $webhook->incrementFailureCount();
    expect($webhook->fresh()->failure_count)->toBe(1);

    $webhook->incrementFailureCount();
    expect($webhook->fresh()->failure_count)->toBe(2);
});

it('disables webhook after max failures', function () {
    $webhook = Webhook::create([
        'user_id' => $this->user->id,
        'url' => 'https://example.com/webhook',
        'events' => [Webhook::EVENT_EXECUTION_COMPLETED],
        'failure_count' => Webhook::MAX_FAILURES_BEFORE_DISABLE - 1,
        'is_active' => true,
    ]);

    expect($webhook->is_active)->toBeTrue();

    $webhook->incrementFailureCount();

    $webhook->refresh();
    expect($webhook->failure_count)->toBe(Webhook::MAX_FAILURES_BEFORE_DISABLE);
    expect($webhook->is_active)->toBeFalse();
});

it('resets failure count', function () {
    $webhook = Webhook::create([
        'user_id' => $this->user->id,
        'url' => 'https://example.com/webhook',
        'events' => [Webhook::EVENT_EXECUTION_COMPLETED],
        'failure_count' => 5,
    ]);

    $webhook->resetFailureCount();

    expect($webhook->fresh()->failure_count)->toBe(0);
});

it('records trigger information', function () {
    $webhook = Webhook::create([
        'user_id' => $this->user->id,
        'url' => 'https://example.com/webhook',
        'events' => [Webhook::EVENT_EXECUTION_COMPLETED],
    ]);

    expect($webhook->last_triggered_at)->toBeNull();
    expect($webhook->last_response_code)->toBeNull();

    $webhook->recordTrigger(200);

    $webhook->refresh();
    expect($webhook->last_triggered_at)->not->toBeNull();
    expect($webhook->last_response_code)->toBe(200);
});

it('validates events against valid events list', function () {
    expect(Webhook::VALID_EVENTS)->toContain(Webhook::EVENT_EXECUTION_STARTED);
    expect(Webhook::VALID_EVENTS)->toContain(Webhook::EVENT_EXECUTION_PROGRESS);
    expect(Webhook::VALID_EVENTS)->toContain(Webhook::EVENT_EXECUTION_COMPLETED);
    expect(Webhook::VALID_EVENTS)->toContain(Webhook::EVENT_EXECUTION_FAILED);
    expect(Webhook::VALID_EVENTS)->toHaveCount(4);
});
