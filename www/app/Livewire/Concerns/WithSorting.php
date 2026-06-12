<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\Url;

trait WithSorting
{
    #[Url(as: 'sort')]
    public string $sortBy = '';

    #[Url(as: 'dir')]
    public string $sortDirection = '';

    public function mountWithSorting(): void
    {
        if ($this->sortBy === '' || ! in_array($this->sortBy, $this->allowedSortColumns(), true)) {
            $this->sortBy = $this->defaultSortColumn();
        }

        if ($this->sortDirection === '' || ! in_array($this->sortDirection, ['asc', 'desc'], true)) {
            $this->sortDirection = $this->defaultSortDirection();
        }
    }

    public function sort(string $column): void
    {
        if (! in_array($column, $this->allowedSortColumns(), true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    public function applySorting($query): mixed
    {
        return $query->orderBy($this->sortBy, $this->sortDirection);
    }

    /** @return string[] */
    abstract protected function allowedSortColumns(): array;

    abstract protected function defaultSortColumn(): string;

    protected function defaultSortDirection(): string
    {
        return 'asc';
    }
}
