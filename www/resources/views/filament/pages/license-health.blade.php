<x-filament-panels::page>
    <div class="space-y-6">

        {{-- Status Cards --}}
        @php $cards = $this->getStatusCards(); @endphp
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            @foreach($cards as $card)
                <x-filament::section>
                    <div class="flex items-center gap-3">
                        <div class="p-2 rounded-lg bg-{{ $card['color'] }}-50 dark:bg-{{ $card['color'] }}-950">
                            <x-dynamic-component :component="$card['icon']" class="w-6 h-6 text-{{ $card['color'] }}-600 dark:text-{{ $card['color'] }}-400" />
                        </div>
                        <div>
                            <div class="text-3xl font-bold text-{{ $card['color'] }}-600 dark:text-{{ $card['color'] }}-400">
                                {{ $card['value'] }}
                            </div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                {{ $card['label'] }}
                            </div>
                        </div>
                    </div>
                </x-filament::section>
            @endforeach
        </div>

        {{-- Tabs --}}
        <div>
            <nav class="flex gap-2 border-b border-gray-200 dark:border-gray-700 mb-4">
                <button
                    wire:click="setActiveTab('overdue')"
                    class="px-4 py-2 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'overdue' ? 'border-primary-600 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"
                >
                    Overdue Resets
                    @php $overdueCount = $this->getOverdueResets()->count(); @endphp
                    @if($overdueCount > 0)
                        <span class="ml-1 inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold rounded-full bg-danger-100 text-danger-700 dark:bg-danger-900 dark:text-danger-300">
                            {{ $overdueCount }}
                        </span>
                    @endif
                </button>
                <button
                    wire:click="setActiveTab('expiring')"
                    class="px-4 py-2 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'expiring' ? 'border-primary-600 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"
                >
                    Expiring
                    @php $expiringCount = $this->getExpiringSoon()->count(); @endphp
                    @if($expiringCount > 0)
                        <span class="ml-1 inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold rounded-full bg-warning-100 text-warning-700 dark:bg-warning-900 dark:text-warning-300">
                            {{ $expiringCount }}
                        </span>
                    @endif
                </button>
                <button
                    wire:click="setActiveTab('active')"
                    class="px-4 py-2 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'active' ? 'border-primary-600 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"
                >
                    Alle actieve licenties
                </button>
            </nav>

            {{-- Tab: Overdue Resets --}}
            @if($activeTab === 'overdue')
                @php $overdueItems = $this->getOverdueResets(); @endphp

                @if($overdueItems->isEmpty())
                    <div class="text-center py-8 text-gray-500">
                        <x-heroicon-o-check-circle class="w-12 h-12 mx-auto mb-2 text-success-500" />
                        <p class="text-lg font-medium">Geen overdue resets</p>
                        <p class="text-sm">Alle credit resets zijn up-to-date.</p>
                    </div>
                @else
                    <x-filament::section>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm text-left">
                                <thead class="text-xs uppercase bg-gray-50 dark:bg-gray-800 text-gray-500">
                                    <tr>
                                        <th class="px-4 py-3">Type</th>
                                        <th class="px-4 py-3">Naam</th>
                                        <th class="px-4 py-3">Licentie</th>
                                        <th class="px-4 py-3">Reset interval</th>
                                        <th class="px-4 py-3">Laatste reset</th>
                                        <th class="px-4 py-3">Verwachte reset</th>
                                        <th class="px-4 py-3">Dagen overdue</th>
                                        <th class="px-4 py-3">Saldo</th>
                                        <th class="px-4 py-3">Actie</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    @foreach($overdueItems as $item)
                                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                            <td class="px-4 py-3">
                                                <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full {{ $item['type'] === 'user' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300' : 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300' }}">
                                                    {{ $item['type'] === 'user' ? 'User' : 'Org' }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 font-medium">{{ $item['name'] }}</td>
                                            <td class="px-4 py-3">
                                                {{ $item['license_name'] }}
                                                <span class="ml-1 inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                                                    {{ ucfirst($item['tier']) }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-3">{{ $item['reset_interval'] }}</td>
                                            <td class="px-4 py-3">{{ $item['last_reset']->format('d M Y H:i') }}</td>
                                            <td class="px-4 py-3">{{ $item['expected_reset']->format('d M Y H:i') }}</td>
                                            <td class="px-4 py-3">
                                                <span class="inline-flex items-center px-2 py-1 text-xs font-bold rounded-full bg-danger-100 text-danger-700 dark:bg-danger-900 dark:text-danger-300">
                                                    {{ $item['days_overdue'] }}d
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 font-mono">{{ number_format($item['current_balance']) }}</td>
                                            <td class="px-4 py-3">
                                                <x-filament::button
                                                    size="xs"
                                                    color="warning"
                                                    wire:click="manualReset('{{ $item['type'] }}', {{ $item['license_id'] }})"
                                                    wire:confirm="Weet je zeker dat je de credits wilt resetten voor {{ $item['name'] }}? Huidig saldo: {{ number_format($item['current_balance']) }} credits."
                                                >
                                                    Reset nu
                                                </x-filament::button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-filament::section>
                @endif
            @endif

            {{-- Tab: Expiring --}}
            @if($activeTab === 'expiring')
                @php
                    $expiringItems = $this->getExpiringSoon();
                    $expiredNotMarked = $expiringItems->where('section', 'expired_not_marked');
                    $expiringSoonItems = $expiringItems->where('section', 'expiring_soon');
                @endphp

                @if($expiringItems->isEmpty())
                    <div class="text-center py-8 text-gray-500">
                        <x-heroicon-o-check-circle class="w-12 h-12 mx-auto mb-2 text-success-500" />
                        <p class="text-lg font-medium">Geen verlopen of bijna verlopen licenties</p>
                        <p class="text-sm">Alle licenties zijn correct gemarkeerd.</p>
                    </div>
                @else
                    {{-- Expired but not marked --}}
                    @if($expiredNotMarked->isNotEmpty())
                        <x-filament::section>
                            <x-slot name="heading">
                                <div class="flex items-center gap-2 text-danger-600">
                                    <x-heroicon-o-exclamation-circle class="w-5 h-5" />
                                    Verlopen maar niet gemarkeerd ({{ $expiredNotMarked->count() }})
                                </div>
                            </x-slot>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm text-left">
                                    <thead class="text-xs uppercase bg-gray-50 dark:bg-gray-800 text-gray-500">
                                        <tr>
                                            <th class="px-4 py-3">Type</th>
                                            <th class="px-4 py-3">Naam</th>
                                            <th class="px-4 py-3">Licentie</th>
                                            <th class="px-4 py-3">Status</th>
                                            <th class="px-4 py-3">Verloopt op</th>
                                            <th class="px-4 py-3">Dagen verlopen</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                        @foreach($expiredNotMarked as $item)
                                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                                <td class="px-4 py-3">
                                                    <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full {{ $item['type'] === 'user' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300' : 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300' }}">
                                                        {{ $item['type'] === 'user' ? 'User' : 'Org' }}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 font-medium">{{ $item['name'] }}</td>
                                                <td class="px-4 py-3">
                                                    {{ $item['license_name'] }}
                                                    <span class="ml-1 inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                                                        {{ ucfirst($item['tier']) }}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full bg-warning-100 text-warning-700 dark:bg-warning-900 dark:text-warning-300">
                                                        {{ ucfirst($item['status']) }}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3">{{ $item['ends_at']->format('d M Y H:i') }}</td>
                                                <td class="px-4 py-3">
                                                    <span class="inline-flex items-center px-2 py-1 text-xs font-bold rounded-full bg-danger-100 text-danger-700 dark:bg-danger-900 dark:text-danger-300">
                                                        {{ $item['days_remaining'] }}d
                                                    </span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </x-filament::section>
                    @endif

                    {{-- Expiring soon --}}
                    @if($expiringSoonItems->isNotEmpty())
                        <x-filament::section class="mt-4">
                            <x-slot name="heading">
                                <div class="flex items-center gap-2 text-warning-600">
                                    <x-heroicon-o-clock class="w-5 h-5" />
                                    Verloopt binnen 14 dagen ({{ $expiringSoonItems->count() }})
                                </div>
                            </x-slot>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm text-left">
                                    <thead class="text-xs uppercase bg-gray-50 dark:bg-gray-800 text-gray-500">
                                        <tr>
                                            <th class="px-4 py-3">Type</th>
                                            <th class="px-4 py-3">Naam</th>
                                            <th class="px-4 py-3">Licentie</th>
                                            <th class="px-4 py-3">Status</th>
                                            <th class="px-4 py-3">Verloopt op</th>
                                            <th class="px-4 py-3">Dagen resterend</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                        @foreach($expiringSoonItems as $item)
                                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                                <td class="px-4 py-3">
                                                    <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full {{ $item['type'] === 'user' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300' : 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300' }}">
                                                        {{ $item['type'] === 'user' ? 'User' : 'Org' }}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 font-medium">{{ $item['name'] }}</td>
                                                <td class="px-4 py-3">
                                                    {{ $item['license_name'] }}
                                                    <span class="ml-1 inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                                                        {{ ucfirst($item['tier']) }}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full bg-warning-100 text-warning-700 dark:bg-warning-900 dark:text-warning-300">
                                                        {{ ucfirst($item['status']) }}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3">{{ $item['ends_at']->format('d M Y H:i') }}</td>
                                                <td class="px-4 py-3">
                                                    <span class="inline-flex items-center px-2 py-1 text-xs font-bold rounded-full bg-warning-100 text-warning-700 dark:bg-warning-900 dark:text-warning-300">
                                                        {{ $item['days_remaining'] }}d
                                                    </span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </x-filament::section>
                    @endif
                @endif
            @endif

            {{-- Tab: All Active Licenses --}}
            @if($activeTab === 'active')
                {{-- Filters --}}
                <div class="flex gap-4 mb-4">
                    <div>
                        <label class="text-xs font-medium text-gray-500 block mb-1">Tier</label>
                        <select wire:model.live="filterTier" class="text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">
                            <option value="">Alle tiers</option>
                            <option value="free">Free</option>
                            <option value="premium">Premium</option>
                            <option value="onetime">Onetime</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-500 block mb-1">Type</label>
                        <select wire:model.live="filterType" class="text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">
                            <option value="">Alle types</option>
                            <option value="user">User</option>
                            <option value="organization">Organization</option>
                        </select>
                    </div>
                </div>

                @php $activeLicenses = $this->getAllActiveLicenses(); @endphp

                @if($activeLicenses->isEmpty())
                    <div class="text-center py-8 text-gray-500">
                        <x-heroicon-o-key class="w-12 h-12 mx-auto mb-2 text-gray-400" />
                        <p class="text-lg font-medium">Geen actieve licenties gevonden</p>
                        <p class="text-sm">Pas de filters aan om resultaten te zien.</p>
                    </div>
                @else
                    <x-filament::section>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm text-left">
                                <thead class="text-xs uppercase bg-gray-50 dark:bg-gray-800 text-gray-500">
                                    <tr>
                                        <th class="px-4 py-3">Type</th>
                                        <th class="px-4 py-3">Naam</th>
                                        <th class="px-4 py-3">Licentie</th>
                                        <th class="px-4 py-3">Status</th>
                                        <th class="px-4 py-3">Gestart</th>
                                        <th class="px-4 py-3">Verloopt</th>
                                        <th class="px-4 py-3">Laatste reset</th>
                                        <th class="px-4 py-3">Volgende reset</th>
                                        <th class="px-4 py-3">Saldo</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    @foreach($activeLicenses as $item)
                                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                            <td class="px-4 py-3">
                                                <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full {{ $item['type'] === 'user' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300' : 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300' }}">
                                                    {{ $item['type'] === 'user' ? 'User' : 'Org' }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 font-medium">{{ $item['name'] }}</td>
                                            <td class="px-4 py-3">
                                                {{ $item['license_name'] }}
                                                <span class="ml-1 inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                                                    {{ ucfirst($item['tier']) }}
                                                </span>
                                                @if($item['billing_cycle'])
                                                    <span class="ml-1 inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full bg-blue-50 text-blue-700 dark:bg-blue-900 dark:text-blue-300">
                                                        {{ ucfirst($item['billing_cycle']) }}
                                                    </span>
                                                @endif
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full bg-success-100 text-success-700 dark:bg-success-900 dark:text-success-300">
                                                    {{ ucfirst($item['status']) }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-3">{{ $item['starts_at'] ? $item['starts_at']->format('d M Y') : '-' }}</td>
                                            <td class="px-4 py-3">{{ $item['ends_at'] ? $item['ends_at']->format('d M Y') : 'Doorlopend' }}</td>
                                            <td class="px-4 py-3">{{ $item['last_reset'] ? $item['last_reset']->format('d M Y H:i') : '-' }}</td>
                                            <td class="px-4 py-3">{{ $item['next_reset'] ? $item['next_reset']->format('d M Y') : '-' }}</td>
                                            <td class="px-4 py-3 font-mono">{{ number_format($item['balance']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-filament::section>
                @endif
            @endif
        </div>
    </div>
</x-filament-panels::page>
