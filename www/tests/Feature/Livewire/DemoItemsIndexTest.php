<?php

use App\Enums\DemoItemPriority;
use App\Enums\DemoItemStatus;
use App\Livewire\DemoItems\Index;
use App\Models\DemoItem;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    config(['features.demo_crud' => true]);
    $this->user = User::factory()->create();
});

it('renders the index component', function () {
    $this->actingAs($this->user);

    Livewire::test(Index::class)
        ->assertOk()
        ->assertSee('Demo Items');
});

it('shows only current user items', function () {
    $otherUser = User::factory()->create();
    DemoItem::factory()->create(['user_id' => $this->user->id, 'title' => 'My Item']);
    DemoItem::factory()->create(['user_id' => $otherUser->id, 'title' => 'Other Item']);

    $this->actingAs($this->user);

    Livewire::test(Index::class)
        ->assertSee('My Item')
        ->assertDontSee('Other Item');
});

it('filters by status', function () {
    DemoItem::factory()->draft()->create(['user_id' => $this->user->id, 'title' => 'Draft Thing']);
    DemoItem::factory()->active()->create(['user_id' => $this->user->id, 'title' => 'Active Thing']);

    $this->actingAs($this->user);

    Livewire::test(Index::class)
        ->set('statusFilter', 'draft')
        ->assertSee('Draft Thing')
        ->assertDontSee('Active Thing');
});

it('filters by priority', function () {
    DemoItem::factory()->create(['user_id' => $this->user->id, 'title' => 'Low Item', 'priority' => DemoItemPriority::Low]);
    DemoItem::factory()->create(['user_id' => $this->user->id, 'title' => 'Urgent Item', 'priority' => DemoItemPriority::Urgent]);

    $this->actingAs($this->user);

    Livewire::test(Index::class)
        ->set('priorityFilter', 'urgent')
        ->assertSee('Urgent Item')
        ->assertDontSee('Low Item');
});

it('filters by search', function () {
    DemoItem::factory()->create(['user_id' => $this->user->id, 'title' => 'Alpha Project']);
    DemoItem::factory()->create(['user_id' => $this->user->id, 'title' => 'Beta Project']);

    $this->actingAs($this->user);

    Livewire::test(Index::class)
        ->set('search', 'Alpha')
        ->assertSee('Alpha Project')
        ->assertDontSee('Beta Project');
});

it('sorts by column', function () {
    DemoItem::factory()->create(['user_id' => $this->user->id, 'title' => 'AAA First']);
    DemoItem::factory()->create(['user_id' => $this->user->id, 'title' => 'ZZZ Last']);

    $this->actingAs($this->user);

    Livewire::test(Index::class)
        ->call('sort', 'title')
        ->assertSeeInOrder(['AAA First', 'ZZZ Last']);
});

it('shows summary cards with correct data', function () {
    DemoItem::factory()->active()->count(3)->create(['user_id' => $this->user->id, 'amount' => 100]);

    $this->actingAs($this->user);

    Livewire::test(Index::class)
        ->assertSee('3') // active count
        ->assertSee('300.00'); // total amount
});

it('can delete a single item', function () {
    $item = DemoItem::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user);

    Livewire::test(Index::class)
        ->call('deleteSingle', $item->id)
        ->assertHasNoErrors();

    $this->assertSoftDeleted('demo_items', ['id' => $item->id]);
});

// Mobile Card Layout Tests

it('renders mobile card layout and desktop table markup', function () {
    DemoItem::factory()->create([
        'user_id' => $this->user->id,
        'title' => 'Test Mobile Item',
    ]);

    $this->actingAs($this->user);

    Livewire::test(Index::class)
        ->assertOk()
        ->assertSeeHtml('sm:hidden space-y-3')
        ->assertSeeHtml('hidden sm:block')
        ->assertSee('Test Mobile Item');
});

it('shows item data in both mobile and desktop views', function () {
    DemoItem::factory()->create([
        'user_id' => $this->user->id,
        'title' => 'Dual Layout Item',
        'amount' => 42.50,
    ]);

    $this->actingAs($this->user);

    $response = Livewire::test(Index::class)
        ->assertOk();

    $html = $response->html();
    expect(substr_count($html, 'Dual Layout Item'))->toBeGreaterThanOrEqual(2);
    expect(substr_count($html, '42.50'))->toBeGreaterThanOrEqual(2);
});

it('renders empty state in both mobile and desktop views when no items', function () {
    $this->actingAs($this->user);

    Livewire::test(Index::class)
        ->assertOk()
        ->assertSee('No demo items found');
});
