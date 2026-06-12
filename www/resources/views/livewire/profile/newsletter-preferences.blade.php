<div class="space-y-6">
    {{-- Header --}}
    <div>
        <h3 class="text-lg font-medium text-gray-900">{{ __('newsletter.title') }}</h3>
        <p class="mt-1 text-sm text-gray-600">
            {{ __('newsletter.description') }}
        </p>
    </div>

    {{-- Main Settings Card --}}
    <div class="bg-white shadow sm:rounded-lg">
        <div class="px-4 py-5 sm:p-6">
            {{-- Enable/Disable Toggle --}}
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <h4 class="text-base font-semibold text-gray-900">{{ __('newsletter.receive_newsletter') }}</h4>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('newsletter.receive_description') }}
                    </p>
                </div>
                <button
                    type="button"
                    wire:click="toggleSubscription"
                    class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2 {{ $subscribed ? 'bg-blue-600' : 'bg-gray-200' }}"
                    role="switch"
                    aria-checked="{{ $subscribed ? 'true' : 'false' }}"
                >
                    <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $subscribed ? 'translate-x-5' : 'translate-x-0' }}"></span>
                </button>
            </div>

            {{-- Additional Info --}}
            <div class="mt-4 text-sm text-gray-500">
                @if($subscribed)
                    <p>{{ __('newsletter.subscribed_info') }}</p>
                @else
                    <p>{{ __('newsletter.unsubscribed_info') }}</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Success Message --}}
    @if(session()->has('newsletter-message'))
        <div
            x-data="{ show: true }"
            x-show="show"
            x-transition
            x-init="setTimeout(() => show = false, 3000)"
            class="fixed bottom-4 right-4 bg-green-50 p-4 rounded-md shadow-lg z-50"
        >
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-green-800">{{ session('newsletter-message') }}</p>
                </div>
            </div>
        </div>
    @endif
</div>
