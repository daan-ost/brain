<div class="space-y-3">
    {{-- Mode tabs --}}
    <div class="flex flex-wrap gap-1">
        @foreach (['day' => 'Dag', 'week' => 'Week', 'month' => 'Maand', 'year' => 'Jaar', 'custom' => 'Custom'] as $key => $label)
            <button
                wire:click="$set('mode', '{{ $key }}')"
                type="button"
                @class([
                    'px-3 py-1.5 text-sm font-medium rounded-lg transition-colors duration-150',
                    'bg-primary-500 text-white shadow-sm' => $mode === $key,
                    'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5' => $mode !== $key,
                ])
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Navigation + label --}}
    @if ($mode !== 'custom')
        <div class="flex items-center justify-center gap-4">
            <button
                wire:click="previous"
                type="button"
                class="p-1.5 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 transition-colors"
                title="Vorige"
            >
                <x-heroicon-m-chevron-left class="w-5 h-5" />
            </button>

            <span class="text-sm font-medium text-gray-700 dark:text-gray-200 min-w-[180px] text-center">
                {{ $this->periodLabel }}
            </span>

            <button
                wire:click="next"
                type="button"
                @class([
                    'p-1.5 rounded-lg transition-colors',
                    'text-gray-300 dark:text-gray-600 cursor-not-allowed' => $this->isNextDisabled,
                    'text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5' => ! $this->isNextDisabled,
                ])
                @if ($this->isNextDisabled) disabled @endif
                title="Volgende"
            >
                <x-heroicon-m-chevron-right class="w-5 h-5" />
            </button>
        </div>
    @else
        {{-- Custom date range inputs --}}
        <div class="flex flex-wrap items-center gap-3">
            <div class="flex items-center gap-2">
                <label for="custom-start" class="text-sm text-gray-600 dark:text-gray-400">Van</label>
                <input
                    wire:model.live.debounce.500ms="customStart"
                    type="date"
                    id="custom-start"
                    class="block rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500"
                    max="{{ now()->format('Y-m-d') }}"
                />
            </div>
            <div class="flex items-center gap-2">
                <label for="custom-end" class="text-sm text-gray-600 dark:text-gray-400">Tot</label>
                <input
                    wire:model.live.debounce.500ms="customEnd"
                    type="date"
                    id="custom-end"
                    class="block rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500"
                    max="{{ now()->format('Y-m-d') }}"
                />
            </div>
        </div>

        @if ($validationError)
            <p class="text-sm text-danger-600 dark:text-danger-400">{{ $validationError }}</p>
        @endif
    @endif
</div>
