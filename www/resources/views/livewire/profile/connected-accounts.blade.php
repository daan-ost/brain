<div>
    @if (! $googleEnabled && ! $user->hasGoogleLinked())
        {{-- Google OAuth niet geconfigureerd op deze site én user heeft geen
             bestaande koppeling — niets te tonen. --}}
    @else
    <section class="space-y-4">
        <header>
            <h2 class="text-lg font-medium text-gray-900">
                {{ __('profile.connected_accounts') }}
            </h2>
            <p class="mt-1 text-sm text-gray-600">
                {{ __('profile.connected_accounts_subtitle') }}
            </p>
        </header>

        @if (session('status') === 'google-disconnected')
            <div role="status" class="rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
                {{ __('profile.google_disconnected_status') }}
            </div>
        @endif

        <div class="rounded-lg border border-gray-200 p-4 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <svg class="w-6 h-6 flex-shrink-0" viewBox="0 0 24 24" aria-hidden="true">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                </svg>
                <div>
                    <p class="font-medium text-gray-900">Google</p>
                    @if ($user->hasGoogleLinked())
                        <p class="text-sm text-green-700">{{ __('profile.google_connected') }}</p>
                    @else
                        <p class="text-sm text-gray-500">{{ __('profile.google_not_connected') }}</p>
                    @endif
                </div>
            </div>

            <div class="flex items-center gap-2">
                @if ($user->hasGoogleLinked())
                    @if ($user->hasPassword())
                        <button type="button"
                                wire:click="disconnectGoogle"
                                wire:confirm="{{ __('profile.disconnect_google_confirm') }}"
                                class="px-4 py-2 text-sm font-medium text-red-700 border border-red-200 rounded-lg hover:bg-red-50 transition-colors">
                            {{ __('profile.disconnect_google') }}
                        </button>
                    @else
                        <span class="text-xs text-amber-700 max-w-[14rem] text-right">
                            {{ __('profile.disconnect_google_no_password') }}
                        </span>
                    @endif
                @elseif ($googleEnabled)
                    <a href="{{ route('auth.google') }}"
                       class="px-4 py-2 text-sm font-medium text-white bg-[#2A73E8] rounded-lg hover:bg-[#1f5fc4] transition-colors">
                        {{ __('profile.connect_google') }}
                    </a>
                @endif
            </div>
        </div>

        @error('google')
            <p role="alert" class="text-sm text-red-600">{{ $message }}</p>
        @enderror
    </section>
    @endif
</div>
