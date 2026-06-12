@auth
    @if (!auth()->user()->hasVerifiedEmail())
    <div x-data="{ show: !sessionStorage.getItem('hideVerificationBanner') }"
         x-show="show"
         x-transition
         class="bg-yellow-50 border-b border-yellow-200">
        <div class="max-w-7xl mx-auto py-3 px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between flex-wrap">
                <div class="w-0 flex-1 flex items-center">
                    <span class="flex p-2 rounded-lg bg-yellow-100">
                        <svg class="h-6 w-6 text-yellow-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </span>
                    <p class="ml-3 font-medium text-yellow-800 text-sm">
                        {{ __('messages.email_verification_required') }}
                        <a href="{{ route('verification.notice') }}" class="underline hover:text-yellow-900 ml-2">
                            {{ __('messages.verify_now') }}
                        </a>
                    </p>
                </div>
                <div class="order-2 flex-shrink-0 sm:order-3 sm:ml-3">
                    <button @click="show = false; sessionStorage.setItem('hideVerificationBanner', 'true')"
                            type="button"
                            class="-mr-1 flex p-2 rounded-md hover:bg-yellow-100 focus:outline-none focus:ring-2 focus:ring-yellow-600 sm:-mr-2">
                        <span class="sr-only">{{ __('messages.dismiss') }}</span>
                        <svg class="h-6 w-6 text-yellow-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif
@endauth
