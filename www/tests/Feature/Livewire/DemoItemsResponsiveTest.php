<?php

use App\Livewire\DemoItems\CreateForm;
use App\Livewire\DemoItems\EditForm;
use App\Livewire\DemoItems\Index;
use App\Livewire\DemoItems\Show;
use App\Models\DemoItem;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    config(['features.demo_crud' => true]);
    $this->user = User::factory()->create();
});

// Detail page responsive tests

it('renders detail page with responsive action buttons', function () {
    $item = DemoItem::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user);

    Livewire::test(Show::class, ['demoItem' => $item])
        ->assertOk()
        ->assertSeeHtml('flex flex-col sm:flex-row gap-2')
        ->assertSeeHtml('w-full sm:w-auto');
});

it('renders detail page with tab bar', function () {
    $item = DemoItem::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user);

    Livewire::test(Show::class, ['demoItem' => $item])
        ->assertOk()
        ->assertSeeHtml('role="tablist"')
        ->assertSeeHtml('role="tabpanel"')
        ->assertSee('Overview')
        ->assertSee('Details');
});

it('renders detail page with horizontally scrollable tab container', function () {
    $item = DemoItem::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user);

    Livewire::test(Show::class, ['demoItem' => $item])
        ->assertOk()
        ->assertSeeHtml('overflow-x-auto')
        ->assertSeeHtml('min-w-max sm:min-w-0');
});

it('shows status tab for draft items with available transitions', function () {
    $item = DemoItem::factory()->draft()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user);

    // Draft items should have transitions available
    expect(count($item->status->allowedTransitions()))->toBeGreaterThan(0);

    Livewire::test(Show::class, ['demoItem' => $item])
        ->assertOk()
        ->assertSeeHtml('activeTab === \'status\'');
});

// Form responsive tests

it('renders create form with responsive grid columns', function () {
    $this->actingAs($this->user);

    Livewire::test(CreateForm::class)
        ->assertOk()
        ->assertSeeHtml('grid-cols-1 sm:grid-cols-2');
});

it('renders edit form with responsive grid columns', function () {
    $item = DemoItem::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user);

    Livewire::test(EditForm::class, ['demoItem' => $item])
        ->assertOk()
        ->assertSeeHtml('grid-cols-1 sm:grid-cols-2');
});

// Modal responsive tests

it('renders create modal with full-screen mobile markup', function () {
    $this->actingAs($this->user);

    $response = Livewire::test(Index::class)
        ->set('formMode', 'modal')
        ->call('openCreateModal');

    $html = $response->html();
    expect($html)->toContain('fixed inset-0 sm:inset-auto sm:relative');
});

it('renders edit modal with full-screen mobile markup', function () {
    $item = DemoItem::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user);

    $response = Livewire::test(Index::class)
        ->set('formMode', 'modal')
        ->call('openEditModal', $item->id);

    $html = $response->html();
    expect($html)->toContain('fixed inset-0 sm:inset-auto sm:relative');
});

it('renders mobile close button in create modal', function () {
    $this->actingAs($this->user);

    $response = Livewire::test(Index::class)
        ->set('formMode', 'modal')
        ->call('openCreateModal');

    $html = $response->html();
    expect($html)->toContain('aria-label="Close"');
    // Close button is specifically hidden on desktop (sm:hidden) with a close icon
    expect($html)->toMatch('/aria-label="Close".*?sm:hidden|sm:hidden.*?aria-label="Close"/s');
});
