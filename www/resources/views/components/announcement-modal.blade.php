@props(['announcement' => null])

@if($announcement)
@php
    $urgencyColors = [
        'info' => [
            'border' => 'border-blue-500',
            'bg' => 'bg-blue-50',
            'badge' => 'bg-blue-100 text-blue-800',
            'button' => 'bg-blue-600 hover:bg-blue-700',
        ],
        'warning' => [
            'border' => 'border-orange-500',
            'bg' => 'bg-orange-50',
            'badge' => 'bg-orange-100 text-orange-800',
            'button' => 'bg-orange-600 hover:bg-orange-700',
        ],
        'update' => [
            'border' => 'border-green-500',
            'bg' => 'bg-green-50',
            'badge' => 'bg-green-100 text-green-800',
            'button' => 'bg-green-600 hover:bg-green-700',
        ],
    ];
    $colors = $urgencyColors[$announcement['urgency']] ?? $urgencyColors['info'];
@endphp

<div
    x-data="{
        show: true,
        dismissing: false,
        async dismiss() {
            if (this.dismissing) return;
            this.dismissing = true;

            try {
                const response = await fetch('{{ route('announcements.dismiss') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        announcement_id: {{ $announcement['id'] }}
                    })
                });

                if (response.ok) {
                    this.show = false;
                    document.body.classList.remove('overflow-y-hidden');
                }
            } catch (error) {
                console.error('Failed to dismiss announcement:', error);
                // Still close the modal on error
                this.show = false;
                document.body.classList.remove('overflow-y-hidden');
            }
        }
    }"
    x-init="document.body.classList.add('overflow-y-hidden')"
    x-on:keydown.escape.window="dismiss()"
    x-show="show"
    x-cloak
    class="fixed inset-0 overflow-y-auto px-4 py-6 sm:px-0 z-50"
    role="dialog"
    aria-modal="true"
    aria-labelledby="announcement-title"
>
    {{-- Backdrop --}}
    <div
        x-show="show"
        x-on:click="dismiss()"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 bg-gray-500/75 transition-opacity"
    ></div>

    {{-- Modal Panel --}}
    <div class="flex min-h-full items-center justify-center p-4">
        <div
            x-show="show"
            x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave="ease-in duration-200"
            x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            class="relative transform overflow-hidden rounded-lg bg-white shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-xl border-t-4 {{ $colors['border'] }}"
        >
            {{-- Content --}}
            <div class="px-8 pt-8 pb-4 {{ $colors['bg'] }} relative">
                {{-- Close button --}}
                <button
                    type="button"
                    x-on:click="dismiss()"
                    class="absolute top-2 right-0 text-gray-400 hover:text-gray-600 transition-colors z-10"
                    aria-label="{{ __('Close') }}"
                >
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
                {{-- Urgency badge --}}
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $colors['badge'] }} mb-3">
                    @if($announcement['urgency'] === 'info')
                        <svg class="mr-1 h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                        </svg>
                        {{ __('Info') }}
                    @elseif($announcement['urgency'] === 'warning')
                        <svg class="mr-1 h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                        {{ __('Warning') }}
                    @else
                        <svg class="mr-1 h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                        </svg>
                        {{ __('Update') }}
                    @endif
                </span>

                {{-- Title --}}
                <h2 id="announcement-title" class="text-xl font-semibold text-gray-900 pr-8">
                    {{ $announcement['title'] }}
                </h2>
            </div>

            {{-- Body --}}
            {{-- Note: announcement body is admin-only content from Filament panel --}}
            {{-- Consider adding HTMLPurifier for additional sanitization in high-security environments --}}
            <div class="px-8 py-5">
                <div class="prose prose-base max-w-none text-gray-700">
                    {!! $announcement['body'] !!}
                </div>
            </div>

            {{-- Footer with buttons --}}
            <div class="px-8 py-5 bg-gray-50 flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
                <button
                    type="button"
                    x-on:click="dismiss()"
                    class="w-full sm:w-auto inline-flex justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors"
                >
                    {{ __('Close') }}
                </button>

                @if($announcement['has_cta'] && $announcement['cta_url'])
                    <a
                        href="{{ $announcement['cta_url'] }}"
                        x-on:click="dismiss()"
                        class="w-full sm:w-auto inline-flex justify-center rounded-md px-4 py-2 text-sm font-medium text-white shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 transition-colors {{ $colors['button'] }}"
                    >
                        {{ $announcement['cta_label'] ?? __('Learn more') }}
                    </a>
                @endif
            </div>
        </div>
    </div>
</div>
@endif
