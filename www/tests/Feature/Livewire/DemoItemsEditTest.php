<?php

use App\Livewire\DemoItems\EditForm;
use App\Models\DemoItem;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    config(['features.demo_crud' => true]);
    $this->user = User::factory()->create();
});

it('renders the edit form with existing data', function () {
    $item = DemoItem::factory()->create([
        'user_id' => $this->user->id,
        'title' => 'Existing Item',
    ]);

    $this->actingAs($this->user);

    Livewire::test(EditForm::class, ['demoItem' => $item])
        ->assertOk()
        ->assertSet('title', 'Existing Item');
});

it('updates an item', function () {
    $item = DemoItem::factory()->create([
        'user_id' => $this->user->id,
        'title' => 'Old Title',
    ]);

    $this->actingAs($this->user);

    Livewire::test(EditForm::class, ['demoItem' => $item])
        ->set('title', 'Updated Title')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('demo_items', [
        'id' => $item->id,
        'title' => 'Updated Title',
    ]);
});

it('forbids editing another users item', function () {
    $otherUser = User::factory()->create();
    $item = DemoItem::factory()->create(['user_id' => $otherUser->id]);

    $this->actingAs($this->user);

    Livewire::test(EditForm::class, ['demoItem' => $item])
        ->assertForbidden();
});

it('validates required fields on update', function () {
    $item = DemoItem::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user);

    Livewire::test(EditForm::class, ['demoItem' => $item])
        ->set('title', '')
        ->call('save')
        ->assertHasErrors(['title' => 'required']);
});
