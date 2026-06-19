<div class="max-w-7xl mx-auto space-y-6">

    <div>
        <h1 class="text-2xl font-semibold text-gray-900">Munten</h1>
        <p class="text-sm text-gray-500">Gesorteerd op kansrijkheid — de munt met de meeste bewegingsruimte staat bovenaan.</p>
    </div>

    {{-- Tab-balk --}}
    <div class="flex gap-1 border-b border-gray-200">
        <a href="{{ route('coins.ranking') }}"
           class="px-4 py-2 text-sm font-medium rounded-t-lg transition
                  {{ request()->routeIs('coins.ranking') ? 'bg-white border border-b-white -mb-px text-gray-900' : 'text-gray-500 hover:text-gray-700' }}">
            Kansrijk (engine)
        </a>
        <a href="{{ route('coins.mexc') }}"
           class="px-4 py-2 text-sm font-medium rounded-t-lg transition
                  {{ request()->routeIs('coins.mexc') ? 'bg-white border border-b-white -mb-px text-gray-900' : 'text-gray-500 hover:text-gray-700' }}">
            MEXC-markt
        </a>
    </div>

    @if (count($ranking))
        @php $maxUp = max(0.01, collect($ranking)->max('up_7d')); @endphp
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 flex items-baseline justify-between">
                <h3 class="font-semibold text-gray-800">Kansrijkste munten</h3>
                <span class="text-xs text-gray-500">kans = % momenten met ≥3% stijging binnen 1 uur (7-daags) · sorteersleutel</span>
            </div>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">#</th>
                        <th class="px-4 py-3">Munt</th>
                        <th class="px-4 py-3">Kansrijk (≥3% / 1u)</th>
                        <th class="px-4 py-3 text-right">Beweeglijkheid</th>
                        <th class="px-4 py-3 text-right">Liquiditeit (ticks)</th>
                        <th class="px-4 py-3 text-right">Laatste meting</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($ranking as $i => $r)
                        <tr>
                            <td class="px-4 py-3 text-gray-400">{{ $i + 1 }}</td>
                            <td class="px-4 py-3 font-medium text-gray-800">{{ $r['symbol'] ?? $r['trading_symbol_id'] }}</td>
                            <td class="px-4 py-3">
                                @if ($r['up_7d'] !== null)
                                    <div class="flex items-center gap-2">
                                        <div class="h-2 w-28 bg-gray-100 rounded">
                                            <div class="h-2 rounded {{ $r['up_7d'] >= 0.66 * $maxUp ? 'bg-emerald-500' : ($r['up_7d'] >= 0.33 * $maxUp ? 'bg-amber-400' : 'bg-gray-300') }}"
                                                 style="width: {{ max(3, min(100, $r['up_7d'] / $maxUp * 100)) }}%"></div>
                                        </div>
                                        <span class="tabular-nums text-gray-700">{{ number_format((float) $r['up_7d'], 1, ',', '.') }}%</span>
                                    </div>
                                @else
                                    <span class="text-gray-400">— te weinig data</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-gray-600">{{ $r['vol_7d'] !== null ? number_format((float) $r['vol_7d'], 2, ',', '.') : '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-gray-600">{{ number_format((int) $r['n_ticks'], 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right text-gray-500">{{ $r['date'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-400">
            Bron: <code>coin_daily_metrics</code>, dagelijks bijgewerkt door de routine <code>coin-metrics</code>.
            Kansrijk voorspelt winst-per-trade beter dan volume; volume is alleen liquiditeit.
        </p>
    @else
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8 text-center text-gray-500">
            Nog geen metrics. Draai de routine <code>coin-metrics</code> om de tabel te vullen.
        </div>
    @endif
</div>
