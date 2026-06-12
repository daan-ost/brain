@props([])

<div x-data x-show="$wire.selected.length > 0"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="translate-y-4 opacity-0"
     x-transition:enter-end="translate-y-0 opacity-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="translate-y-0 opacity-100"
     x-transition:leave-end="translate-y-4 opacity-0"
     role="status"
     aria-live="polite"
     class="fixed bottom-6 left-1/2 z-40 -translate-x-1/2 rounded-xl border border-gray-200 bg-white px-6 py-3 shadow-2xl"
     style="display: none;">
    <div class="flex items-center gap-4">
        <span class="text-sm font-medium text-gray-700">
            <span x-text="$wire.selected.length"></span> selected
        </span>
        <div class="h-5 w-px bg-gray-200" aria-hidden="true"></div>
        {{ $slot }}
        <div class="h-5 w-px bg-gray-200" aria-hidden="true"></div>
        <button wire:click="deselectAll" type="button" aria-label="Cancel" class="text-sm text-gray-500 hover:text-gray-700 transition-colors">
            Cancel
        </button>
    </div>
</div>
