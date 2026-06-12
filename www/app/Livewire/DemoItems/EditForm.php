<?php

namespace App\Livewire\DemoItems;

use App\Enums\DemoItemPriority;
use App\Enums\DemoItemStatus;
use App\Models\DemoItem;
use App\Services\DemoItemService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class EditForm extends Component
{
    public DemoItem $demoItem;

    public string $title = '';

    public string $description = '';

    public string $status = '';

    public string $priority = '';

    public string $amount = '0';

    public ?string $dueDate = null;

    public bool $isModal = false;

    public function mount(DemoItem $demoItem): void
    {
        $this->authorize('update', $demoItem);
        $this->demoItem = $demoItem;
        $this->title = $demoItem->title;
        $this->description = $demoItem->description ?? '';
        $this->status = $demoItem->status->value;
        $this->priority = $demoItem->priority->value;
        $this->amount = (string) $demoItem->amount;
        $this->dueDate = $demoItem->due_date?->format('Y-m-d');
    }

    public function rules(): array
    {
        return [
            'title' => 'required|max:255',
            'description' => 'nullable|max:5000',
            'status' => 'required|in:'.implode(',', array_column(DemoItemStatus::cases(), 'value')),
            'priority' => 'required|in:'.implode(',', array_column(DemoItemPriority::cases(), 'value')),
            'amount' => 'numeric|min:0',
            'dueDate' => 'nullable|date',
        ];
    }

    public function save(): void
    {
        $validated = $this->validate();

        app(DemoItemService::class)->update($this->demoItem, [
            'title' => $validated['title'],
            'description' => $validated['description'],
            'status' => $validated['status'],
            'priority' => $validated['priority'],
            'amount' => $validated['amount'],
            'due_date' => $validated['dueDate'],
        ]);

        if ($this->isModal) {
            $this->dispatch('demo-item-updated');
        } else {
            session()->flash('message', 'Demo item updated.');
            $this->redirect(route('demo-items.index'), navigate: true);
        }
    }

    public function render()
    {
        return view('livewire.demo-items.edit-form', [
            'statuses' => DemoItemStatus::cases(),
            'priorities' => DemoItemPriority::cases(),
        ]);
    }
}
