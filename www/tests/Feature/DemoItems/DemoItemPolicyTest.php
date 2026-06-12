<?php

use App\Models\DemoItem;
use App\Models\User;

beforeEach(function () {
    config(['features.demo_crud' => true]);
});

it('allows owner to view their item', function () {
    $user = User::factory()->create();
    $item = DemoItem::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('demo-items.show', $item))
        ->assertOk();
});

it('forbids non-owner from viewing item', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $item = DemoItem::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($other)
        ->get(route('demo-items.show', $item))
        ->assertForbidden();
});

it('allows owner to edit their item', function () {
    $user = User::factory()->create();
    $item = DemoItem::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('demo-items.edit', $item))
        ->assertOk();
});

it('forbids non-owner from editing item', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $item = DemoItem::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($other)
        ->get(route('demo-items.edit', $item))
        ->assertForbidden();
});

it('redirects unauthenticated users to login', function () {
    $item = DemoItem::factory()->create();

    $this->get(route('demo-items.show', $item))
        ->assertRedirect(route('login'));
});
