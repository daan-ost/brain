<div>
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-xl font-semibold text-gray-900">{{ __('webhooks.title') }}</h2>
            <p class="mt-1 text-sm text-gray-600">{{ __('webhooks.description') }}</p>
        </div>
        <button wire:click="openCreateModal"
                class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
            {{ __('webhooks.add_webhook') }}
        </button>
    </div>

    <!-- Messages -->
    @if($successMessage)
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md">
            <p class="text-sm text-green-700">{{ $successMessage }}</p>
        </div>
    @endif

    @if($errorMessage)
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
            <p class="text-sm text-red-700">{{ $errorMessage }}</p>
        </div>
    @endif

    <!-- Webhooks List -->
    @if(count($webhooks) === 0)
        <div class="text-center py-12 bg-gray-50 rounded-lg">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">{{ __('webhooks.no_webhooks') }}</h3>
            <p class="mt-1 text-sm text-gray-500">{{ __('webhooks.no_webhooks_description') }}</p>
            <div class="mt-6">
                <button wire:click="openCreateModal"
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700">
                    {{ __('webhooks.add_first_webhook') }}
                </button>
            </div>
        </div>
    @else
        <div class="space-y-4">
            @foreach($webhooks as $webhook)
                <div class="bg-white border rounded-lg p-4 {{ $webhook['is_active'] ? '' : 'opacity-60' }}">
                    <div class="flex items-start justify-between">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <!-- Status indicator -->
                                <span class="flex-shrink-0 w-2.5 h-2.5 rounded-full {{ $webhook['is_active'] ? 'bg-green-500' : 'bg-gray-400' }}"></span>
                                <!-- Description or URL -->
                                <h3 class="text-sm font-medium text-gray-900 truncate">
                                    {{ $webhook['description'] ?: parse_url($webhook['url'], PHP_URL_HOST) }}
                                </h3>
                                @if(!$webhook['is_active'])
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">
                                        {{ __('webhooks.inactive') }}
                                    </span>
                                @endif
                                @if($webhook['failure_count'] >= 3)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800">
                                        {{ __('webhooks.failing') }}
                                    </span>
                                @endif
                            </div>
                            <p class="mt-1 text-sm text-gray-500 truncate">{{ $webhook['url'] }}</p>
                            <div class="mt-2 flex flex-wrap gap-1">
                                @foreach($webhook['events'] as $event)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-700">
                                        {{ $event }}
                                    </span>
                                @endforeach
                            </div>
                            @if($webhook['last_triggered_at'])
                                <p class="mt-2 text-xs text-gray-400">
                                    {{ __('webhooks.last_triggered') }}: {{ \Carbon\Carbon::parse($webhook['last_triggered_at'])->diffForHumans() }}
                                    @if($webhook['last_response_code'])
                                        <span class="{{ $webhook['last_response_code'] >= 200 && $webhook['last_response_code'] < 300 ? 'text-green-600' : 'text-red-600' }}">
                                            ({{ $webhook['last_response_code'] }})
                                        </span>
                                    @endif
                                </p>
                            @endif
                        </div>
                        <div class="flex items-center gap-2 ml-4">
                            <button wire:click="showDeliveries({{ $webhook['id'] }})"
                                    class="text-gray-400 hover:text-gray-600" title="{{ __('webhooks.view_deliveries') }}">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                </svg>
                            </button>
                            <button wire:click="sendTest({{ $webhook['id'] }})"
                                    class="text-gray-400 hover:text-indigo-600" title="{{ __('webhooks.send_test') }}"
                                    {{ !$webhook['is_active'] ? 'disabled' : '' }}>
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </button>
                            <button wire:click="openEditModal({{ $webhook['id'] }})"
                                    class="text-gray-400 hover:text-gray-600" title="{{ __('webhooks.edit') }}">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                </svg>
                            </button>
                            <button wire:click="delete({{ $webhook['id'] }})"
                                    wire:confirm="{{ __('webhooks.delete_confirm') }}"
                                    class="text-gray-400 hover:text-red-600" title="{{ __('webhooks.delete') }}">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <p class="mt-4 text-sm text-gray-500">
            {{ __('webhooks.usage_count', ['count' => count($webhooks), 'max' => $maxWebhooks]) }}
        </p>
    @endif

    <!-- Create/Edit Modal -->
    @if($showModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" wire:click="closeModal"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
                <div class="inline-block align-bottom bg-white rounded-lg px-4 pt-5 pb-4 text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full sm:p-6">
                    <div>
                        <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">
                            {{ $editingWebhookId ? __('webhooks.edit_webhook') : __('webhooks.add_webhook') }}
                        </h3>
                        <form wire:submit="save" class="mt-4 space-y-4">
                            <!-- URL -->
                            <div>
                                <label for="url" class="block text-sm font-medium text-gray-700">{{ __('webhooks.endpoint_url') }} *</label>
                                <input type="url" wire:model="url" id="url"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                       placeholder="https://example.com/webhooks/yourapp">
                                @error('url') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                            </div>

                            <!-- Description -->
                            <div>
                                <label for="description" class="block text-sm font-medium text-gray-700">{{ __('webhooks.description_label') }}</label>
                                <input type="text" wire:model="description" id="description"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                       placeholder="{{ __('webhooks.description_placeholder') }}">
                            </div>

                            <!-- Secret -->
                            <div>
                                <label for="secret" class="block text-sm font-medium text-gray-700">
                                    {{ __('webhooks.secret_label') }}
                                    @if($editingWebhookId)
                                        <span class="text-gray-400 font-normal">({{ __('webhooks.leave_empty_to_keep') }})</span>
                                    @endif
                                </label>
                                <input type="password" wire:model="secret" id="secret"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                       placeholder="{{ $editingWebhookId ? '••••••••' : __('webhooks.secret_placeholder') }}">
                                <p class="mt-1 text-xs text-gray-500">{{ __('webhooks.secret_help') }}</p>
                            </div>

                            <!-- Events -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('webhooks.events_label') }} *</label>
                                <div class="space-y-2">
                                    @foreach($availableEvents as $event)
                                        <label class="flex items-start">
                                            <input type="checkbox" wire:model="events" value="{{ $event }}"
                                                   class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 mt-0.5">
                                            <span class="ml-2">
                                                <span class="text-sm text-gray-900">{{ $event }}</span>
                                                <span class="block text-xs text-gray-500">{{ __('webhooks.event_' . str_replace('.', '_', $event)) }}</span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('events') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                            </div>

                            <!-- Active toggle (only for edit) -->
                            @if($editingWebhookId)
                                <div class="flex items-center">
                                    <input type="checkbox" wire:model="isActive" id="isActive"
                                           class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                    <label for="isActive" class="ml-2 block text-sm text-gray-900">{{ __('webhooks.active') }}</label>
                                </div>
                            @endif

                            <!-- Buttons -->
                            <div class="mt-5 sm:mt-6 sm:grid sm:grid-cols-2 sm:gap-3 sm:grid-flow-row-dense">
                                <button type="submit"
                                        class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:col-start-2 sm:text-sm">
                                    {{ $editingWebhookId ? __('webhooks.save_changes') : __('webhooks.create_webhook') }}
                                </button>
                                <button type="button" wire:click="closeModal"
                                        class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:col-start-1 sm:text-sm">
                                    {{ __('webhooks.cancel') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Deliveries Modal -->
    @if($showDeliveriesModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="deliveries-modal-title" role="dialog" aria-modal="true">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" wire:click="closeDeliveriesModal"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
                <div class="inline-block align-bottom bg-white rounded-lg px-4 pt-5 pb-4 text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full sm:p-6">
                    <div>
                        <h3 class="text-lg leading-6 font-medium text-gray-900" id="deliveries-modal-title">
                            {{ __('webhooks.recent_deliveries') }}
                        </h3>
                        <div class="mt-4">
                            @if(count($selectedWebhookDeliveries) === 0)
                                <p class="text-sm text-gray-500 text-center py-8">{{ __('webhooks.no_deliveries') }}</p>
                            @else
                                {{-- Mobile Cards --}}
                                <div class="sm:hidden space-y-3">
                                    @foreach($selectedWebhookDeliveries as $delivery)
                                        <div class="bg-gray-50 rounded-lg border border-gray-200 p-3">
                                            <div class="flex items-center justify-between mb-2">
                                                <span class="text-sm font-medium text-gray-900">{{ $delivery['event'] }}</span>
                                                @if($delivery['status'] === 'success')
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">{{ __('webhooks.success') }}</span>
                                                @elseif($delivery['status'] === 'failed')
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">{{ __('webhooks.failed') }}</span>
                                                @elseif($delivery['status'] === 'retrying')
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800">{{ __('webhooks.retrying') }}</span>
                                                @else
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800">{{ __('webhooks.pending') }}</span>
                                                @endif
                                            </div>
                                            <div class="text-sm text-gray-500 space-y-1">
                                                <div>{{ __('webhooks.response') }}:
                                                    @if($delivery['response_code'])
                                                        <span class="{{ $delivery['response_code'] >= 200 && $delivery['response_code'] < 300 ? 'text-green-600' : 'text-red-600' }}">
                                                            {{ $delivery['response_code'] }}
                                                        </span>
                                                        @if($delivery['duration_ms'])
                                                            <span class="text-gray-400">({{ $delivery['duration_ms'] }}ms)</span>
                                                        @endif
                                                    @else
                                                        -
                                                    @endif
                                                </div>
                                                <div>{{ __('webhooks.time') }}: {{ $delivery['created_at'] ? \Carbon\Carbon::parse($delivery['created_at'])->diffForHumans() : '-' }}</div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                {{-- Desktop Table --}}
                                <div class="hidden sm:block overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('webhooks.event') }}</th>
                                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('webhooks.status') }}</th>
                                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('webhooks.response') }}</th>
                                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('webhooks.time') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            @foreach($selectedWebhookDeliveries as $delivery)
                                                <tr>
                                                    <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-900">{{ $delivery['event'] }}</td>
                                                    <td class="px-3 py-2 whitespace-nowrap">
                                                        @if($delivery['status'] === 'success')
                                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">{{ __('webhooks.success') }}</span>
                                                        @elseif($delivery['status'] === 'failed')
                                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">{{ __('webhooks.failed') }}</span>
                                                        @elseif($delivery['status'] === 'retrying')
                                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800">{{ __('webhooks.retrying') }}</span>
                                                        @else
                                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800">{{ __('webhooks.pending') }}</span>
                                                        @endif
                                                    </td>
                                                    <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-500">
                                                        @if($delivery['response_code'])
                                                            <span class="{{ $delivery['response_code'] >= 200 && $delivery['response_code'] < 300 ? 'text-green-600' : 'text-red-600' }}">
                                                                {{ $delivery['response_code'] }}
                                                            </span>
                                                            @if($delivery['duration_ms'])
                                                                <span class="text-gray-400">({{ $delivery['duration_ms'] }}ms)</span>
                                                            @endif
                                                        @else
                                                            -
                                                        @endif
                                                    </td>
                                                    <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-500">
                                                        {{ $delivery['created_at'] ? \Carbon\Carbon::parse($delivery['created_at'])->diffForHumans() : '-' }}
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                        <div class="mt-5">
                            <button type="button" wire:click="closeDeliveriesModal"
                                    class="w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:text-sm">
                                {{ __('webhooks.close') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
