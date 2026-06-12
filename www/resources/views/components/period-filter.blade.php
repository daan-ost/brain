@props(['periodTypes' => ['day', 'week', 'month', 'quarter', 'year', 'custom']])

<div class="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-3">
    {{-- Period type pills --}}
    <div class="inline-flex overflow-x-auto whitespace-nowrap rounded-lg border border-gray-200 p-0.5">
        @foreach ($periodTypes as $type)
            <button wire:click="setPeriodType('{{ $type }}')" type="button"
                class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors {{ $this->periodType === $type ? 'bg-emerald-500 text-white' : 'text-gray-600 hover:bg-gray-50' }}">
                {{ ucfirst($type) }}
            </button>
        @endforeach
    </div>

    {{-- Navigation arrows + label (hidden in custom mode) --}}
    @if ($this->periodType !== 'custom')
        <div class="inline-flex items-center gap-1">
            <button wire:click="previousPeriod" type="button" aria-label="Previous period"
                class="rounded-md border border-gray-200 p-1.5 text-gray-500 hover:bg-gray-50 hover:text-gray-700 transition-colors">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </button>
            <span class="min-w-24 px-2 text-center text-sm font-medium text-gray-700">{{ $this->getPeriodLabel() }}</span>
            <button wire:click="nextPeriod" type="button" aria-label="Next period"
                class="rounded-md border border-gray-200 p-1.5 text-gray-500 hover:bg-gray-50 hover:text-gray-700 transition-colors">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </button>
        </div>
    @endif

    {{-- Custom date inputs --}}
    @if ($this->periodType === 'custom')
        <div class="flex flex-col sm:flex-row sm:items-center gap-2">
            <input type="date" wire:model.live="dateFrom" aria-label="From date"
                class="w-full sm:w-auto rounded-lg border-gray-200 bg-gray-50 py-1.5 px-3 text-sm focus:border-emerald-500 focus:bg-white focus:ring-emerald-500">
            <span class="hidden sm:inline text-sm text-gray-400">&mdash;</span>
            <input type="date" wire:model.live="dateTo" aria-label="To date"
                class="w-full sm:w-auto rounded-lg border-gray-200 bg-gray-50 py-1.5 px-3 text-sm focus:border-emerald-500 focus:bg-white focus:ring-emerald-500">
        </div>
    @endif
</div>
