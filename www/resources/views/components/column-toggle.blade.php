@props(['columns' => [], 'storageKey' => 'bw_default'])

<div x-data="{
        open: false,
        columns: @js($columns),
        storageKey: '{{ $storageKey }}_columns',
        get visibleCount() {
            return this.columns.filter(c => c.visible).length;
        },
        init() {
            const stored = localStorage.getItem(this.storageKey);
            if (!stored) return;

            try {
                const parsed = JSON.parse(stored);
                this.columns = this.columns.map(col => ({
                    ...col,
                    visible: col.key in parsed ? parsed[col.key] : col.visible,
                }));
            } catch {
                localStorage.removeItem(this.storageKey);
            }
        },
        toggle(key) {
            const col = this.columns.find(c => c.key === key);
            if (!col) return;
            if (col.visible && this.visibleCount <= 1) return;
            col.visible = !col.visible;
            this.save();
        },
        save() {
            const state = {};
            this.columns.forEach(c => state[c.key] = c.visible);
            localStorage.setItem(this.storageKey, JSON.stringify(state));
        },
        isVisible(key) {
            const col = this.columns.find(c => c.key === key);
            return col ? col.visible : true;
        },
        isDisabled(col) {
            return col.visible && this.visibleCount <= 1;
        }
    }"
    @click.outside="open = false"
    @keydown.escape="open = false"
    class="relative inline-block"
>
    <button @click="open = !open" type="button"
        :aria-expanded="open"
        class="rounded-lg border border-gray-200 p-2 text-gray-400 hover:bg-gray-50 hover:text-gray-600 transition-colors"
        title="Toggle columns">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
    </button>
    <div x-show="open" x-transition
         role="menu"
         class="absolute right-0 z-50 mt-2 w-56 rounded-lg border border-gray-200 bg-white py-2 shadow-xl"
         style="display: none;">
        <p class="px-4 pb-2 text-xs font-medium uppercase tracking-wider text-gray-400">Visible columns</p>
        <template x-for="col in columns" :key="col.key">
            <label :class="{ 'opacity-50 cursor-not-allowed': isDisabled(col) }" class="flex items-center gap-2 px-4 py-1.5 text-sm text-gray-700 hover:bg-gray-50 cursor-pointer">
                <input type="checkbox" :checked="col.visible" @change="toggle(col.key)"
                       :disabled="isDisabled(col)"
                       class="rounded border-gray-300 text-emerald-600 shadow-sm focus:ring-emerald-500">
                <span x-text="col.label"></span>
            </label>
        </template>
    </div>
</div>
