<div class="max-w-7xl mx-auto space-y-6">

    <div>
        <h1 class="text-2xl font-semibold text-gray-900">Trades</h1>
        <p class="text-sm text-gray-500">Onze fires uit brain — per coin, rule en uitkomst.</p>
    </div>

    {{-- Filter bar --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 text-sm">
            <label class="flex flex-col gap-1">
                <span class="text-xs font-medium text-gray-500">Coin</span>
                <select wire:model.live="coin" class="rounded-lg border-gray-300 text-sm">
                    <option value="">Alle</option>
                    @foreach ($coins as $c)
                        <option value="{{ $c->trading_symbol_id }}">{{ $c->symbol }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs font-medium text-gray-500">Rule</span>
                <select wire:model.live="rule" class="rounded-lg border-gray-300 text-sm">
                    <option value="">Alle</option>
                    @foreach ($rules as $r)
                        <option value="{{ $r }}">Rule {{ $r }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs font-medium text-gray-500">Uitkomst</span>
                <select wire:model.live="outcome" class="rounded-lg border-gray-300 text-sm">
                    <option value="">Alle</option>
                    <option value="good">Goed (promising)</option>
                    <option value="bad">Buiten</option>
                    <option value="shadow">Schaduw</option>
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs font-medium text-gray-500">Van</span>
                <input type="date" wire:model.live="from" class="rounded-lg border-gray-300 text-sm">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs font-medium text-gray-500">Tot</span>
                <input type="date" wire:model.live="to" class="rounded-lg border-gray-300 text-sm">
            </label>
            <div class="flex items-end">
                <button wire:click="resetFilters" class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50">Reset</button>
            </div>
        </div>

        {{-- outcome pills: aantal + opgetelde P&L (onze sell) --}}
        @php $pct = fn ($v) => ($v >= 0 ? '+' : '').number_format((float) $v, 1, ',', '.').'%'; @endphp
        <div class="mt-3 flex flex-wrap gap-2 text-xs">
            <span class="px-2.5 py-1 rounded-full bg-gray-100 text-gray-700">
                Uitgevoerd {{ number_format($totals['n'], 0, ',', '.') }} ·
                <span class="font-semibold {{ $totals['pl'] >= 0 ? 'text-green-700' : 'text-red-700' }}">{{ $pct($totals['pl']) }}</span>
            </span>
            <span class="px-2.5 py-1 rounded-full bg-green-100 text-green-800">Goed {{ $pills['good']['n'] }} · {{ $pct($pills['good']['pl']) }}</span>
            <span class="px-2.5 py-1 rounded-full bg-red-100 text-red-800">Buiten {{ $pills['bad']['n'] }} · {{ $pct($pills['bad']['pl']) }}</span>
            <span class="px-2.5 py-1 rounded-full bg-gray-200 text-gray-600">Schaduw {{ $pills['shadow']['n'] }}</span>
        </div>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">Datumtijd</th>
                    <th class="px-4 py-3">Coin</th>
                    <th class="px-4 py-3">Rule</th>
                    <th class="px-4 py-3 text-right">Aankoop</th>
                    <th class="px-4 py-3 text-right">Verkoop</th>
                    <th class="px-4 py-3 text-right">P&L (onze sell)</th>
                    <th class="px-4 py-3 text-center">Uitkomst</th>
                    <th class="px-4 py-3 text-center">Legacy</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @php($fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 10, '.', ''), '0'), '.'))
                @forelse ($trades as $t)
                    <tr class="hover:bg-gray-50 {{ $t->is_executed ? '' : 'opacity-50' }}">
                        <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $t->datetime?->format('d-m-Y H:i:s') }}</td>
                        <td class="px-4 py-3 text-gray-700">{{ $t->symbol }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $t->rule }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ $fmt($t->buy_price) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-gray-500">{{ $fmt($t->selling_price) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums font-medium {{ ($t->profit_loss ?? 0) > 0 ? 'text-green-600' : (($t->profit_loss ?? 0) < 0 ? 'text-red-600' : 'text-gray-500') }}">
                            {{ ($t->is_executed && $t->profit_loss !== null) ? number_format($t->profit_loss, 2, ',', '.').'%' : '—' }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if (! $t->is_executed)
                                <span class="text-xs text-gray-400" title="zit in trade van {{ optional($t->shadow_parent)->format('H:i:s') }}">↳ schaduw</span>
                            @elseif ($t->in_good_period)
                                <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800">Goed</span>
                            @else
                                <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800">Buiten</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center text-xs text-gray-400">
                            @switch($t->legacy_result)
                                @case(1) <span class="text-green-700">goed</span> @break
                                @case(2) <span class="text-yellow-700">middel</span> @break
                                @case(3) <span class="text-red-700">slecht</span> @break
                                @default ·
                            @endswitch
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-8 text-center text-gray-500">Geen trades voor deze filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $trades->links() }}</div>
</div>
