<?php

use App\Livewire\DemoItems\CreateForm;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    config(['features.demo_crud' => true]);
    $this->user = User::factory()->create();
});

it('renders the create form', function () {
    $this->actingAs($this->user);

    Livewire::test(CreateForm::class)
        ->assertOk()
        ->assertSee('Title');
});

it('validates required title', function () {
    $this->actingAs($this->user);

    Livewire::test(CreateForm::class)
        ->set('title', '')
        ->call('save')
        ->assertHasErrors(['title' => 'required']);
});

it('validates title max length', function () {
    $this->actingAs($this->user);

    Livewire::test(CreateForm::class)
        ->set('title', str_repeat('a', 256))
        ->call('save')
        ->assertHasErrors(['title' => 'max']);
});

it('validates amount is numeric', function () {
    $this->actingAs($this->user);

    Livewire::test(CreateForm::class)
        ->set('title', 'Valid Title')
        ->set('amount', 'not-a-number')
        ->call('save')
        ->assertHasErrors(['amount' => 'numeric']);
});

it('creates item successfully', function () {
    $this->actingAs($this->user);

    Livewire::test(CreateForm::class)
        ->set('title', 'New Demo Item')
        ->set('description', 'A test item')
        ->set('status', 'draft')
        ->set('priority', 'high')
        ->set('amount', '150.50')
        ->set('dueDate', '2026-03-15')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('demo_items', [
        'title' => 'New Demo Item',
        'user_id' => $this->user->id,
    ]);
});

it('dispatches event when in modal mode', function () {
    $this->actingAs($this->user);

    Livewire::test(CreateForm::class, ['isModal' => true])
        ->set('title', 'Modal Item')
        ->call('save')
        ->assertDispatched('demo-item-created');
});
