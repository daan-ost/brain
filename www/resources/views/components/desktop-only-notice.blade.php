@props([
    'title',
    'description',
    'hint' => null,
])

{{-- Mobile: desktop-only message --}}
<div class="sm:hidden p-6 text-center" role="status">
    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25m18 0A2.25 2.25 0 0 0 18.75 3H5.25A2.25 2.25 0 0 0 3 5.25m18 0V12a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 12V5.25" />
    </svg>
    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mt-3">{{ $title }}</h3>
    <p class="text-gray-500 dark:text-gray-400 mt-1">{{ $description }}</p>
    @if ($hint)
        <p class="text-sm text-gray-400 dark:text-gray-500 mt-3">{{ $hint }}</p>
    @endif
</div>

{{-- Desktop: actual content --}}
<div class="hidden sm:block">
    {{ $slot }}
</div>
