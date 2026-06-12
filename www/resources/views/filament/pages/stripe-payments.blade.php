<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Filters --}}
        <x-filament::section>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <x-filament::input.wrapper>
                        <x-filament::input
                            type="text"
                            wire:model.live.debounce.500ms="searchQuery"
                            placeholder="Zoek op order ID, session ID, customer ID of email..."
                        />
                    </x-filament::input.wrapper>
                </div>
                <div>
                    <select wire:model.live="statusFilter" class="fi-input block w-full rounded-lg border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                        <option value="">Alle statussen</option>
                        @foreach($this->getStatusOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex gap-2">
                    <x-filament::button wire:click="search" icon="heroicon-m-magnifying-glass">
                        Zoeken
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>

        {{-- Orders table --}}
        <x-filament::section>
            @if(empty($orders))
                <div class="text-center py-8 text-gray-500">Geen Stripe orders gevonden.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700 text-left text-xs text-gray-500 uppercase tracking-wider">
                                <th class="px-4 py-3">Datum</th>
                                <th class="px-4 py-3">Email</th>
                                <th class="px-4 py-3">License</th>
                                <th class="px-4 py-3">Bedrag</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3">Session / PI</th>
                                <th class="px-4 py-3">Acties</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach($orders as $order)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400 text-xs whitespace-nowrap">
                                        {{ $order['paid_at'] ?? $order['created_at'] }}
                                    </td>
                                    <td class="px-4 py-3">{{ $order['email'] ?? '—' }}</td>
                                    <td class="px-4 py-3">{{ $order['license_name'] ?? '—' }}</td>
                                    <td class="px-4 py-3 font-medium">
                                        {{ strtoupper($order['currency']) }} {{ number_format($order['gross_amount'], 2) }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span @class([
                                            'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
                                            'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' => $order['status'] === 'paid',
                                            'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400' => in_array($order['status'], ['failed', 'refunded', 'charged_back']),
                                            'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400' => in_array($order['status'], ['initiated', 'pending']),
                                            'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300' => !in_array($order['status'], ['paid', 'failed', 'refunded', 'charged_back', 'initiated', 'pending']),
                                        ])>
                                            {{ $order['status'] }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-500 text-xs">{{ $order['type'] }}</td>
                                    <td class="px-4 py-3 font-mono text-xs text-gray-500 truncate max-w-[160px]" title="{{ $order['provider_payment_id'] }}">
                                        {{ $order['provider_payment_id'] }}
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($order['status'] === 'paid')
                                            <x-filament::button
                                                wire:click="selectOrder('{{ $order['id'] }}')"
                                                size="xs"
                                                color="warning"
                                                icon="heroicon-m-arrow-uturn-left"
                                            >
                                                Refund
                                            </x-filament::button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>

    {{-- Refund Modal --}}
    @if($showRefundModal)
        <x-filament::modal id="refund-modal" :visible="true" width="md">
            <x-slot name="heading">Refund aanmaken</x-slot>
            <div class="space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Laat het bedrag leeg voor een volledige terugbetaling. Vul een bedrag in (bijv. <code>9.99</code>) voor een gedeeltelijke terugbetaling.
                </p>
                <x-filament::input.wrapper label="Bedrag (€) — leeg = volledig">
                    <x-filament::input
                        type="text"
                        wire:model="refundAmount"
                        placeholder="bijv. 9.99"
                    />
                </x-filament::input.wrapper>
            </div>
            <x-slot name="footerActions">
                <x-filament::button wire:click="processRefund" color="danger" icon="heroicon-m-arrow-uturn-left">
                    Refund uitvoeren
                </x-filament::button>
                <x-filament::button wire:click="closeModal" color="gray">
                    Annuleren
                </x-filament::button>
            </x-slot>
        </x-filament::modal>
    @endif
</x-filament-panels::page>
