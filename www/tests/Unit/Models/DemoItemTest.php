<?php

use App\Enums\DemoItemPriority;
use App\Enums\DemoItemStatus;
use App\Models\DemoItem;
use App\Models\User;

it('generates a ULID as primary key', function () {
    $user = User::factory()->create();
    $item = DemoItem::factory()->create(['user_id' => $user->id]);

    expect($item->id)->toBeString();
    expect(strlen($item->id))->toBe(26); // ULID is 26 chars
});

it('belongs to a user', function () {
    $user = User::factory()->create();
    $item = DemoItem::factory()->create(['user_id' => $user->id]);

    expect($item->user)->toBeInstanceOf(User::class);
    expect($item->user->id)->toBe($user->id);
});

it('casts status to DemoItemStatus enum', function () {
    $item = DemoItem::factory()->create();

    expect($item->status)->toBeInstanceOf(DemoItemStatus::class);
});

it('casts priority to DemoItemPriority enum', function () {
    $item = DemoItem::factory()->create();

    expect($item->priority)->toBeInstanceOf(DemoItemPriority::class);
});

it('scopes forUser returns only user items', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    DemoItem::factory()->count(3)->create(['user_id' => $user1->id]);
    DemoItem::factory()->count(2)->create(['user_id' => $user2->id]);

    expect(DemoItem::forUser($user1->id)->count())->toBe(3);
    expect(DemoItem::forUser($user2->id)->count())->toBe(2);
});

it('scopes withStatus filters by status', function () {
    $user = User::factory()->create();
    DemoItem::factory()->draft()->count(2)->create(['user_id' => $user->id]);
    DemoItem::factory()->active()->count(3)->create(['user_id' => $user->id]);

    expect(DemoItem::forUser($user->id)->withStatus(DemoItemStatus::Draft)->count())->toBe(2);
    expect(DemoItem::forUser($user->id)->withStatus(DemoItemStatus::Active)->count())->toBe(3);
});

it('scopes overdue returns only overdue items', function () {
    $user = User::factory()->create();
    DemoItem::factory()->overdue()->create(['user_id' => $user->id]);
    DemoItem::factory()->active()->create(['user_id' => $user->id, 'due_date' => now()->addDays(5)]);
    DemoItem::factory()->completed()->create(['user_id' => $user->id, 'due_date' => now()->subDays(5)]);

    expect(DemoItem::forUser($user->id)->overdue()->count())->toBe(1);
});

it('transitions status correctly', function () {
    $item = DemoItem::factory()->draft()->create();
    $item->transitionTo(DemoItemStatus::Active);

    expect($item->fresh()->status)->toBe(DemoItemStatus::Active);
});

it('sets completed_at when transitioning to completed', function () {
    $item = DemoItem::factory()->active()->create();
    $item->transitionTo(DemoItemStatus::Completed);

    expect($item->fresh()->completed_at)->not->toBeNull();
});

it('clears completed_at when transitioning away from completed', function () {
    $item = DemoItem::factory()->completed()->create();
    $item->transitionTo(DemoItemStatus::Active);

    expect($item->fresh()->completed_at)->toBeNull();
});

it('throws exception for invalid transition', function () {
    $item = DemoItem::factory()->draft()->create();
    $item->transitionTo(DemoItemStatus::Completed);
})->throws(InvalidArgumentException::class);

it('supports soft deletes', function () {
    $item = DemoItem::factory()->create();
    $id = $item->id;

    $item->delete();

    expect(DemoItem::find($id))->toBeNull();
    expect(DemoItem::withTrashed()->find($id))->not->toBeNull();
});
