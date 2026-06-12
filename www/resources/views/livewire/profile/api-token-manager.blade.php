<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h3 class="text-lg font-medium text-gray-900">{{ __('profile.api_tokens') }}</h3>
            <p class="mt-1 text-sm text-gray-600">
                {{ __('profile.api_tokens_description') }}
            </p>
        </div>
    </div>

    {{-- Create Token Form --}}
    <div class="bg-white shadow sm:rounded-lg">
        <div class="px-4 py-5 sm:p-6">
            <h4 class="text-base font-semibold text-gray-900 mb-4">{{ __('profile.create_new_token') }}</h4>

            @if(auth()->user()->isFreeTier())
                {{-- Free tier restriction --}}
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-400">
                            {{ __('profile.token_name') }}
                        </label>
                        <div class="mt-1">
                            <input
                                type="text"
                                disabled
                                class="block w-full rounded-md border-gray-200 bg-gray-50 shadow-sm sm:text-sm text-gray-400 cursor-not-allowed"
                                placeholder="{{ __('profile.token_name_placeholder') }}"
                            >
                        </div>
                    </div>

                    <div>
                        <button
                            type="button"
                            disabled
                            class="inline-flex items-center rounded-md bg-gray-300 px-3 py-2 text-sm font-semibold text-gray-500 shadow-sm cursor-not-allowed"
                        >
                            {{ __('profile.create_token') }}
                        </button>
                    </div>

                    <div class="mt-3 p-3 bg-amber-50 border border-amber-200 rounded-md">
                        <p class="text-sm text-amber-800">
                            {{ __('common.upgrade_required') }}
                            <a href="{{ url('/pricing') }}" class="font-medium text-amber-900 underline hover:text-amber-700">
                                {{ __('common.view_pricing') }}
                            </a>
                        </p>
                    </div>
                </div>
            @else
                <form wire:submit="createToken" class="space-y-4">
                    <div>
                        <label for="tokenName" class="block text-sm font-medium text-gray-700">
                            {{ __('profile.token_name') }}
                        </label>
                        <div class="mt-1">
                            <input
                                type="text"
                                id="tokenName"
                                wire:model="tokenName"
                                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                placeholder="{{ __('profile.token_name_placeholder') }}"
                            >
                        </div>
                        @error('tokenName')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <button
                            type="submit"
                            class="inline-flex items-center rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                        >
                            {{ __('profile.create_token') }}
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </div>

    {{-- Token List --}}
    <div class="bg-white shadow sm:rounded-lg">
        <div class="px-4 py-5 sm:p-6">
            <h4 class="text-base font-semibold text-gray-900 mb-4">{{ __('profile.active_tokens') }}</h4>

            @if($tokens->isEmpty())
                <div class="text-center py-6">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                    </svg>
                    <h3 class="mt-2 text-sm font-medium text-gray-900">{{ __('profile.no_tokens') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __('profile.no_tokens_description') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <ul role="list" class="divide-y divide-gray-200">
                        @foreach($tokens as $token)
                            <li class="flex items-center justify-between py-4">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900 truncate">
                                        {{ $token->name }}
                                    </p>
                                    <p class="text-sm text-gray-500">
                                        {{ __('profile.created') }}: {{ $token->created_at->format('M d, Y') }}
                                        @if($token->last_used_at)
                                            • {{ __('profile.last_used') }}: {{ $token->last_used_at->diffForHumans() }}
                                        @else
                                            • {{ __('profile.never_used') }}
                                        @endif
                                    </p>
                                </div>
                                <div class="ml-4 flex-shrink-0">
                                    <button
                                        wire:click="revokeToken({{ $token->id }})"
                                        wire:confirm="{{ __('profile.revoke_token_confirm') }}"
                                        class="inline-flex items-center rounded-md bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/20 hover:bg-red-100"
                                    >
                                        {{ __('profile.revoke') }}
                                    </button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>

    {{-- New Token Modal --}}
    @if($showTokenModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" x-data="{ show: true }">
            <div class="flex min-h-screen items-center justify-center p-4">
                {{-- Backdrop --}}
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" @click="$wire.closeTokenModal()"></div>

                {{-- Modal --}}
                <div class="relative bg-white rounded-lg shadow-xl max-w-2xl w-full p-6">
                    <div class="sm:flex sm:items-start">
                        <div class="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-green-100 sm:mx-0 sm:h-10 sm:w-10">
                            <svg class="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left flex-1">
                            <h3 class="text-lg font-semibold leading-6 text-gray-900">
                                {{ __('profile.token_created_successfully') }}
                            </h3>
                            <div class="mt-4">
                                <p class="text-sm text-gray-500 mb-3">
                                    {{ __('profile.token_copy_warning') }}
                                </p>

                                <div class="bg-gray-50 rounded-md p-4">
                                    <div class="flex items-center justify-between">
                                        <code class="text-sm font-mono text-gray-900 break-all">{{ $newTokenValue }}</code>
                                        <button
                                            type="button"
                                            onclick="navigator.clipboard.writeText('{{ $newTokenValue }}'); this.textContent = '{{ __('profile.copied') }}'; setTimeout(() => this.textContent = '{{ __('profile.copy') }}', 2000)"
                                            class="ml-4 flex-shrink-0 inline-flex items-center rounded-md bg-white px-2.5 py-1.5 text-xs font-medium text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                        >
                                            {{ __('profile.copy') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse">
                        <button
                            type="button"
                            wire:click="closeTokenModal"
                            class="inline-flex w-full justify-center rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 sm:ml-3 sm:w-auto"
                        >
                            {{ __('profile.done') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Success Messages --}}
    <div
        x-data="{ show: false }"
        x-on:token-created.window="show = true; setTimeout(() => show = false, 3000)"
        x-show="show"
        x-transition
        class="fixed bottom-4 right-4 bg-green-50 p-4 rounded-md shadow-lg"
        style="display: none;"
    >
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                </svg>
            </div>
            <div class="ml-3">
                <p class="text-sm font-medium text-green-800">{{ __('profile.token_created_message') }}</p>
            </div>
        </div>
    </div>

    <div
        x-data="{ show: false }"
        x-on:token-revoked.window="show = true; setTimeout(() => show = false, 3000)"
        x-show="show"
        x-transition
        class="fixed bottom-4 right-4 bg-red-50 p-4 rounded-md shadow-lg"
        style="display: none;"
    >
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                </svg>
            </div>
            <div class="ml-3">
                <p class="text-sm font-medium text-red-800">{{ __('profile.token_revoked_message') }}</p>
            </div>
        </div>
    </div>
</div>
