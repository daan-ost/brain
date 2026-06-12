<div class="space-y-6">
    {{-- Header --}}
    <div>
        <h3 class="text-lg font-medium text-gray-900">{{ __('inbound.title') }}</h3>
        <p class="mt-1 text-sm text-gray-600">
            {{ __('inbound.description') }}
        </p>
    </div>

    {{-- Main Settings Card --}}
    <div class="bg-white shadow sm:rounded-lg">
        <div class="px-4 py-5 sm:p-6">
            {{-- Feature Disabled Warning --}}
            @if(!$featureEnabled)
                <div class="mb-4 p-4 bg-yellow-50 border border-yellow-200 rounded-md">
                    <p class="text-sm text-yellow-800">
                        {{ __('inbound.feature_disabled') }}
                    </p>
                </div>
            @endif

            {{-- Enable/Disable Toggle --}}
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <h4 class="text-base font-semibold text-gray-900">{{ __('inbound.enable_inbound') }}</h4>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('inbound.enable_description') }}
                    </p>
                </div>
                <button
                    type="button"
                    wire:click="toggleInbound"
                    @if(!$featureEnabled) disabled @endif
                    class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2 {{ $inboundEnabled ? 'bg-blue-600' : 'bg-gray-200' }} {{ !$featureEnabled ? 'opacity-50 cursor-not-allowed' : '' }}"
                    role="switch"
                    aria-checked="{{ $inboundEnabled ? 'true' : 'false' }}"
                >
                    <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $inboundEnabled ? 'translate-x-5' : 'translate-x-0' }}"></span>
                </button>
            </div>

            {{-- Email Addresses (shown when enabled) --}}
            @if($inboundEnabled && !empty($actions))
                <div class="mt-6 border-t border-gray-200 pt-6">
                    <h4 class="text-sm font-semibold text-gray-900 mb-4">{{ __('inbound.your_email_addresses') }}</h4>
                    <div class="space-y-3">
                        @foreach($actions as $action => $details)
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-md">
                                <div class="flex-1 min-w-0 mr-3">
                                    <p class="text-sm font-medium text-gray-900 mb-1">
                                        {{ ucfirst($action) }}
                                    </p>
                                    <p class="text-xs text-gray-600 mb-2">
                                        {{ $details['description'] }}
                                    </p>
                                    <code class="text-xs text-gray-700 bg-white px-2 py-1 rounded border border-gray-200 break-all">
                                        {{ $details['email'] }}
                                    </code>
                                </div>
                                <button
                                    type="button"
                                    onclick="navigator.clipboard.writeText('{{ $details['email'] }}'); this.textContent = '{{ __('inbound.copied') }}'; setTimeout(() => this.innerHTML = '<svg class=\'h-4 w-4\' fill=\'none\' viewBox=\'0 0 24 24\' stroke=\'currentColor\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z\' /></svg>', 2000)"
                                    class="flex-shrink-0 inline-flex items-center justify-center rounded-md bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                >
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                    </svg>
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Advanced Options Toggle --}}
                <div class="mt-6 border-t border-gray-200 pt-4">
                    <button
                        type="button"
                        wire:click="toggleAdvanced"
                        class="flex items-center text-sm font-medium text-gray-700 hover:text-gray-900"
                    >
                        <svg class="h-5 w-5 mr-2 transform transition-transform {{ $showAdvanced ? 'rotate-90' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                        {{ __('inbound.advanced_options') }}
                    </button>

                    @if($showAdvanced)
                        <div class="mt-4 pl-7 space-y-4">
                            {{-- Verify Sender Toggle --}}
                            <div class="flex items-start justify-between">
                                <div class="flex-1 mr-4">
                                    <h5 class="text-sm font-medium text-gray-900">{{ __('inbound.verify_sender') }}</h5>
                                    <p class="mt-1 text-xs text-gray-600">
                                        {{ __('inbound.verify_sender_description') }}
                                    </p>
                                    <p class="mt-1 text-xs text-amber-600">
                                        <strong>{{ __('inbound.security_warning') }}:</strong> {{ __('inbound.verify_sender_warning') }}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    wire:click="toggleVerifySender"
                                    class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2 {{ $verifySender ? 'bg-blue-600' : 'bg-gray-200' }}"
                                    role="switch"
                                    aria-checked="{{ $verifySender ? 'true' : 'false' }}"
                                >
                                    <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $verifySender ? 'translate-x-5' : 'translate-x-0' }}"></span>
                                </button>
                            </div>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>

    {{-- Recent Inbound Emails History --}}
    @if($inboundEnabled && !empty($recentEmails))
        <div class="bg-white shadow sm:rounded-lg">
            <div class="px-4 py-5 sm:p-6">
                <h4 class="text-base font-semibold text-gray-900 mb-4">{{ __('inbound.recent_emails') }}</h4>
                {{-- Mobile Cards --}}
                <div class="sm:hidden space-y-3">
                    @foreach($recentEmails as $email)
                        <div class="bg-gray-50 rounded-lg border border-gray-200 p-4">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm font-medium text-gray-900 truncate max-w-[200px]" title="{{ $email['subject'] }}">
                                    {{ $email['subject'] ?? '-' }}
                                </span>
                                @switch($email['status'])
                                    @case('processed')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            {{ __('inbound.status_processed') }}
                                        </span>
                                        @break
                                    @case('processing')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                            {{ __('inbound.status_processing') }}
                                        </span>
                                        @break
                                    @case('received')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                            {{ __('inbound.status_received') }}
                                        </span>
                                        @break
                                    @case('failed')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                            {{ __('inbound.status_failed') }}
                                        </span>
                                        @break
                                    @case('virus_detected')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                            {{ __('inbound.status_virus_detected') }}
                                        </span>
                                        @break
                                    @case('bounced')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                            {{ __('inbound.status_bounced') }}
                                        </span>
                                        @break
                                    @default
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                            {{ $email['status'] }}
                                        </span>
                                @endswitch
                            </div>
                            <div class="text-sm text-gray-500 space-y-1">
                                <div>{{ __('inbound.action') }}: {{ ucfirst($email['action'] ?? '-') }}</div>
                                <div>{{ __('inbound.date') }}: {{ $email['date'] }}</div>
                                @if($email['status'] === 'processed' && $email['days_remaining'] !== null)
                                    <div class="text-xs text-gray-400">
                                        ({{ trans_choice('inbound.days_remaining', $email['days_remaining'], ['days' => $email['days_remaining']]) }})
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Desktop Table --}}
                <div class="hidden sm:block overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('inbound.date') }}</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('inbound.subject') }}</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('inbound.action') }}</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('inbound.status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($recentEmails as $email)
                                <tr>
                                    <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $email['date'] }}
                                    </td>
                                    <td class="px-3 py-4 text-sm text-gray-900 max-w-xs truncate" title="{{ $email['subject'] }}">
                                        {{ $email['subject'] ?? '-' }}
                                    </td>
                                    <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ ucfirst($email['action'] ?? '-') }}
                                    </td>
                                    <td class="px-3 py-4 whitespace-nowrap">
                                        @switch($email['status'])
                                            @case('processed')
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                    {{ __('inbound.status_processed') }}
                                                </span>
                                                @if($email['days_remaining'] !== null)
                                                    <span class="ml-1 text-xs text-gray-500">
                                                        ({{ trans_choice('inbound.days_remaining', $email['days_remaining'], ['days' => $email['days_remaining']]) }})
                                                    </span>
                                                @endif
                                                @break
                                            @case('processing')
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                    {{ __('inbound.status_processing') }}
                                                </span>
                                                @break
                                            @case('received')
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                                    {{ __('inbound.status_received') }}
                                                </span>
                                                @break
                                            @case('failed')
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                    {{ __('inbound.status_failed') }}
                                                </span>
                                                @break
                                            @case('virus_detected')
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                    {{ __('inbound.status_virus_detected') }}
                                                </span>
                                                @break
                                            @case('bounced')
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                                    {{ __('inbound.status_bounced') }}
                                                </span>
                                                @break
                                            @default
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                                    {{ $email['status'] }}
                                                </span>
                                        @endswitch
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- Success Message --}}
    @if(session()->has('inbound-message'))
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
                    <p class="text-sm font-medium text-green-800">{{ session('inbound-message') }}</p>
                </div>
            </div>
        </div>
    @endif
</div>
