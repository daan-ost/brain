<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Filters --}}
        <x-filament::section>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <x-filament::input.wrapper>
                        <x-filament::input
                            type="text"
                            wire:model.live.debounce.500ms="searchQuery"
                            placeholder="Search payments..."
                        />
                    </x-filament::input.wrapper>
                </div>
                <div>
                    <select wire:model.live="statusFilter" class="fi-input block w-full rounded-lg border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                        <option value="">All statuses</option>
                        <option value="paid">Paid</option>
                        <option value="pending">Pending</option>
                        <option value="open">Open</option>
                        <option value="failed">Failed</option>
                        <option value="canceled">Canceled</option>
                        <option value="expired">Expired</option>
                    </select>
                </div>
                <div>
                    <select wire:model.live="methodFilter" class="fi-input block w-full rounded-lg border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                        <option value="">All methods</option>
                        <option value="ideal">iDEAL</option>
                        <option value="creditcard">Credit Card</option>
                        <option value="banktransfer">Bank Transfer</option>
                        <option value="paypal">PayPal</option>
                        <option value="bancontact">Bancontact</option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <x-filament::button wire:click="search" icon="heroicon-m-magnifying-glass">
                        Search
                    </x-filament::button>
                    <x-filament::button wire:click="clearFilters" color="gray" icon="heroicon-m-x-mark">
                        Clear
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>

        {{-- Error --}}
        @if($error)
            <x-filament::section>
                <div class="text-danger-600 dark:text-danger-400">
                    {{ $error }}
                </div>
            </x-filament::section>
        @endif

        {{-- Loading --}}
        @if($isLoading)
            <div class="flex justify-center py-8">
                <x-filament::loading-indicator class="h-8 w-8" />
            </div>
        @else
            {{-- Payments Table --}}
            <x-filament::section>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b dark:border-gray-700">
                                <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">ID</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Amount</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Status</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Method</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Description</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Created</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y dark:divide-gray-700">
                            @forelse($payments as $payment)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                                    <td class="px-4 py-3 font-mono text-xs">
                                        {{ $payment['id'] }}
                                    </td>
                                    <td class="px-4 py-3">
                                        {{ strtoupper($payment['amount']['currency']) }} {{ $payment['amount']['value'] }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <x-filament::badge :color="$this->getStatusBadgeColor($payment['status'])">
                                            {{ ucfirst($payment['status']) }}
                                        </x-filament::badge>
                                    </td>
                                    <td class="px-4 py-3">
                                        {{ $payment['method'] ?? '-' }}
                                    </td>
                                    <td class="px-4 py-3 max-w-xs truncate" title="{{ $payment['description'] ?? '' }}">
                                        {{ $payment['description'] ?? '-' }}
                                    </td>
                                    <td class="px-4 py-3 text-gray-500">
                                        {{ \Carbon\Carbon::parse($payment['createdAt'])->format('Y-m-d H:i') }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex justify-end gap-2">
                                            <x-filament::icon-button
                                                icon="heroicon-m-eye"
                                                wire:click="viewPayment('{{ $payment['id'] }}')"
                                                tooltip="View Details"
                                            />
                                            @if($payment['status'] === 'paid')
                                                <x-filament::icon-button
                                                    icon="heroicon-m-arrow-uturn-left"
                                                    color="warning"
                                                    wire:click="openRefundModal('{{ $payment['id'] }}')"
                                                    tooltip="Create Refund"
                                                />
                                            @endif
                                            <x-filament::icon-button
                                                icon="heroicon-m-arrow-top-right-on-square"
                                                color="gray"
                                                tag="a"
                                                href="https://my.mollie.com/dashboard/payments/{{ $payment['id'] }}"
                                                target="_blank"
                                                tooltip="Open in Mollie"
                                            />
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                                        No payments found
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    </div>

    {{-- Payment Details Modal --}}
    @if($selectedPayment && !$showRefundModal)
        <x-filament::modal
            id="payment-details"
            :close-by-clicking-away="true"
            width="2xl"
            :heading="'Payment: ' . $selectedPayment['id']"
        >
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400">Amount</h4>
                        <p class="mt-1 font-semibold">{{ strtoupper($selectedPayment['amount']['currency']) }} {{ $selectedPayment['amount']['value'] }}</p>
                    </div>
                    <div>
                        <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400">Status</h4>
                        <p class="mt-1">
                            <x-filament::badge :color="$this->getStatusBadgeColor($selectedPayment['status'])">
                                {{ ucfirst($selectedPayment['status']) }}
                            </x-filament::badge>
                        </p>
                    </div>
                    <div>
                        <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400">Method</h4>
                        <p class="mt-1">{{ $selectedPayment['method'] ?? '-' }}</p>
                    </div>
                    <div>
                        <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400">Created</h4>
                        <p class="mt-1">{{ \Carbon\Carbon::parse($selectedPayment['createdAt'])->format('Y-m-d H:i:s') }}</p>
                    </div>
                </div>

                <div>
                    <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400">Description</h4>
                    <p class="mt-1">{{ $selectedPayment['description'] ?? '-' }}</p>
                </div>

                @if(!empty($selectedPayment['metadata']))
                    <div>
                        <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400">Metadata</h4>
                        <pre class="mt-2 p-3 bg-gray-50 dark:bg-gray-800 rounded-lg text-xs overflow-auto">{{ json_encode($selectedPayment['metadata'], JSON_PRETTY_PRINT) }}</pre>
                    </div>
                @endif

                @if(isset($selectedPayment['_embedded']['refunds']) && count($selectedPayment['_embedded']['refunds']) > 0)
                    <div>
                        <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400">Refunds</h4>
                        <div class="mt-2 space-y-2">
                            @foreach($selectedPayment['_embedded']['refunds'] as $refund)
                                <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                                    <div class="flex justify-between">
                                        <span>{{ strtoupper($refund['amount']['currency']) }} {{ $refund['amount']['value'] }}</span>
                                        <x-filament::badge :color="$refund['status'] === 'refunded' ? 'success' : 'warning'">
                                            {{ ucfirst($refund['status']) }}
                                        </x-filament::badge>
                                    </div>
                                    @if(!empty($refund['description']))
                                        <p class="text-sm text-gray-500 mt-1">{{ $refund['description'] }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <x-slot name="footerActions">
                <x-filament::button wire:click="closePaymentModal" color="gray">
                    Close
                </x-filament::button>
            </x-slot>
        </x-filament::modal>

        <script>
            document.addEventListener('livewire:init', () => {
                @this.on('payment-details-opened', () => {
                    // Modal is opened
                });
            });
        </script>
        @php
            // Auto-open modal when selectedPayment is set
            $this->dispatch('open-modal', id: 'payment-details');
        @endphp
    @endif

    {{-- Refund Modal --}}
    @if($showRefundModal && $selectedPayment)
        <x-filament::modal
            id="refund-modal"
            :close-by-clicking-away="false"
            width="md"
            heading="Create Refund"
        >
            <div class="space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Payment: {{ $selectedPayment['id'] }}<br>
                    Original amount: {{ strtoupper($selectedPayment['amount']['currency']) }} {{ $selectedPayment['amount']['value'] }}
                </p>

                <x-filament::input.wrapper label="Refund Amount">
                    <x-filament::input
                        type="number"
                        step="0.01"
                        wire:model="refundAmount"
                        placeholder="0.00"
                        :max="$selectedPayment['amount']['value']"
                    />
                </x-filament::input.wrapper>

                <x-filament::input.wrapper label="Description (optional)">
                    <x-filament::input
                        type="text"
                        wire:model="refundDescription"
                        placeholder="Reason for refund..."
                    />
                </x-filament::input.wrapper>
            </div>

            <x-slot name="footerActions">
                <x-filament::button wire:click="closeRefundModal" color="gray">
                    Cancel
                </x-filament::button>
                <x-filament::button wire:click="createRefund" color="warning">
                    Create Refund
                </x-filament::button>
            </x-slot>
        </x-filament::modal>

        @php
            $this->dispatch('open-modal', id: 'refund-modal');
        @endphp
    @endif
</x-filament-panels::page>
