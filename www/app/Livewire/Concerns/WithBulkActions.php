<?php

namespace App\Livewire\Concerns;

trait WithBulkActions
{
    /** Maximum IDs to load for "Select All" to prevent memory issues with large datasets. */
    protected int $selectableLimit = 1000;

    public array $selected = [];

    public bool $selectAll = false;

    public function updatedSelectAll(bool $value): void
    {
        $this->selected = $value ? $this->getSelectableIds() : [];
    }

    public function updatedSelected(): void
    {
        // Don't call getSelectableIds() here — with 200k rows it loads all IDs into memory.
        // Simply turn off selectAll when individual checkboxes change.
        $this->selectAll = false;
    }

    public function getSelectedCount(): int
    {
        return count($this->selected);
    }

    public function deselectAll(): void
    {
        $this->resetBulkSelection();
    }

    public function resetBulkSelection(): void
    {
        $this->selected = [];
        $this->selectAll = false;
    }

    abstract public function getSelectableIds(): array;
}
