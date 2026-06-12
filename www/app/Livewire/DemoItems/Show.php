<?php

namespace App\Livewire\DemoItems;

use App\Enums\DemoItemStatus;
use App\Models\DemoItem;
use App\Services\DemoItemService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    public DemoItem $demoItem;

    public function mount(DemoItem $demoItem): void
    {
        $this->authorize('view', $demoItem);
        $this->demoItem = $demoItem;
    }

    public function transitionTo(string $status): void
    {
        $newStatus = DemoItemStatus::from($status);
        app(DemoItemService::class)->transitionStatus($this->demoItem, $newStatus);
        $this->demoItem = $this->demoItem->fresh();
        session()->flash('message', "Status changed to {$newStatus->label()}.");
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->demoItem);
        app(DemoItemService::class)->delete($this->demoItem);
        session()->flash('message', 'Item deleted.');
        $this->redirect(route('demo-items.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.demo-items.show');
    }
}
