<div>
    {{-- Flash message --}}
    @if (session()->has('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-4 text-sm text-green-700" role="alert">
            {{ session('message') }}
        </div>
    @endif

    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Demo Items</h1>
            <p class="mt-1 text-sm text-gray-500">Manage your demo items — a complete CRUD example.</p>
        </div>
        @if($formMode === 'modal')
            <button wire:click="openCreateModal" class="inline-flex items-center rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 transition-colors">
                <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Item
            </button>
        @else
            <a href="{{ route('demo-items.create') }}" class="inline-flex items-center rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 transition-colors">
                <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Item
            </a>
        @endif
    </div>

    {{-- Summary Cards --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-lg bg-white p-4 shadow-sm border border-gray-200">
            <p class="text-sm text-gray-500">Total Items</p>
            <p class="text-2xl font-bold text-gray-900">{{ $this->summary['total'] }}</p>
        </div>
        <div class="rounded-lg bg-white p-4 shadow-sm border border-gray-200">
            <p class="text-sm text-gray-500">Active</p>
            <p class="text-2xl font-bold text-emerald-600">{{ $this->summary['active'] }}</p>
        </div>
        <div class="rounded-lg bg-white p-4 shadow-sm border border-gray-200">
            <p class="text-sm text-gray-500">Overdue</p>
            <p class="text-2xl font-bold text-red-600">{{ $this->summary['overdue'] }}</p>
        </div>
        <div class="rounded-lg bg-white p-4 shadow-sm border border-gray-200">
            <p class="text-sm text-gray-500">Total Amount</p>
            <p class="text-2xl font-bold text-gray-900">&euro;{{ number_format($this->summary['total_amount'], 2) }}</p>
        </div>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-3">
        {{-- Search --}}
        <div class="relative flex-1 min-w-[200px]">
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search by title..."
                class="block w-full rounded-lg border-gray-200 bg-gray-50 py-2.5 pl-10 pr-4 text-sm focus:border-emerald-500 focus:bg-white focus:ring-emerald-500">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            </div>
        </div>

        {{-- Status filter --}}
        <select wire:model.live="statusFilter" class="w-full sm:w-auto sm:max-w-[180px] rounded-lg border-gray-200 bg-gray-50 py-2.5 px-4 text-sm focus:border-emerald-500 focus:bg-white focus:ring-emerald-500">
            <option value="">All Statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </select>

        {{-- Priority filter --}}
        <select wire:model.live="priorityFilter" class="w-full sm:w-auto sm:max-w-[180px] rounded-lg border-gray-200 bg-gray-50 py-2.5 px-4 text-sm focus:border-emerald-500 focus:bg-white focus:ring-emerald-500">
            <option value="">All Priorities</option>
            @foreach($priorities as $priority)
                <option value="{{ $priority->value }}">{{ $priority->label() }}</option>
            @endforeach
        </select>
    </div>

    {{-- Period filter --}}
    <div class="mb-4">
        <x-period-filter :periodTypes="['month', 'quarter', 'year', 'custom']" />
    </div>

    {{-- Mobile Cards --}}
    <div class="sm:hidden space-y-3">
        @forelse($items as $item)
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4" wire:key="item-mobile-{{ $item->id }}">
                <div class="flex items-start justify-between mb-2">
                    <div class="flex items-center gap-2">
                        <input type="checkbox" wire:model.live="selected" value="{{ $item->id }}"
                            class="rounded border-gray-300 text-emerald-600 shadow-sm focus:ring-emerald-500">
                        <a href="{{ route('demo-items.show', $item) }}" class="font-medium text-emerald-600 hover:text-emerald-800">
                            {{ $item->title }}
                        </a>
                    </div>
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                        @switch($item->status->badgeColor())
                            @case('success') bg-green-100 text-green-800 @break
                            @case('info') bg-blue-100 text-blue-800 @break
                            @case('danger') bg-red-100 text-red-800 @break
                            @default bg-gray-100 text-gray-800
                        @endswitch
                    ">
                        {{ $item->status->label() }}
                    </span>
                </div>
                <div class="text-sm text-gray-500 space-y-1 ml-6">
                    <div class="flex items-center justify-between">
                        <span>Priority:
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                @switch($item->priority->badgeColor())
                                    @case('warning') bg-yellow-100 text-yellow-800 @break
                                    @case('danger') bg-red-100 text-red-800 @break
                                    @case('info') bg-blue-100 text-blue-800 @break
                                    @default bg-gray-100 text-gray-800
                                @endswitch
                            ">{{ $item->priority->label() }}</span>
                        </span>
                        <span class="text-gray-700 font-medium">&euro;{{ number_format($item->amount, 2) }}</span>
                    </div>
                    <div>Due:
                        @if($item->due_date)
                            <span class="{{ $item->due_date->isPast() && !in_array($item->status->value, ['completed', 'cancelled']) ? 'text-red-600 font-medium' : '' }}">
                                {{ $item->due_date->format('d M Y') }}
                            </span>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </div>
                    <div>Created: {{ $item->created_at->format('d M Y') }}</div>
                </div>
                <div class="flex items-center gap-3 mt-3 ml-6 pt-2 border-t border-gray-100">
                    @if($formMode === 'modal')
                        <button wire:click="openEditModal('{{ $item->id }}')" class="text-sm text-gray-600 hover:text-emerald-600">Edit</button>
                    @else
                        <a href="{{ route('demo-items.edit', $item) }}" class="text-sm text-gray-600 hover:text-emerald-600">Edit</a>
                    @endif
                    <button wire:click="deleteSingle('{{ $item->id }}')" wire:confirm="Are you sure you want to delete this item?" class="text-sm text-gray-600 hover:text-red-600">Delete</button>
                </div>
            </div>
        @empty
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-8 text-center">
                <div class="text-gray-400">
                    <svg class="mx-auto h-12 w-12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                    <p class="mt-2 text-sm font-medium">No demo items found</p>
                    <p class="mt-1 text-xs">Create your first item to get started.</p>
                </div>
            </div>
        @endforelse

        @if($items->hasPages())
            <div class="mt-4">
                {{ $items->links() }}
            </div>
        @endif
    </div>

    {{-- Desktop Table --}}
    <div class="hidden sm:block overflow-hidden rounded-lg bg-white shadow-sm border border-gray-200">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                    <tr>
                        <th scope="col" class="px-5 py-3 w-10">
                            <input type="checkbox" wire:model.live="selectAll"
                                class="rounded border-gray-300 text-emerald-600 shadow-sm focus:ring-emerald-500">
                        </th>
                        <x-sortable-th column="title" label="Title" />
                        <x-sortable-th column="status" label="Status" />
                        <x-sortable-th column="priority" label="Priority" />
                        <x-sortable-th column="amount" label="Amount" />
                        <x-sortable-th column="due_date" label="Due Date" />
                        <x-sortable-th column="created_at" label="Created" />
                        <th scope="col" class="px-5 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @forelse($items as $item)
                        <tr class="hover:bg-gray-50" wire:key="item-{{ $item->id }}">
                            <td class="px-5 py-3">
                                <input type="checkbox" wire:model.live="selected" value="{{ $item->id }}"
                                    class="rounded border-gray-300 text-emerald-600 shadow-sm focus:ring-emerald-500">
                            </td>
                            <td class="px-5 py-3">
                                <a href="{{ route('demo-items.show', $item) }}" class="font-medium text-emerald-600 hover:text-emerald-800">
                                    {{ $item->title }}
                                </a>
                            </td>
                            <td class="px-5 py-3">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                    @switch($item->status->badgeColor())
                                        @case('success') bg-green-100 text-green-800 @break
                                        @case('info') bg-blue-100 text-blue-800 @break
                                        @case('danger') bg-red-100 text-red-800 @break
                                        @default bg-gray-100 text-gray-800
                                    @endswitch
                                ">
                                    {{ $item->status->label() }}
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                    @switch($item->priority->badgeColor())
                                        @case('warning') bg-yellow-100 text-yellow-800 @break
                                        @case('danger') bg-red-100 text-red-800 @break
                                        @case('info') bg-blue-100 text-blue-800 @break
                                        @default bg-gray-100 text-gray-800
                                    @endswitch
                                ">
                                    {{ $item->priority->label() }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-sm text-gray-700">
                                &euro;{{ number_format($item->amount, 2) }}
                            </td>
                            <td class="px-5 py-3 text-sm text-gray-700">
                                @if($item->due_date)
                                    <span class="{{ $item->due_date->isPast() && !in_array($item->status->value, ['completed', 'cancelled']) ? 'text-red-600 font-medium' : '' }}">
                                        {{ $item->due_date->format('d M Y') }}
                                    </span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-sm text-gray-500">
                                {{ $item->created_at->format('d M Y') }}
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-2">
                                    @if($formMode === 'modal')
                                        <button wire:click="openEditModal('{{ $item->id }}')" class="text-sm text-gray-600 hover:text-emerald-600">Edit</button>
                                    @else
                                        <a href="{{ route('demo-items.edit', $item) }}" class="text-sm text-gray-600 hover:text-emerald-600">Edit</a>
                                    @endif
                                    <button wire:click="deleteSingle('{{ $item->id }}')" wire:confirm="Are you sure you want to delete this item?" class="text-sm text-gray-600 hover:text-red-600">Delete</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-5 py-12 text-center">
                                <div class="text-gray-400">
                                    <svg class="mx-auto h-12 w-12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                                    <p class="mt-2 text-sm font-medium">No demo items found</p>
                                    <p class="mt-1 text-xs">Create your first item to get started.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($items->hasPages())
            <div class="border-t border-gray-200 px-5 py-3">
                {{ $items->links() }}
            </div>
        @endif
    </div>

    {{-- Bulk Action Bar --}}
    <x-bulk-action-bar>
        <button wire:click="bulkDelete" wire:confirm="Are you sure you want to delete the selected items?"
            class="text-sm font-medium text-red-600 hover:text-red-800 transition-colors">
            Delete
        </button>
        <button wire:click="bulkTransition('active')"
            class="text-sm font-medium text-emerald-600 hover:text-emerald-800 transition-colors">
            Set Active
        </button>
        <button wire:click="bulkTransition('completed')"
            class="text-sm font-medium text-blue-600 hover:text-blue-800 transition-colors">
            Set Completed
        </button>
    </x-bulk-action-bar>

    {{-- Create Modal --}}
    @if($formMode === 'modal' && $showCreateModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex min-h-full items-end justify-center text-center sm:items-center sm:p-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" wire:click="$set('showCreateModal', false)"></div>
                <div class="fixed inset-0 sm:inset-auto sm:relative transform overflow-hidden sm:rounded-lg bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                    <div class="flex h-full flex-col sm:block">
                        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 sm:border-0 sm:px-6 sm:pt-5 sm:pb-0">
                            <h3 class="text-lg font-semibold text-gray-900">Create Demo Item</h3>
                            <button wire:click="$set('showCreateModal', false)" class="sm:hidden rounded-lg p-1 text-gray-400 hover:text-gray-600" aria-label="Close">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                        <div class="flex-1 overflow-y-auto px-4 pb-4 pt-4 sm:px-6 sm:pb-6">
                            @livewire('demo-items.create-form', ['isModal' => true], key('create-modal'))
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Edit Modal --}}
    @if($formMode === 'modal' && $showEditModal && $editingItemId)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex min-h-full items-end justify-center text-center sm:items-center sm:p-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" wire:click="$set('showEditModal', false)"></div>
                <div class="fixed inset-0 sm:inset-auto sm:relative transform overflow-hidden sm:rounded-lg bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                    <div class="flex h-full flex-col sm:block">
                        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 sm:border-0 sm:px-6 sm:pt-5 sm:pb-0">
                            <h3 class="text-lg font-semibold text-gray-900">Edit Demo Item</h3>
                            <button wire:click="$set('showEditModal', false)" class="sm:hidden rounded-lg p-1 text-gray-400 hover:text-gray-600" aria-label="Close">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                        <div class="flex-1 overflow-y-auto px-4 pb-4 pt-4 sm:px-6 sm:pb-6">
                            @livewire('demo-items.edit-form', ['demoItem' => \App\Models\DemoItem::findOrFail($editingItemId), 'isModal' => true], key('edit-modal-'.$editingItemId))
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
