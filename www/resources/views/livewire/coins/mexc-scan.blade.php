<div class="max-w-7xl mx-auto space-y-6">

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Munten</h1>
            <p class="text-sm text-gray-500">Volatiele MEXC-kandidaten voor rotatie — gesorteerd op 24u-volatiliteit.</p>
        </div>
        <button wire:click="scanNow" wire:loading.attr="disabled" wire:target="scanNow"
                class="shrink-0 inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg
                       bg-emerald-600 text-white hover:bg-emerald-700 transition
                       disabled:opacity-60 disabled:cursor-not-allowed">
            <svg wire:loading.remove wire:target="scanNow" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            <svg wire:loading wire:target="scanNow" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <span wire:loading.remove wire:target="scanNow">Nu verversen</span>
            <span wire:loading wire:target="scanNow">Bezig met scannen…</span>
        </button>
    </div>

    @if ($error)
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3">
            {{ $error }}
        </div>
    @endif

    {{-- Tab-balk --}}
    <div class="flex gap-1 border-b border-gray-200">
        <a href="{{ route('coins.ranking') }}"
           class="px-4 py-2 text-sm font-medium rounded-t-lg transition
                  {{ request()->routeIs('coins.ranking') ? 'bg-white border border-b-white -mb-px text-gray-900' : 'text-gray-500 hover:text-gray-700' }}">
            Kansrijk (engine)
        </a>
        <a href="{{ route('coins.weekly') }}"
           class="px-4 py-2 text-sm font-medium rounded-t-lg transition
                  {{ request()->routeIs('coins.weekly') ? 'bg-white border border-b-white -mb-px text-gray-900' : 'text-gray-500 hover:text-gray-700' }}">
            Per week
        </a>
        <a href="{{ route('coins.mexc') }}"
           class="px-4 py-2 text-sm font-medium rounded-t-lg transition
                  {{ request()->routeIs('coins.mexc') ? 'bg-white border border-b-white -mb-px text-gray-900' : 'text-gray-500 hover:text-gray-700' }}">
            MEXC-markt
        </a>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-6 bg-white rounded-xl shadow-sm border border-gray-100 px-4 py-3">
        <label class="flex items-center gap-2 text-sm text-gray-600">
            Min. marketcap
            <select wire:model.live="mcapMin" class="rounded border-gray-300 text-sm py-1">
                <option value="0">Alles</option>
                <option value="1000000">$1M</option>
                <option value="5000000">$5M</option>
                <option value="10000000">$10M</option>
                <option value="50000000">$50M</option>
                <option value="100000000">$100M</option>
            </select>
        </label>
        <label class="flex items-center gap-2 text-sm text-gray-600">
            Min. 24u-volume
            <select wire:model.live="minVol24h" class="rounded border-gray-300 text-sm py-1">
                <option value="0">Alles</option>
                <option value="50000">$50k</option>
                <option value="100000">$100k</option>
                <option value="500000">$500k</option>
                <option value="1000000">$1M</option>
            </select>
        </label>
        <label class="flex items-center gap-2 text-sm text-gray-600">
            <input type="checkbox" wire:model.live="hideUnder7d" class="rounded border-gray-300">
            Verberg &lt;7 dagen oud
        </label>
    </div>

    @if (count($coins))
        @php $maxVolat = max(0.01, collect($coins)->max('volat_pct')); @endphp
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 flex items-baseline justify-between">
                <h3 class="font-semibold text-gray-800">{{ count($coins) }} coins gevonden</h3>
                <span class="text-xs text-gray-500">volat = (24u high&minus;low)/low &middot; sorteersleutel</span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3">#</th>
                            <th class="px-4 py-3">Munt</th>
                            <th class="px-4 py-3">Volatiliteit</th>
                            <th class="px-4 py-3 text-right">24u-wijziging</th>
                            <th class="px-4 py-3 text-right">24u-volume</th>
                            <th class="px-4 py-3 text-right">Marketcap</th>
                            <th class="px-4 py-3 text-right">Leeftijd</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($coins as $i => $c)
                            <tr>
                                <td class="px-4 py-3 text-gray-400">{{ $i + 1 }}</td>
                                <td class="px-4 py-3 font-medium text-gray-800">
                                    <a href="https://www.tradingview.com/chart/70iLoXV1/?symbol=MEXC%3A{{ $c['symbol'] }}"
                                       target="_blank" rel="noopener"
                                       class="hover:text-emerald-600 hover:underline">{{ $c['base'] }}</a>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($c['volat_pct'] !== null)
                                        <div class="flex items-center gap-2">
                                            <div class="h-2 w-28 bg-gray-100 rounded">
                                                <div class="h-2 rounded {{ $c['volat_pct'] >= 0.66 * $maxVolat ? 'bg-emerald-500' : ($c['volat_pct'] >= 0.33 * $maxVolat ? 'bg-amber-400' : 'bg-gray-300') }}"
                                                     style="width: {{ max(3, min(100, $c['volat_pct'] / $maxVolat * 100)) }}%"></div>
                                            </div>
                                            <span class="tabular-nums text-gray-700">{{ number_format((float) $c['volat_pct'], 1, ',', '.') }}%</span>
                                        </div>
                                    @else
                                        <span class="text-gray-400">&mdash;</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums {{ ($c['change24h_pct'] ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-500' }}">
                                    {{ $c['change24h_pct'] !== null ? (($c['change24h_pct'] >= 0 ? '+' : '') . number_format((float) $c['change24h_pct'], 1, ',', '.') . '%') : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-gray-600">
                                    @if ($c['vol24h_usd'] !== null)
                                        ${{ number_format((float) $c['vol24h_usd'], 0, ',', '.') }}
                                    @else
                                        &mdash;
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-gray-600">
                                    @if ($c['mcap_usd'] !== null)
                                        ${{ number_format((float) $c['mcap_usd'], 0, ',', '.') }}
                                    @else
                                        <span class="text-amber-500 text-xs">mcap onbekend</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-gray-600">
                                    @if ($c['age_days'] !== null)
                                        @if ($c['age_days'] < 7)
                                            <span class="inline-flex items-center gap-1">
                                                {{ $c['age_days'] }}d
                                                <span class="px-1.5 py-0.5 text-xs rounded bg-red-100 text-red-700">nieuw</span>
                                            </span>
                                        @elseif ($c['age_days'] < 14)
                                            <span class="text-red-500">{{ $c['age_days'] }}d</span>
                                        @elseif ($c['age_days'] < 90)
                                            <span class="text-amber-500">{{ $c['age_days'] }}d</span>
                                        @else
                                            {{ $c['age_days'] }}d
                                        @endif
                                    @else
                                        <span class="text-gray-400">&mdash;</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <p class="text-xs text-gray-400">
            Bron: <code>mexc_market_scan</code> | bijgewerkt {{ $fetchedAt ?? 'nooit' }}.
            Volatiliteit = 24u prijs-range (MEXC). Marketcap via
            <a href="https://www.coingecko.com" target="_blank" rel="noopener" class="underline">CoinGecko</a>.
            <span class="font-medium">Powered by CoinGecko</span>.
        </p>
    @else
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8 text-center text-gray-500">
            Nog geen scan — draai de routine <code>mexc-scan</code>.
        </div>
    @endif
</div>
