<?php

declare(strict_types=1);

use App\Livewire\DemoItems\Show;
use App\Models\DemoItem;
use App\Models\User;
use Livewire\Livewire;

describe('DemoItems Show - Responsive Layout', function () {
    beforeEach(function () {
        config(['features.demo_crud' => true]);
        $this->user = User::factory()->create();
    });

    describe('tab bar', function () {
        it('renders tab bar with overview and details tabs', function () {
            $item = DemoItem::factory()->create(['user_id' => $this->user->id]);

            $this->actingAs($this->user);

            Livewire::test(Show::class, ['demoItem' => $item])
                ->assertOk()
                ->assertSeeHtml('role="tablist"')
                ->assertSee('Overview')
                ->assertSee('Details');
        });

        it('renders overview tab panel with status and priority', function () {
            $item = DemoItem::factory()->create(['user_id' => $this->user->id]);

            $this->actingAs($this->user);

            Livewire::test(Show::class, ['demoItem' => $item])
                ->assertOk()
                ->assertSeeHtml('id="panel-overview"')
                ->assertSeeHtml('aria-labelledby="tab-overview"')
                ->assertSee($item->status->label())
                ->assertSee($item->priority->label());
        });

        it('renders details tab panel with created date', function () {
            $item = DemoItem::factory()->create(['user_id' => $this->user->id]);

            $this->actingAs($this->user);

            Livewire::test(Show::class, ['demoItem' => $item])
                ->assertOk()
                ->assertSeeHtml('id="panel-details"')
                ->assertSeeHtml('aria-labelledby="tab-details"');
        });

        it('renders status tab when transitions are available', function () {
            $item = DemoItem::factory()->draft()->create(['user_id' => $this->user->id]);

            $this->actingAs($this->user);

            expect(count($item->status->allowedTransitions()))->toBeGreaterThan(0);

            Livewire::test(Show::class, ['demoItem' => $item])
                ->assertOk()
                ->assertSeeHtml('id="panel-status"')
                ->assertSeeHtml('aria-controls="panel-status"');
        });

        it('renders horizontally scrollable tab container for mobile', function () {
            $item = DemoItem::factory()->create(['user_id' => $this->user->id]);

            $this->actingAs($this->user);

            Livewire::test(Show::class, ['demoItem' => $item])
                ->assertOk()
                ->assertSeeHtml('overflow-x-auto')
                ->assertSeeHtml('min-w-max sm:min-w-0');
        });
    });

    describe('action buttons', function () {
        it('renders responsive action buttons that stack on mobile', function () {
            $item = DemoItem::factory()->create(['user_id' => $this->user->id]);

            $this->actingAs($this->user);

            Livewire::test(Show::class, ['demoItem' => $item])
                ->assertOk()
                ->assertSeeHtml('flex flex-col sm:flex-row gap-2')
                ->assertSeeHtml('w-full sm:w-auto');
        });
    });
});
