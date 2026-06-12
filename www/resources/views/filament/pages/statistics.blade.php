<x-filament-panels::page>

    {{-- ===================================================================
         PERIOD SELECTOR
    =================================================================== --}}
    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="flex flex-wrap items-center gap-3">

            {{-- Preset buttons --}}
            <div class="flex flex-wrap gap-1.5">
                @foreach([
                    '7d'         => '7 dagen',
                    '30d'        => '30 dagen',
                    '90d'        => '90 dagen',
                    'mtd'        => 'Deze maand',
                    'prev_month' => 'Vorige maand',
                    'ytd'        => 'Dit jaar',
                    '12m'        => '12 maanden',
                    'custom'     => 'Aangepast',
                ] as $key => $label)
                    <button
                        wire:click="setPeriod('{{ $key }}')"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors
                            {{ $this->period === $key
                                ? 'bg-primary-600 text-white'
                                : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700' }}"
                    >{{ $label }}</button>
                @endforeach
            </div>

            <div class="h-6 w-px bg-gray-200 dark:bg-gray-700 hidden sm:block"></div>

            {{-- Compare buttons --}}
            <div class="flex items-center gap-1.5">
                <span class="text-xs text-gray-500 dark:text-gray-400">Vergelijk:</span>
                @foreach(['none' => 'Geen', 'previous' => 'Vorige periode', 'year' => 'Vorig jaar'] as $key => $label)
                    <button
                        wire:click="setCompare('{{ $key }}')"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors
                            {{ $this->compareMode === $key
                                ? 'bg-info-600 text-white'
                                : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700' }}"
                    >{{ $label }}</button>
                @endforeach
            </div>
        </div>

        {{-- Custom date range --}}
        @if($this->period === 'custom')
            <div class="mt-3 flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Van</label>
                    <input
                        type="date"
                        wire:model="customFrom"
                        class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                        value="{{ $this->customFrom ?? now()->subDays(29)->format('Y-m-d') }}"
                    >
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Tot</label>
                    <input
                        type="date"
                        wire:model="customTo"
                        class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                        value="{{ $this->customTo ?? now()->format('Y-m-d') }}"
                    >
                </div>
                <button
                    wire:click="applyCustomRange('{{ $this->customFrom ?? now()->subDays(29)->format('Y-m-d') }}', '{{ $this->customTo ?? now()->format('Y-m-d') }}')"
                    class="rounded-md bg-primary-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-primary-700"
                >Toepassen</button>
            </div>
        @endif

        {{-- Period label --}}
        <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">
            <span class="font-medium text-gray-700 dark:text-gray-200">{{ $this->periodLabel() }}</span>
            @if($this->compareMode !== 'none')
                <span class="ml-1">vs <span class="font-medium">{{ $this->comparePeriodLabel() }}</span></span>
            @endif
        </div>
    </div>

    {{-- ===================================================================
         KPI CARDS
    =================================================================== --}}
    @php
        $stats    = $this->stats;
        $cmp      = $this->compareStats;
        $hasCmp   = $cmp !== null;

        $kpis = [
            [
                'label'   => 'Omzet',
                'value'   => '€ ' . number_format($stats['revenue'], 2, ',', '.'),
                'cmp'     => $hasCmp ? '€ ' . number_format($cmp['revenue'], 2, ',', '.') : null,
                'delta'   => $this->delta('revenue'),
                'icon'    => 'heroicon-m-currency-euro',
                'color'   => 'text-success-600',
                'sub'     => '€ ' . number_format($stats['revenue_eur'] ?? 0, 2, ',', '.') . ' EUR · $ ' . number_format($stats['revenue_usd'] ?? 0, 2, ',', '.') . ' USD',
            ],
            [
                'label'   => 'Orders',
                'value'   => number_format($stats['orders_count']),
                'cmp'     => $hasCmp ? number_format($cmp['orders_count']) : null,
                'delta'   => $this->delta('orders_count'),
                'icon'    => 'heroicon-m-shopping-cart',
                'color'   => 'text-primary-600',
                'sub'     => ($stats['orders_eur'] ?? 0) . ' EUR · ' . ($stats['orders_usd'] ?? 0) . ' USD',
            ],
            [
                'label'   => 'Gem. orderwaarde',
                'value'   => '€ ' . number_format($stats['avg_order_value'], 2, ',', '.'),
                'cmp'     => $hasCmp ? '€ ' . number_format($cmp['avg_order_value'], 2, ',', '.') : null,
                'delta'   => $this->delta('avg_order_value'),
                'icon'    => 'heroicon-m-banknotes',
                'color'   => 'text-warning-600',
            ],
            [
                'label'   => 'Nieuwe licenties',
                'value'   => number_format($stats['new_licenses'] ?? 0),
                'cmp'     => $hasCmp ? number_format($cmp['new_licenses'] ?? 0) : null,
                'delta'   => $this->delta('new_licenses'),
                'icon'    => 'heroicon-m-key',
                'color'   => 'text-success-600',
            ],
            [
                'label'   => 'Verlopen licenties',
                'value'   => number_format($stats['expired_licenses'] ?? 0),
                'cmp'     => $hasCmp ? number_format($cmp['expired_licenses'] ?? 0) : null,
                'delta'   => $this->delta('expired_licenses'),
                'icon'    => 'heroicon-m-x-circle',
                'color'   => 'text-danger-600',
            ],
            [
                'label'   => 'Factuur aangevraagd',
                'value'   => number_format($stats['invoice_requested_count'] ?? 0),
                'cmp'     => $hasCmp ? number_format($cmp['invoice_requested_count'] ?? 0) : null,
                'delta'   => $this->delta('invoice_requested_count'),
                'icon'    => 'heroicon-m-document-text',
                'color'   => 'text-warning-600',
            ],
            [
                'label'   => 'Nieuwe gebruikers',
                'value'   => number_format($stats['new_users']),
                'cmp'     => $hasCmp ? number_format($cmp['new_users']) : null,
                'delta'   => $this->delta('new_users'),
                'icon'    => 'heroicon-m-user-plus',
                'color'   => 'text-info-600',
            ],
            [
                'label'   => 'Actieve gebruikers',
                'value'   => number_format($stats['active_users']),
                'cmp'     => $hasCmp ? number_format($cmp['active_users']) : null,
                'delta'   => $this->delta('active_users'),
                'icon'    => 'heroicon-m-users',
                'color'   => 'text-info-600',
                'sub'     => 'gebruikersdagen',
            ],
            [
                'label'   => 'Pageviews',
                'value'   => number_format($stats['pageviews']),
                'cmp'     => $hasCmp ? number_format($cmp['pageviews']) : null,
                'delta'   => $this->delta('pageviews'),
                'icon'    => 'heroicon-m-eye',
                'color'   => 'text-gray-600',
                'sub'     => 'Google: ' . number_format($stats['pageviews_google']) . ' · Direct: ' . number_format($stats['pageviews_direct']),
            ],
            [
                'label'   => 'Checkout gestart',
                'value'   => number_format($stats['checkout_started']),
                'cmp'     => $hasCmp ? number_format($cmp['checkout_started']) : null,
                'delta'   => $this->delta('checkout_started'),
                'icon'    => 'heroicon-m-arrow-right-circle',
                'color'   => 'text-primary-600',
                'sub'     => 'Conversie: ' . $stats['checkout_conversion'] . '%',
            ],
            [
                'label'   => 'Credits gekocht',
                'value'   => number_format($stats['credits_purchased_events']),
                'cmp'     => $hasCmp ? number_format($cmp['credits_purchased_events']) : null,
                'delta'   => $this->delta('credits_purchased_events'),
                'icon'    => 'heroicon-m-bolt',
                'color'   => 'text-warning-600',
            ],
            [
                'label'   => 'Upgrade modals',
                'value'   => number_format($stats['upgrade_modal_shown']),
                'cmp'     => $hasCmp ? number_format($cmp['upgrade_modal_shown']) : null,
                'delta'   => $this->delta('upgrade_modal_shown'),
                'icon'    => 'heroicon-m-arrow-trending-up',
                'color'   => 'text-danger-600',
            ],
            [
                'label'   => 'Credits ontvangen',
                'value'   => number_format($stats['credits_received']),
                'cmp'     => $hasCmp ? number_format($cmp['credits_received']) : null,
                'delta'   => $this->delta('credits_received'),
                'icon'    => 'heroicon-m-plus-circle',
                'color'   => 'text-success-600',
            ],
            [
                'label'   => 'Credits verbruikt',
                'value'   => number_format($stats['credits_spent']),
                'cmp'     => $hasCmp ? number_format($cmp['credits_spent']) : null,
                'delta'   => $this->delta('credits_spent'),
                'icon'    => 'heroicon-m-minus-circle',
                'color'   => 'text-gray-500',
            ],
        ];
    @endphp

    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6">
        @foreach($kpis as $kpi)
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-start justify-between">
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $kpi['label'] }}</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white truncate">{{ $kpi['value'] }}</p>
                        @if($hasCmp && $kpi['cmp'])
                            <p class="mt-0.5 text-xs text-gray-400">{{ $kpi['cmp'] }}</p>
                        @endif
                        @if(isset($kpi['sub']))
                            <p class="mt-0.5 text-xs text-gray-400">{{ $kpi['sub'] }}</p>
                        @endif
                    </div>
                    @if(isset($kpi['delta']) && $kpi['delta'] !== null)
                        @php $d = $kpi['delta']; @endphp
                        <span class="ml-2 flex-shrink-0 rounded-full px-1.5 py-0.5 text-xs font-medium
                            {{ $d >= 0 ? 'bg-success-50 text-success-700 dark:bg-success-900/30 dark:text-success-400'
                                       : 'bg-danger-50 text-danger-700 dark:bg-danger-900/30 dark:text-danger-400' }}">
                            {{ $d >= 0 ? '+' : '' }}{{ $d }}%
                        </span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- ===================================================================
         CHARTS
    =================================================================== --}}
    @php $chartData = $this->chartData; @endphp

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">

        {{-- Revenue chart --}}
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 lg:col-span-2"
             x-data="revenueChart(@js($chartData), @js($hasCmp), @js($this->comparePeriodLabel()))"
             x-init="init()"
             wire:key="revenue-chart-{{ $this->period }}-{{ $this->compareMode }}"
             @stats-updated.window="update($event.detail)">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Omzet per dag</h3>
                <div class="flex gap-2 text-xs text-gray-500">
                    <span class="flex items-center gap-1"><span class="inline-block h-2 w-4 rounded bg-blue-500"></span>Omzet</span>
                    @if($hasCmp)
                        <span class="flex items-center gap-1"><span class="inline-block h-2 w-4 rounded bg-blue-200"></span>Vergelijking</span>
                    @endif
                </div>
            </div>
            <canvas id="revenue-chart" height="80"></canvas>
        </div>

        {{-- New users chart --}}
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
             x-data="usersChart(@js($chartData))"
             x-init="init()"
             wire:key="users-chart-{{ $this->period }}-{{ $this->compareMode }}">
            <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">Nieuwe gebruikers per dag</h3>
            <canvas id="users-chart" height="120"></canvas>
        </div>

        {{-- License breakdown --}}
        @php
            $licenseBreakdown = $this->licenseBreakdown;
            $tierBreakdown    = $this->tierBreakdown;
            $tierColors = ['onetime' => 'bg-primary-500', 'premium' => 'bg-warning-500', 'free' => 'bg-gray-400', 'unknown' => 'bg-gray-300'];
            $tierLabels = ['onetime' => 'Eenmalig', 'premium' => 'Premium', 'free' => 'Gratis', 'unknown' => 'Onbekend'];
        @endphp
        @if(!empty($licenseBreakdown))
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="mb-1 text-sm font-semibold text-gray-900 dark:text-white">Omzet per licentie</h3>

                {{-- Tier summary pills --}}
                @if(!empty($tierBreakdown))
                    <div class="mb-3 flex flex-wrap gap-2">
                        @foreach($tierBreakdown as $tier => $td)
                            <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium
                                {{ $tier === 'premium' ? 'bg-warning-100 text-warning-800 dark:bg-warning-900/30 dark:text-warning-300'
                                : ($tier === 'onetime' ? 'bg-primary-100 text-primary-800 dark:bg-primary-900/30 dark:text-primary-300'
                                : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300') }}">
                                {{ $tierLabels[$tier] ?? $tier }}
                                · {{ $td['count'] }} orders
                                · €{{ number_format($td['revenue'], 2, ',', '.') }}
                            </span>
                        @endforeach
                    </div>
                @endif

                <div class="space-y-2.5">
                    @php $totalRevenue = array_sum(array_column($licenseBreakdown, 'revenue')); @endphp
                    @foreach($licenseBreakdown as $slug => $data)
                        @php
                            $pct = $totalRevenue > 0 ? round(($data['revenue'] / $totalRevenue) * 100) : 0;
                            $barColor = $tierColors[$data['tier']] ?? 'bg-gray-400';
                            $currency = strtoupper($data['currency'] ?? '');
                        @endphp
                        <div>
                            <div class="mb-1 flex items-center justify-between text-xs">
                                <div class="flex items-center gap-1.5 min-w-0">
                                    <span class="font-medium text-gray-800 dark:text-gray-200 truncate">{{ $data['name'] }}</span>
                                    <span class="flex-shrink-0 rounded px-1 py-0.5 text-[10px] font-medium
                                        {{ $data['tier'] === 'premium' ? 'bg-warning-100 text-warning-700' : 'bg-gray-100 text-gray-500' }}">
                                        {{ $tierLabels[$data['tier']] ?? $data['tier'] }}
                                    </span>
                                    <span class="flex-shrink-0 text-gray-400">{{ $currency }}</span>
                                    @if($data['credits'] > 0)
                                        <span class="flex-shrink-0 text-gray-400">· {{ number_format($data['credits']) }} cr</span>
                                    @endif
                                </div>
                                <span class="flex-shrink-0 ml-2 text-gray-500">
                                    {{ $data['orders'] }}× · €{{ number_format($data['revenue'], 2, ',', '.') }}
                                </span>
                            </div>
                            <div class="h-1.5 w-full rounded-full bg-gray-100 dark:bg-gray-700">
                                <div class="h-1.5 rounded-full {{ $barColor }}" style="width: {{ $pct }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- ===================================================================
         DAILY BREAKDOWN TABLE
    =================================================================== --}}
    @php $rows = $this->dailyRows; @endphp

    <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-hidden">
        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Dagelijks overzicht</h3>
        </div>

        @if($rows->isEmpty())
            <div class="px-4 py-8 text-center text-sm text-gray-500">
                Geen data beschikbaar voor deze periode.
                <span class="block mt-1 text-xs">Voer <code class="bg-gray-100 px-1 rounded">php artisan stats:generate --backfill</code> uit om historische data in te laden.</span>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
                            <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-600 dark:text-gray-300">Datum</th>
                            <th class="px-3 py-2.5 text-right text-xs font-semibold text-gray-600 dark:text-gray-300">Omzet</th>
                            <th class="px-3 py-2.5 text-right text-xs font-semibold text-gray-600 dark:text-gray-300">Orders</th>
                            <th class="px-3 py-2.5 text-right text-xs font-semibold text-gray-600 dark:text-gray-300">Gem. order</th>
                            <th class="px-3 py-2.5 text-right text-xs font-semibold text-gray-600 dark:text-gray-300">Nieuwe users</th>
                            <th class="px-3 py-2.5 text-right text-xs font-semibold text-gray-600 dark:text-gray-300">Actief</th>
                            <th class="px-3 py-2.5 text-right text-xs font-semibold text-gray-600 dark:text-gray-300">Pageviews</th>
                            <th class="px-3 py-2.5 text-right text-xs font-semibold text-gray-600 dark:text-gray-300">Checkout</th>
                            <th class="px-3 py-2.5 text-right text-xs font-semibold text-gray-600 dark:text-gray-300">Upgrades</th>
                            <th class="px-3 py-2.5 text-right text-xs font-semibold text-gray-600 dark:text-gray-300">Credits +</th>
                            <th class="px-3 py-2.5 text-right text-xs font-semibold text-gray-600 dark:text-gray-300">Credits −</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50 dark:divide-gray-800/50">
                        @foreach($rows as $row)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/30">
                                <td class="px-4 py-2 font-medium text-gray-900 dark:text-white whitespace-nowrap">
                                    {{ $row->date->translatedFormat('j M Y') }}
                                    @if($row->date->isToday())
                                        <span class="ml-1 rounded-full bg-primary-100 px-1.5 py-0.5 text-xs text-primary-700 dark:bg-primary-900/30 dark:text-primary-400">vandaag</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right @if($row->revenue > 0) font-semibold text-success-700 dark:text-success-400 @else text-gray-400 @endif">
                                    @if($row->revenue > 0)€{{ number_format($row->revenue, 2, ',', '.') }}@else–@endif
                                </td>
                                <td class="px-3 py-2 text-right @if($row->orders_count > 0) text-gray-900 dark:text-white @else text-gray-400 @endif">
                                    {{ $row->orders_count > 0 ? $row->orders_count : '–' }}
                                </td>
                                <td class="px-3 py-2 text-right text-gray-500">
                                    @if($row->orders_count > 0)€{{ number_format($row->avg_order_value, 2, ',', '.') }}@else–@endif
                                </td>
                                <td class="px-3 py-2 text-right @if($row->new_users > 0) text-gray-900 dark:text-white @else text-gray-400 @endif">
                                    {{ $row->new_users > 0 ? number_format($row->new_users) : '–' }}
                                </td>
                                <td class="px-3 py-2 text-right text-gray-500">
                                    {{ $row->active_users > 0 ? number_format($row->active_users) : '–' }}
                                </td>
                                <td class="px-3 py-2 text-right text-gray-500">
                                    {{ $row->pageviews > 0 ? number_format($row->pageviews) : '–' }}
                                </td>
                                <td class="px-3 py-2 text-right text-gray-500">
                                    {{ $row->checkout_started > 0 ? $row->checkout_started : '–' }}
                                </td>
                                <td class="px-3 py-2 text-right @if($row->upgrade_modal_shown > 0) text-warning-600 dark:text-warning-400 @else text-gray-400 @endif">
                                    {{ $row->upgrade_modal_shown > 0 ? $row->upgrade_modal_shown : '–' }}
                                </td>
                                <td class="px-3 py-2 text-right @if($row->credits_received > 0) text-success-600 dark:text-success-400 @else text-gray-400 @endif">
                                    {{ $row->credits_received > 0 ? number_format($row->credits_received) : '–' }}
                                </td>
                                <td class="px-3 py-2 text-right @if($row->credits_spent > 0) text-gray-600 dark:text-gray-400 @else text-gray-400 @endif">
                                    {{ $row->credits_spent > 0 ? number_format($row->credits_spent) : '–' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 font-semibold">
                            <td class="px-4 py-2.5 text-xs text-gray-600 dark:text-gray-300">Totaal {{ $rows->count() }} dagen</td>
                            <td class="px-3 py-2.5 text-right text-success-700 dark:text-success-400">€{{ number_format($stats['revenue'], 2, ',', '.') }}</td>
                            <td class="px-3 py-2.5 text-right text-gray-900 dark:text-white">{{ number_format($stats['orders_count']) }}</td>
                            <td class="px-3 py-2.5 text-right text-gray-500">@if($stats['orders_count'] > 0)€{{ number_format($stats['avg_order_value'], 2, ',', '.') }}@else–@endif</td>
                            <td class="px-3 py-2.5 text-right text-gray-900 dark:text-white">{{ number_format($stats['new_users']) }}</td>
                            <td class="px-3 py-2.5 text-right text-gray-500"></td>
                            <td class="px-3 py-2.5 text-right text-gray-500">{{ number_format($stats['pageviews']) }}</td>
                            <td class="px-3 py-2.5 text-right text-gray-500">{{ number_format($stats['checkout_started']) }}</td>
                            <td class="px-3 py-2.5 text-right text-gray-500">{{ number_format($stats['upgrade_modal_shown']) }}</td>
                            <td class="px-3 py-2.5 text-right text-success-700 dark:text-success-400">{{ number_format($stats['credits_received']) }}</td>
                            <td class="px-3 py-2.5 text-right text-gray-600 dark:text-gray-400">{{ number_format($stats['credits_spent']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>

    {{-- ===================================================================
         CHART.JS
    =================================================================== --}}
    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
        document.addEventListener('alpine:init', () => {

            Alpine.data('revenueChart', (chartData, hasCmp, cmpLabel) => ({
                chart: null,

                init() {
                    const isDark = document.documentElement.classList.contains('dark');
                    const gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
                    const textColor = isDark ? '#9ca3af' : '#6b7280';

                    const datasets = [{
                        label: 'Omzet',
                        data: chartData.revenue,
                        borderColor: 'rgb(59,130,246)',
                        backgroundColor: 'rgba(59,130,246,0.15)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: chartData.labels.length > 60 ? 0 : 3,
                        pointHoverRadius: 5,
                    }];

                    if (hasCmp && chartData.compareRevenue.length) {
                        datasets.push({
                            label: cmpLabel || 'Vergelijking',
                            data: chartData.compareRevenue,
                            borderColor: 'rgb(147,197,253)',
                            backgroundColor: 'rgba(147,197,253,0.1)',
                            borderDash: [4, 4],
                            fill: false,
                            tension: 0.3,
                            pointRadius: 0,
                            pointHoverRadius: 5,
                        });
                    }

                    this.chart = new Chart(document.getElementById('revenue-chart'), {
                        type: 'line',
                        data: { labels: chartData.labels, datasets },
                        options: {
                            responsive: true,
                            interaction: { mode: 'index', intersect: false },
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    callbacks: {
                                        label: ctx => ` € ${ctx.raw.toFixed(2)}`
                                    }
                                }
                            },
                            scales: {
                                x: { grid: { color: gridColor }, ticks: { color: textColor, maxTicksLimit: 12 } },
                                y: {
                                    grid: { color: gridColor },
                                    ticks: {
                                        color: textColor,
                                        callback: v => `€${v}`,
                                    },
                                    beginAtZero: true,
                                },
                            },
                        },
                    });
                },
            }));

            Alpine.data('usersChart', (chartData) => ({
                chart: null,

                init() {
                    const isDark = document.documentElement.classList.contains('dark');
                    const gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
                    const textColor = isDark ? '#9ca3af' : '#6b7280';

                    this.chart = new Chart(document.getElementById('users-chart'), {
                        type: 'bar',
                        data: {
                            labels: chartData.labels,
                            datasets: [{
                                label: 'Nieuwe users',
                                data: chartData.newUsers,
                                backgroundColor: 'rgba(99,102,241,0.7)',
                                borderRadius: 3,
                            }],
                        },
                        options: {
                            responsive: true,
                            plugins: { legend: { display: false } },
                            scales: {
                                x: { grid: { display: false }, ticks: { color: textColor, maxTicksLimit: 12 } },
                                y: { grid: { color: gridColor }, ticks: { color: textColor }, beginAtZero: true },
                            },
                        },
                    });
                },
            }));
        });
    </script>
    @endpush

</x-filament-panels::page>
