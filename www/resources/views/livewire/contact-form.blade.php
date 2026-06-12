<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
    @if($submitted)
        {{-- Success state --}}
        <div class="text-center py-8">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-green-100 mb-4">
                <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            <h3 class="text-lg font-medium text-gray-900 mb-2">{{ __('contact.thank_you') }}</h3>
            <p class="text-sm text-gray-600 mb-4">{{ __('contact.message_sent_description') }}</p>
            <a href="{{ route('profile.messages.show', $threadId) }}"
               class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                {{ __('contact.view_conversation') }}
            </a>
        </div>
    @else
        {{-- Form --}}
        @guest
            <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                <p class="text-sm text-yellow-800">
                    {{ __('contact.login_to_send') }}
                    <a href="{{ route('login') }}" class="font-medium underline hover:text-yellow-900">{{ __('common.login') }}</a>
                    {{ __('common.or') }}
                    <a href="{{ route('register') }}" class="font-medium underline hover:text-yellow-900">{{ __('common.register') }}</a>
                </p>
            </div>
        @endguest

        <form wire:submit="submit">
            {{-- Category --}}
            <div class="mb-4">
                <label for="category" class="block text-sm font-medium text-gray-700 mb-1">
                    {{ __('contact.category') }} <span class="text-red-500">*</span>
                </label>
                <select
                    wire:model="category"
                    id="category"
                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    @guest disabled @endguest
                >
                    <option value="">{{ __('contact.select_category') }}</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
                @error('category')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Subject --}}
            <div class="mb-4">
                <label for="subject" class="block text-sm font-medium text-gray-700 mb-1">
                    {{ __('contact.subject') }} <span class="text-red-500">*</span>
                </label>
                <input
                    wire:model="subject"
                    type="text"
                    id="subject"
                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    placeholder="{{ __('contact.subject_placeholder') }}"
                    maxlength="200"
                    @guest disabled @endguest
                >
                @error('subject')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Message --}}
            <div class="mb-6">
                <label for="message" class="block text-sm font-medium text-gray-700 mb-1">
                    {{ __('contact.message') }} <span class="text-red-500">*</span>
                </label>
                <textarea
                    wire:model="message"
                    id="message"
                    rows="5"
                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    placeholder="{{ __('contact.message_placeholder') }}"
                    maxlength="2000"
                    @guest disabled @endguest
                ></textarea>
                @error('message')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
                <p class="mt-1 text-xs text-gray-500">{{ __('contact.max_characters', ['count' => 2000]) }}</p>
            </div>

            {{-- Submit --}}
            <div class="flex items-center justify-end">
                <button
                    type="submit"
                    class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150 disabled:opacity-50 disabled:cursor-not-allowed"
                    wire:loading.attr="disabled"
                    @guest disabled @endguest
                >
                    <span wire:loading.remove>{{ __('contact.send_message') }}</span>
                    <span wire:loading class="flex items-center">
                        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        {{ __('contact.sending') }}
                    </span>
                </button>
            </div>
        </form>
    @endif
</div>
