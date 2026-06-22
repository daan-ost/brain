<div class="max-w-7xl mx-auto space-y-6">

    <div>
        <h1 class="text-2xl font-semibold text-gray-900">Trades</h1>
        <p class="text-sm text-gray-500">Onze trades uit brain — per coin, rule en uitkomst.</p>
    </div>

    {{-- Tabs --}}
    <div class="border-b border-gray-200">
        <nav class="-mb-px flex gap-6 text-sm font-medium">
            <button type="button" wire:click="setTab('summary')"
                class="border-b-2 px-1 py-3 transition
                       {{ $tab === 'summary' ? 'border-emerald-500 text-emerald-700' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                Samenvatting
            </button>
            <button type="button" wire:click="setTab('list')"
                class="border-b-2 px-1 py-3 transition
                       {{ $tab === 'list' ? 'border-emerald-500 text-emerald-700' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                Trades
            </button>
        </nav>
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
                    <option value="goed">Goed (≥3%)</option>
                    <option value="middel">Middel (0,5–3%)</option>
                    <option value="slecht">Slecht (&lt;0,5%)</option>
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

        {{-- class pills: aantal + gem. winst + totale winst per klasse (op gerealiseerde profit_loss) --}}
        @php $pct = fn ($v) => ($v >= 0 ? '+' : '').number_format((float) $v, 1, ',', '.').'%'; @endphp
        <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
            <span class="px-2.5 py-1 rounded-full bg-gray-100 text-gray-700">
                Uitgevoerd {{ number_format($totals['n'], 0, ',', '.') }} ·
                ⌀winst {{ $pct($totals['avg']) }} · Σwinst <span class="font-semibold {{ $totals['pl'] >= 0 ? 'text-green-700' : 'text-red-700' }}">{{ $pct($totals['pl']) }}</span>
            </span>
            <span class="px-2.5 py-1 rounded-full bg-green-100 text-green-800">Goed {{ $pills['goed']['n'] }} · ⌀{{ $pct($pills['goed']['avg']) }} · Σ {{ $pct($pills['goed']['pl']) }}</span>
            <span class="px-2.5 py-1 rounded-full bg-orange-100 text-orange-800">Middel {{ $pills['middel']['n'] }} · Σ {{ $pct($pills['middel']['pl']) }}</span>
            <span class="px-2.5 py-1 rounded-full bg-red-100 text-red-800">Slecht {{ $pills['slecht']['n'] }} · Σ {{ $pct($pills['slecht']['pl']) }}</span>
            <span class="px-2.5 py-1 rounded-full bg-indigo-100 text-indigo-800"
                  title="Promising kansen (goede koop-stijgingen, één positie tegelijk: overlappende momenten binnen hetzelfde hold-window tellen als één trade) met Σ sell-winst van het vroegste instapmoment, en welk deel we verhandeld hebben. Het totaal is rule-onafhankelijk; het verhandeld-deel volgt wél de rule-filter.">
                Promising {{ number_format($promPill['n'], 0, ',', '.') }} · Σ {{ $pct($promPill['pl']) }} ·
                <span class="font-semibold">{{ number_format($promPill['pct'], 1, ',', '.') }}% verhandeld</span>
                ({{ $promPill['traded'] }}/{{ $promPill['n'] }})
            </span>
        </div>
    </div>

    @if ($tab === 'summary')
        {{-- Samenvatting: per maand per coin — aantal + Σwinst per klasse + totaal --}}
        @php
            $pct = $pct ?? fn ($v) => ($v >= 0 ? '+' : '').number_format((float) $v, 1, ',', '.').'%';
            // groepeer per maand zodat we per maand een sub-header kunnen tonen
            $byMonth = collect($summary)->groupBy('ym');
        @endphp
        @php
            // ingedikte klasse-cel: "n (±x,x%)" — aantal met Σwinst tussen haakjes
            $clsCell = fn ($n, $pl, $color) => $n == 0
                ? '<span class="text-gray-300">0</span>'
                : '<span class="'.$color.' font-medium">'.$n.'</span> <span class="'.$color.' opacity-60 text-xs">('.$pct($pl).')</span>';
        @endphp
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Maand</th>
                        <th class="px-4 py-3">Coin</th>
                        <th class="px-4 py-3 text-right">Goed</th>
                        <th class="px-4 py-3 text-right">Middel</th>
                        <th class="px-4 py-3 text-right">Slecht</th>
                        <th class="px-4 py-3 text-right">Trades</th>
                        <th class="px-4 py-3 text-right">Σ winst</th>
                        <th class="px-4 py-3 text-right border-l border-gray-200" title="Promising kansen: goede koop-stijgingen (max 60min ≥ 3% & geen vroege dip), gegroepeerd per hold-window (één positie tegelijk — overlappende momenten tellen als één trade). Winst = die van het vroegste instapmoment (pakt de meeste upside). De potentie.">Promising</th>
                        <th class="px-4 py-3 text-right" title="Hoeveel van de promising rises we daadwerkelijk verhandeld hebben. Het verschil (+N) = potentie voor nieuwe rules.">Verhandeld</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($byMonth as $ym => $rows)
                        @php
                            $mTot = ['n' => 0, 'g' => 0, 'm' => 0, 's' => 0, 'pl' => 0.0,
                                     'plG' => 0.0, 'plM' => 0.0, 'plS' => 0.0,
                                     'pn' => 0, 'ppl' => 0.0, 'pt' => 0];
                            foreach ($rows as $r) {
                                $mTot['n']   += (int) $r['n_total'];
                                $mTot['g']   += (int) $r['n_goed'];
                                $mTot['m']   += (int) $r['n_middel'];
                                $mTot['s']   += (int) $r['n_slecht'];
                                $mTot['plG'] += (float) $r['pl_goed'];
                                $mTot['plM'] += (float) $r['pl_middel'];
                                $mTot['plS'] += (float) $r['pl_slecht'];
                                $mTot['pl']  += (float) $r['pl_total'];
                                $mTot['pn']  += (int) $r['prom_n'];
                                $mTot['ppl'] += (float) $r['prom_pl'];
                                $mTot['pt']  += (int) $r['prom_traded'];
                            }
                            try { $mLabel = \Carbon\Carbon::createFromFormat('Y-m', $ym)->translatedFormat('F Y'); }
                            catch (\Throwable $e) { $mLabel = $ym; }
                        @endphp
                        @foreach ($rows as $r)
                            @php $miss = (int) $r['prom_n'] - (int) $r['prom_traded']; @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">
                                    @if ($loop->first){{ $mLabel }}@endif
                                </td>
                                <td class="px-4 py-3 text-gray-700">{{ $r['symbol'] }}</td>
                                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{!! $clsCell($r['n_goed'], $r['pl_goed'], 'text-emerald-700') !!}</td>
                                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{!! $clsCell($r['n_middel'], $r['pl_middel'], 'text-orange-700') !!}</td>
                                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{!! $clsCell($r['n_slecht'], $r['pl_slecht'], 'text-red-700') !!}</td>
                                <td class="px-4 py-3 text-right tabular-nums font-semibold text-gray-800">{{ $r['n_total'] }}</td>
                                <td class="px-4 py-3 text-right tabular-nums font-semibold {{ $r['pl_total'] >= 0 ? 'text-green-700' : 'text-red-700' }}">{{ $pct($r['pl_total']) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap border-l border-gray-100">
                                    @if ($r['prom_n'] > 0)
                                        <span class="font-medium text-indigo-700">{{ $r['prom_n'] }}</span>
                                        <span class="text-indigo-700 opacity-60 text-xs">({{ $pct($r['prom_pl']) }})</span>
                                    @else
                                        <span class="text-gray-300">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">
                                    @if ($r['prom_n'] > 0)
                                        <span class="font-medium text-gray-800">{{ $r['prom_traded'] }}</span><span class="text-gray-400">/{{ $r['prom_n'] }}</span>
                                        @if ($miss > 0)
                                            <span class="ml-1 inline-block rounded bg-indigo-50 px-1.5 py-0.5 text-xs text-indigo-600" title="{{ $miss }} promising momenten zonder trade — potentie voor nieuwe rules">+{{ $miss }}</span>
                                        @endif
                                    @else
                                        <span class="text-gray-300">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        @if ($rows->count() > 1)
                            @php $mMiss = $mTot['pn'] - $mTot['pt']; @endphp
                            <tr class="bg-gray-50 text-xs">
                                <td class="px-4 py-2 font-semibold text-gray-700">{{ $mLabel }} · totaal</td>
                                <td class="px-4 py-2 text-gray-500">—</td>
                                <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap font-semibold">{!! $clsCell($mTot['g'], $mTot['plG'], 'text-emerald-700') !!}</td>
                                <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap font-semibold">{!! $clsCell($mTot['m'], $mTot['plM'], 'text-orange-700') !!}</td>
                                <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap font-semibold">{!! $clsCell($mTot['s'], $mTot['plS'], 'text-red-700') !!}</td>
                                <td class="px-4 py-2 text-right tabular-nums font-bold text-gray-800">{{ $mTot['n'] }}</td>
                                <td class="px-4 py-2 text-right tabular-nums font-bold {{ $mTot['pl'] >= 0 ? 'text-green-700' : 'text-red-700' }}">{{ $pct($mTot['pl']) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap border-l border-gray-200">
                                    @if ($mTot['pn'] > 0)
                                        <span class="font-semibold text-indigo-700">{{ $mTot['pn'] }}</span>
                                        <span class="text-indigo-700 opacity-60">({{ $pct($mTot['ppl']) }})</span>
                                    @else — @endif
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap">
                                    @if ($mTot['pn'] > 0)
                                        <span class="font-semibold text-gray-800">{{ $mTot['pt'] }}</span><span class="text-gray-400">/{{ $mTot['pn'] }}</span>
                                        @if ($mMiss > 0)<span class="ml-1 inline-block rounded bg-indigo-50 px-1.5 py-0.5 text-indigo-600">+{{ $mMiss }}</span>@endif
                                    @else — @endif
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="9" class="px-4 py-8 text-center text-gray-500">Geen trades of promising momenten voor deze filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-400 px-1">
            <span class="text-indigo-600">Promising</span> = goede koop-stijgingen (max 60 min ≥ 3% & geen vroege dip onder −0,5%), gegroepeerd per hold-window (één positie tegelijk: overlappende momenten — bv. rule 20 dan rule 21 — tellen als één trade, dus geen dubbeltelling); winst = die van het vroegste instapmoment.
            <span class="font-medium">Verhandeld</span> toont hoeveel kansen een echte trade kregen; de <span class="text-indigo-600">+N</span> is de potentie die nieuwe rules nog kunnen pakken. Het promising-totaal is rule-onafhankelijk; het verhandeld-deel volgt wél de rule-filter.
        </p>
    @else
    {{-- Table --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">Datumtijd</th>
                    <th class="px-4 py-3">Coin</th>
                    <th class="px-4 py-3">Rule</th>
                    <th class="px-4 py-3 text-right">Aankoop</th>
                    <th class="px-4 py-3 text-right">Beste up%</th>
                    <th class="px-4 py-3 text-right">Onze sell%</th>
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
                        <td class="px-4 py-3 text-right tabular-nums font-medium text-emerald-600">{{ $t->best_upside !== null ? number_format($t->best_upside, 2, ',', '.').'%' : '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums {{ ($t->profit_loss ?? 0) < 0 ? 'text-red-600' : 'text-gray-500' }}">
                            {{ ($t->is_executed && $t->profit_loss !== null) ? number_format($t->profit_loss, 2, ',', '.').'%' : '—' }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if (! $t->is_executed)
                                <span class="text-xs text-gray-400" title="zit in trade van {{ optional($t->shadow_parent)->format('H:i:s') }}">↳ schaduw</span>
                            @else
                                @php([$kl, $klc] = [$t->klasseKey(), ['goed'=>'bg-green-100 text-green-800','middel'=>'bg-orange-100 text-orange-800','slecht'=>'bg-red-100 text-red-800']])
                                <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $klc[$kl] ?? 'bg-gray-100 text-gray-600' }}">{{ ucfirst($kl) }}</span>
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
    @endif
</div>
