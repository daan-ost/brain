@props(['column', 'label', 'class' => ''])

@php
    $isActive = $this->sortBy === $column;
    $ariaSortValue = $isActive
        ? ($this->sortDirection === 'asc' ? 'ascending' : 'descending')
        : 'none';
@endphp

<th scope="col" class="px-5 py-3 {{ $class }}" aria-sort="{{ $ariaSortValue }}">
    <button type="button" wire:click="sort('{{ $column }}')" class="group inline-flex items-center gap-1 cursor-pointer select-none">
        {{ $label }}
        @if($isActive)
            @if($this->sortDirection === 'asc')
                <svg class="h-3.5 w-3.5 text-emerald-500" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M14.707 12.707a1 1 0 01-1.414 0L10 9.414l-3.293 3.293a1 1 0 01-1.414-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 010 1.414z" clip-rule="evenodd" />
                </svg>
            @else
                <svg class="h-3.5 w-3.5 text-emerald-500" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                </svg>
            @endif
        @else
            <svg class="h-3.5 w-3.5 text-gray-300 group-hover:text-gray-400" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M10 3a.75.75 0 01.55.24l3.25 3.5a.75.75 0 11-1.1 1.02L10 4.852 7.3 7.76a.75.75 0 01-1.1-1.02l3.25-3.5A.75.75 0 0110 3zm-3.76 9.2a.75.75 0 011.06.04l2.7 2.908 2.7-2.908a.75.75 0 111.1 1.02l-3.25 3.5a.75.75 0 01-1.1 0l-3.25-3.5a.75.75 0 01.04-1.06z" clip-rule="evenodd" />
            </svg>
        @endif
    </button>
</th>
