@props([
    'options' => [],
    'placeholder' => '',
    'valueField' => 'id',
    'textField' => 'name',
])

@php
    $wireModel = $attributes->wire('model')->value();
@endphp

<div x-data="{
        open: false,
        search: '',
        highlightIndex: -1,
        options: @js($options->map(fn($o) => ['value' => data_get($o, $valueField), 'text' => data_get($o, $textField)])->values()->all()),
        get filtered() {
            if (this.search === '') return this.options;
            const s = this.search.toLowerCase();
            return this.options.filter(o => o.text.toLowerCase().includes(s));
        },
        select(value) {
            @this.set('{{ $wireModel }}', value || null);
            this.search = '';
            this.open = false;
        },
        get selectedText() {
            const val = @this.get('{{ $wireModel }}');
            if (!val && val !== 0) return '';
            const opt = this.options.find(o => String(o.value) === String(val));
            return opt ? opt.text : '';
        },
        get selectedValue() {
            return @this.get('{{ $wireModel }}');
        }
    }"
    class="relative"
    @click.outside="open = false"
    @keydown.escape.prevent="open = false"
>
    <div class="relative">
        <input
            type="text"
            x-model.debounce.150ms="search"
            @focus="open = true; highlightIndex = -1"
            @click="open = true"
            :placeholder="selectedText || '{{ $placeholder }}'"
            role="combobox"
            :aria-expanded="open"
            aria-haspopup="listbox"
            aria-autocomplete="list"
            class="block w-full rounded-lg border-gray-200 bg-gray-50 py-2.5 px-4 text-sm focus:border-emerald-500 focus:bg-white focus:ring-emerald-500"
            @keydown.arrow-down.prevent="highlightIndex = Math.min(highlightIndex + 1, filtered.length - 1)"
            @keydown.arrow-up.prevent="highlightIndex = Math.max(highlightIndex - 1, -1)"
            @keydown.enter.prevent="if (highlightIndex >= 0 && filtered[highlightIndex]) { select(filtered[highlightIndex].value) }"
            autocomplete="off"
        >
        <button type="button" @click="select(null)" x-show="@this.get('{{ $wireModel }}')" aria-label="Clear selection" class="absolute inset-y-0 right-8 flex items-center pr-1">
            <svg class="h-4 w-4 text-gray-400 hover:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
            <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
        </div>
    </div>
    <div x-show="open && filtered.length > 0"
         x-transition
         role="listbox"
         class="absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-gray-200 bg-white py-1 shadow-xl"
         style="display: none;">
        <button type="button"
                role="option"
                :aria-selected="!selectedValue && selectedValue !== 0"
                @click="select(null)"
                class="block w-full px-4 py-2 text-left text-sm text-gray-400 hover:bg-gray-50">
            {{ $placeholder }}
        </button>
        <template x-for="(option, index) in filtered" :key="option.value">
            <button type="button"
                    role="option"
                    :aria-selected="String(option.value) === String(selectedValue)"
                    @click="select(option.value)"
                    @mouseenter="highlightIndex = index"
                    :class="{ 'bg-emerald-50 text-emerald-700': highlightIndex === index, 'text-gray-700': highlightIndex !== index }"
                    class="block w-full px-4 py-2 text-left text-sm hover:bg-gray-50"
                    x-text="option.text">
            </button>
        </template>
    </div>
</div>
