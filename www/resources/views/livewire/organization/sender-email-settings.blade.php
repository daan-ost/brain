<div>
    <div class="p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-1">{{ __('profile.sender_email_title') }}</h3>
        <p class="text-sm text-gray-600 mb-6">{{ __('profile.sender_email_description') }}</p>

        {{-- Flash Messages --}}
        @if (session()->has('message'))
            <div class="mb-4 rounded-md bg-green-50 p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-green-800">{{ session('message') }}</p>
                    </div>
                </div>
            </div>
        @endif

        @if (session()->has('error'))
            <div class="mb-4 rounded-md bg-red-50 p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-red-800">{{ session('error') }}</p>
                    </div>
                </div>
            </div>
        @endif

        {{-- Level Selection Cards --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
            {{-- Reply-To --}}
            <button wire:click="$set('selectedLevel', 'reply_to')"
                    class="relative rounded-lg border-2 p-4 text-left transition-colors {{ $selectedLevel === 'reply_to' ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200 hover:border-gray-300' }}">
                <div class="flex items-center mb-2">
                    <svg class="h-5 w-5 text-gray-500 mr-2" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" />
                    </svg>
                    <span class="font-medium text-gray-900">{{ __('profile.sender_level_reply_to') }}</span>
                </div>
                <p class="text-sm text-gray-500">{{ __('profile.sender_level_reply_to_desc') }}</p>
                @if($selectedLevel === 'reply_to')
                    <div class="absolute top-2 right-2">
                        <svg class="h-5 w-5 text-indigo-500" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                        </svg>
                    </div>
                @endif
            </button>

            {{-- Sender Signature --}}
            <button wire:click="$set('selectedLevel', 'sender_signature')"
                    class="relative rounded-lg border-2 p-4 text-left transition-colors {{ $selectedLevel === 'sender_signature' ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200 hover:border-gray-300' }}">
                <div class="flex items-center mb-2">
                    <svg class="h-5 w-5 text-gray-500 mr-2" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                    </svg>
                    <span class="font-medium text-gray-900">{{ __('profile.sender_level_signature') }}</span>
                </div>
                <p class="text-sm text-gray-500">{{ __('profile.sender_level_signature_desc') }}</p>
                @if($selectedLevel === 'sender_signature')
                    <div class="absolute top-2 right-2">
                        <svg class="h-5 w-5 text-indigo-500" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                        </svg>
                    </div>
                @endif
            </button>

            {{-- Domain Authentication --}}
            <button wire:click="$set('selectedLevel', 'domain_auth')"
                    class="relative rounded-lg border-2 p-4 text-left transition-colors {{ $selectedLevel === 'domain_auth' ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200 hover:border-gray-300' }}">
                <div class="flex items-center mb-2">
                    <svg class="h-5 w-5 text-gray-500 mr-2" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418" />
                    </svg>
                    <span class="font-medium text-gray-900">{{ __('profile.sender_level_domain') }}</span>
                </div>
                <p class="text-sm text-gray-500">{{ __('profile.sender_level_domain_desc') }}</p>
                @if($selectedLevel === 'domain_auth')
                    <div class="absolute top-2 right-2">
                        <svg class="h-5 w-5 text-indigo-500" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                        </svg>
                    </div>
                @endif
            </button>
        </div>

        {{-- Level-specific forms --}}
        <div class="border-t pt-6">
            {{-- Reply-To Form --}}
            @if($selectedLevel === 'reply_to')
                <form wire:submit="saveReplyTo"
                      @if($config && $config->isUsable() && $config->sender_level !== \App\Enums\SenderLevel::ReplyTo)
                          x-data x-ref="replyToForm"
                          @submit.prevent="if(confirm('{{ __('profile.sender_level_switch_confirm') }}')) $wire.saveReplyTo()"
                      @endif
                      class="space-y-4">
                    <div>
                        <label for="replyToEmail" class="block text-sm font-medium text-gray-700">{{ __('profile.email') }}</label>
                        <input type="email" id="replyToEmail" wire:model.blur="replyToEmail"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                               placeholder="info@yourcompany.com">
                        @error('replyToEmail') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="fromName" class="block text-sm font-medium text-gray-700">{{ __('profile.name') }}</label>
                        <input type="text" id="fromName" wire:model.blur="fromName"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                               placeholder="Your Company Name">
                        @error('fromName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                            {{ __('profile.save') }}
                        </button>
                    </div>
                </form>
            @endif

            {{-- Sender Signature Form --}}
            @if($selectedLevel === 'sender_signature')
                @if($config && $config->sender_level === \App\Enums\SenderLevel::SenderSignature)
                    {{-- Compact: config exists, show next step --}}
                    @if($config->status === \App\Enums\SenderConfigStatus::PendingVerification)
                        <div class="rounded-md bg-blue-50 p-4">
                            <p class="text-sm text-blue-700">{{ __('profile.sender_signature_next_step_verify') }}</p>
                        </div>
                    @elseif($config->status === \App\Enums\SenderConfigStatus::Verified)
                        <div class="rounded-md bg-green-50 p-4">
                            <p class="text-sm text-green-700">{{ __('profile.sender_signature_active') }}</p>
                        </div>
                    @endif
                @else
                    {{-- Full form: no config yet --}}
                    <div class="mb-4 rounded-md bg-yellow-50 p-4">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm text-yellow-700">{{ __('profile.sender_no_free_email') }}</p>
                            </div>
                        </div>
                    </div>

                    <ol class="mb-6 space-y-2 text-sm text-gray-600 list-decimal list-inside">
                        <li>{{ __('profile.sender_signature_step_1') }}</li>
                        <li>{{ __('profile.sender_signature_step_2') }}</li>
                        <li>{{ __('profile.sender_signature_step_3') }}</li>
                    </ol>

                    <form wire:submit="saveSenderSignature"
                          @if($config && $config->isUsable() && $config->sender_level !== \App\Enums\SenderLevel::SenderSignature)
                              x-data
                              @submit.prevent="if(confirm('{{ __('profile.sender_level_switch_confirm') }}')) $wire.saveSenderSignature()"
                          @endif
                          class="space-y-4">
                        <div>
                            <label for="fromEmail" class="block text-sm font-medium text-gray-700">{{ __('profile.email') }}</label>
                            <input type="email" id="fromEmail" wire:model.blur="fromEmail"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                   placeholder="info@yourcompany.com">
                            @error('fromEmail') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="fromName" class="block text-sm font-medium text-gray-700">{{ __('profile.name') }}</label>
                            <input type="text" id="fromName" wire:model.blur="fromName"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                   placeholder="Your Company Name">
                            @error('fromName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                                {{ __('profile.save') }}
                            </button>
                        </div>
                    </form>
                @endif
            @endif

            {{-- Domain Authentication Form --}}
            @if($selectedLevel === 'domain_auth')
                @if($config && $config->sender_level === \App\Enums\SenderLevel::DomainAuth)
                    {{-- Compact: config exists, show next step --}}
                    @if($config->status === \App\Enums\SenderConfigStatus::PendingVerification)
                        <div class="rounded-md bg-blue-50 p-4">
                            <p class="text-sm text-blue-700">{{ __('profile.sender_domain_next_step_dns') }}</p>
                        </div>
                    @elseif($config->status === \App\Enums\SenderConfigStatus::Verified)
                        <div class="rounded-md bg-green-50 p-4">
                            <p class="text-sm text-green-700">{{ __('profile.sender_domain_active') }}</p>
                        </div>
                    @endif
                @else
                    {{-- Full form: no config yet --}}
                    <div class="mb-4 rounded-md bg-blue-50 p-4">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm text-blue-700">{{ __('profile.sender_dns_required') }}</p>
                            </div>
                        </div>
                    </div>

                    <ol class="mb-6 space-y-2 text-sm text-gray-600 list-decimal list-inside">
                        <li>{{ __('profile.sender_domain_step_1') }}</li>
                        <li>{{ __('profile.sender_domain_step_2') }}</li>
                        <li>{{ __('profile.sender_domain_step_3') }}</li>
                        <li>{{ __('profile.sender_domain_step_4') }}</li>
                    </ol>

                    <form wire:submit="saveDomainAuth"
                          @if($config && $config->isUsable() && $config->sender_level !== \App\Enums\SenderLevel::DomainAuth)
                              x-data
                              @submit.prevent="if(confirm('{{ __('profile.sender_level_switch_confirm') }}')) $wire.saveDomainAuth()"
                          @endif
                          class="space-y-4">
                        <div>
                            <label for="domain" class="block text-sm font-medium text-gray-700">{{ __('profile.domain_name') }}</label>
                            <input type="text" id="domain" wire:model.blur="domain"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                   placeholder="yourcompany.com">
                            @error('domain') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="fromEmail" class="block text-sm font-medium text-gray-700">{{ __('profile.sender_from_email') }}</label>
                            <input type="email" id="fromEmail" wire:model.blur="fromEmail"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                   placeholder="noreply@yourcompany.com">
                            @error('fromEmail') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                                {{ __('profile.save') }}
                            </button>
                        </div>
                    </form>
                @endif
            @endif
        </div>

        {{-- Current Config Status --}}
        @if($config)
            <div class="mt-8 border-t pt-6">
                <h4 class="text-sm font-semibold text-gray-900 mb-4">{{ __('profile.sender_current_config') }}</h4>

                <div class="bg-gray-50 rounded-lg p-4 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">{{ __('profile.sender_level') }}</span>
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                            {{ $config->sender_level->badgeColor() === 'success' ? 'bg-green-100 text-green-800' : '' }}
                            {{ $config->sender_level->badgeColor() === 'warning' ? 'bg-yellow-100 text-yellow-800' : '' }}
                            {{ $config->sender_level->badgeColor() === 'secondary' ? 'bg-gray-100 text-gray-800' : '' }}">
                            {{ $config->sender_level->label() }}
                        </span>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">{{ __('profile.status') }}</span>
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                            {{ $config->status->badgeColor() === 'success' ? 'bg-green-100 text-green-800' : '' }}
                            {{ $config->status->badgeColor() === 'warning' ? 'bg-yellow-100 text-yellow-800' : '' }}
                            {{ $config->status->badgeColor() === 'danger' ? 'bg-red-100 text-red-800' : '' }}">
                            {{ $config->status->label() }}
                        </span>
                    </div>

                    @if($config->from_email)
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600">{{ __('profile.sender_from_email') }}</span>
                            <span class="text-sm text-gray-900">{{ $config->from_email }}</span>
                        </div>
                    @endif

                    @if($config->reply_to_email)
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600">{{ __('profile.sender_reply_to_label') }}</span>
                            <span class="text-sm text-gray-900">{{ $config->reply_to_email }}</span>
                        </div>
                    @endif

                    @if($config->domain)
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600">{{ __('profile.domain_name') }}</span>
                            <span class="text-sm text-gray-900">{{ $config->domain }}</span>
                        </div>
                    @endif

                    @if($config->verified_at)
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600">{{ __('profile.verified') }}</span>
                            <span class="text-sm text-gray-900">{{ $config->verified_at->format('d M Y H:i') }}</span>
                        </div>
                    @endif
                </div>

                {{-- DNS Records Table (Domain Auth) --}}
                @if($config->sender_level === \App\Enums\SenderLevel::DomainAuth && $config->dns_records)
                    <div class="mt-4">
                        <h5 class="text-sm font-medium text-gray-900 mb-2">{{ __('profile.sender_dns_records') }}</h5>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Value</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    @foreach($config->dns_records as $record)
                                        <tr>
                                            <td class="px-3 py-2 font-mono">{{ $record['type'] ?? '-' }}</td>
                                            <td class="px-3 py-2 font-mono text-xs break-all">{{ $record['name'] ?? '-' }}</td>
                                            <td class="px-3 py-2">
                                                <div class="flex items-center gap-2" x-data="{ copied: false, value: @js($record['value'] ?? '') }">
                                                    <span class="font-mono text-xs break-all">{{ $record['value'] ?? '-' }}</span>
                                                    <button type="button"
                                                            x-on:click="navigator.clipboard.writeText(value); copied = true; setTimeout(() => copied = false, 2000)"
                                                            class="flex-shrink-0 text-gray-400 hover:text-gray-600">
                                                        <span x-show="!copied">
                                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9.75a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184" />
                                                            </svg>
                                                        </span>
                                                        <span x-show="copied" x-cloak class="text-green-500 text-xs">{{ __('profile.copied') }}</span>
                                                    </button>
                                                </div>
                                            </td>
                                            <td class="px-3 py-2">
                                                @if($record['verified'] ?? false)
                                                    <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">{{ __('profile.verified') }}</span>
                                                @else
                                                    <span class="inline-flex items-center rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-800">{{ __('profile.pending') }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                {{-- Action Buttons --}}
                <div class="mt-4 flex gap-3">
                    @if($config->status === \App\Enums\SenderConfigStatus::PendingVerification)
                        <button wire:click="checkVerification" type="button"
                                class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                            {{ $config->sender_level === \App\Enums\SenderLevel::DomainAuth ? __('profile.sender_check_dns') : __('profile.sender_check_verification') }}
                        </button>

                        @if($config->sender_level === \App\Enums\SenderLevel::SenderSignature)
                            <button wire:click="resendVerification" type="button"
                                    class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                                {{ __('profile.sender_resend_verification') }}
                            </button>
                        @endif
                    @endif

                    <button wire:click="remove" wire:confirm="{{ __('profile.sender_remove_confirm') }}" type="button"
                            class="inline-flex items-center px-4 py-2 bg-white border border-red-300 rounded-md font-semibold text-xs text-red-700 uppercase tracking-widest shadow-sm hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150">
                        {{ __('profile.sender_remove') }}
                    </button>
                </div>
            </div>
        @endif
    </div>
</div>
