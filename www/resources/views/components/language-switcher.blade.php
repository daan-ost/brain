@php
    $currentLocale = app()->getLocale();
    $availableLocales = [
        'en' => ['name' => 'English', 'code' => 'EN'],
        'nl' => ['name' => 'Nederlands', 'code' => 'NL'],
    ];
@endphp

<div class="relative" x-data="{ langOpen: false }">
    <button
        @click="langOpen = !langOpen"
        class="flex items-center gap-2 px-3 py-2 text-white hover:text-white/80 transition-colors rounded-lg hover:bg-white/10"
    >
        <!-- Globe icon -->
        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <span class="font-medium text-[15px]">{{ strtoupper($currentLocale) }}</span>
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
        </svg>
    </button>

    <div
        x-show="langOpen"
        @click.away="langOpen = false"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        class="absolute right-0 mt-2 w-40 bg-white rounded-lg shadow-xl border border-gray-200 py-1 z-50"
        style="display: none;"
    >
        @foreach($availableLocales as $locale => $data)
            @if($locale !== $currentLocale)
                <a
                    href="{{ route('language.switch', ['locale' => $locale, 'redirect' => url()->full()]) }}"
                    class="flex items-center gap-3 px-4 py-2 text-gray-700 hover:bg-gray-50 transition-colors"
                >
                    <span class="font-semibold text-gray-500 text-sm w-6">{{ $data['code'] }}</span>
                    <span class="font-medium">{{ $data['name'] }}</span>
                </a>
            @endif
        @endforeach
    </div>
</div>
