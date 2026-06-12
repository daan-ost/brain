<?php

use App\Enums\DemoItemPriority;
use App\Enums\DemoItemStatus;
use App\Models\DemoItem;
use App\Models\User;
use App\Services\DemoItemService;

beforeEach(function () {
    $this->service = new DemoItemService;
    $this->user = User::factory()->create();
});

it('creates a demo item with defaults', function () {
    $item = $this->service->create($this->user, [
        'title' => 'Test Item',
    ]);

    expect($item)->toBeInstanceOf(DemoItem::class);
    expect($item->title)->toBe('Test Item');
    expect($item->user_id)->toBe($this->user->id);
    $this->assertDatabaseHas('demo_items', ['title' => 'Test Item']);
});

it('creates a demo item with all fields', function () {
    $item = $this->service->create($this->user, [
        'title' => 'Full Item',
        'description' => 'A full description',
        'status' => 'active',
        'priority' => 'high',
        'amount' => 99.99,
        'due_date' => '2026-03-01',
    ]);

    expect($item->description)->toBe('A full description');
    expect($item->status)->toBe(DemoItemStatus::Active);
    expect($item->priority)->toBe(DemoItemPriority::High);
    expect((float) $item->amount)->toBe(99.99);
});

it('updates a demo item', function () {
    $item = DemoItem::factory()->create(['user_id' => $this->user->id, 'title' => 'Old Title']);

    $updated = $this->service->update($item, ['title' => 'New Title']);

    expect($updated->title)->toBe('New Title');
    $this->assertDatabaseHas('demo_items', ['id' => $item->id, 'title' => 'New Title']);
});

it('deletes a demo item', function () {
    $item = DemoItem::factory()->create(['user_id' => $this->user->id]);

    $result = $this->service->delete($item);

    expect($result)->toBeTrue();
    $this->assertSoftDeleted('demo_items', ['id' => $item->id]);
});

it('transitions status via service', function () {
    $item = DemoItem::factory()->draft()->create(['user_id' => $this->user->id]);

    $updated = $this->service->transitionStatus($item, DemoItemStatus::Active);

    expect($updated->status)->toBe(DemoItemStatus::Active);
});

it('bulk deletes items for user', function () {
    $items = DemoItem::factory()->count(3)->create(['user_id' => $this->user->id]);
    $otherUser = User::factory()->create();
    $otherItem = DemoItem::factory()->create(['user_id' => $otherUser->id]);

    $ids = $items->pluck('id')->push($otherItem->id)->all();
    $count = $this->service->bulkDelete($ids, $this->user);

    expect($count)->toBe(3); // Only deletes user's items
});

it('bulk transitions items for user', function () {
    $items = DemoItem::factory()->draft()->count(3)->create(['user_id' => $this->user->id]);

    $count = $this->service->bulkTransition($items->pluck('id')->all(), $this->user, DemoItemStatus::Active);

    expect($count)->toBe(3);
});

it('returns correct summary', function () {
    DemoItem::factory()->draft()->count(2)->create(['user_id' => $this->user->id]);
    DemoItem::factory()->active()->count(3)->create(['user_id' => $this->user->id, 'amount' => 100]);
    DemoItem::factory()->completed()->create(['user_id' => $this->user->id, 'amount' => 50]);
    DemoItem::factory()->overdue()->create(['user_id' => $this->user->id, 'amount' => 25]);

    $summary = $this->service->getSummary($this->user);

    expect($summary['total'])->toBe(7);
    expect($summary['draft'])->toBe(2);
    expect($summary['active'])->toBe(4); // 3 active + 1 overdue (also active)
    expect($summary['completed'])->toBe(1);
    expect($summary['overdue'])->toBe(1);
});
