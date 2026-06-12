<?php

namespace App\Livewire\DemoItems;

use App\Enums\DemoItemPriority;
use App\Enums\DemoItemStatus;
use App\Livewire\Concerns\WithBulkActions;
use App\Livewire\Concerns\WithPeriodFilter;
use App\Livewire\Concerns\WithSorting;
use App\Models\DemoItem;
use App\Services\DemoItemService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use WithBulkActions, WithPagination, WithPeriodFilter, WithSorting;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $priorityFilter = '';

    public string $formMode = 'page';

    public bool $showCreateModal = false;

    public bool $showEditModal = false;

    public ?string $editingItemId = null;

    public function mount(): void
    {
        $this->formMode = config('features.demo_crud_form_mode', 'page');
    }

    protected function allowedSortColumns(): array
    {
        return ['title', 'status', 'priority', 'amount', 'due_date', 'created_at'];
    }

    protected function defaultSortColumn(): string
    {
        return 'created_at';
    }

    protected function defaultSortDirection(): string
    {
        return 'desc';
    }

    protected function periodDateColumn(): string
    {
        return 'created_at';
    }

    public function getSelectableIds(): array
    {
        return $this->getBaseQuery()
            ->limit($this->selectableLimit)
            ->pluck('id')
            ->all();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPriorityFilter(): void
    {
        $this->resetPage();
    }

    public function getSummaryProperty(): array
    {
        return app(DemoItemService::class)->getSummary(auth()->user());
    }

    private function getBaseQuery()
    {
        $query = DemoItem::forUser(auth()->id());

        if ($this->search !== '') {
            $query->where('title', 'like', '%'.$this->search.'%');
        }

        if ($this->statusFilter !== '') {
            $status = DemoItemStatus::tryFrom($this->statusFilter);
            if ($status) {
                $query->withStatus($status);
            }
        }

        if ($this->priorityFilter !== '') {
            $priority = DemoItemPriority::tryFrom($this->priorityFilter);
            if ($priority) {
                $query->withPriority($priority);
            }
        }

        $query = $this->applyPeriodFilter($query);
        $query = $this->applySorting($query);

        return $query;
    }

    public function bulkDelete(): void
    {
        $count = app(DemoItemService::class)->bulkDelete($this->selected, auth()->user());
        $this->resetBulkSelection();
        session()->flash('message', "{$count} item(s) deleted.");
    }

    public function bulkTransition(string $status): void
    {
        $newStatus = DemoItemStatus::from($status);
        $count = app(DemoItemService::class)->bulkTransition($this->selected, auth()->user(), $newStatus);
        $this->resetBulkSelection();
        session()->flash('message', "{$count} item(s) updated to {$newStatus->label()}.");
    }

    public function deleteSingle(string $id): void
    {
        $item = DemoItem::findOrFail($id);
        $this->authorize('delete', $item);
        app(DemoItemService::class)->delete($item);
        session()->flash('message', 'Item deleted.');
    }

    public function openCreateModal(): void
    {
        $this->showCreateModal = true;
    }

    public function openEditModal(string $id): void
    {
        $this->editingItemId = $id;
        $this->showEditModal = true;
    }

    public function render()
    {
        return view('livewire.demo-items.index', [
            'items' => $this->getBaseQuery()->paginate(15),
            'statuses' => DemoItemStatus::cases(),
            'priorities' => DemoItemPriority::cases(),
        ]);
    }
}
