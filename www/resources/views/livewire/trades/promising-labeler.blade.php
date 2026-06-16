@php
    $klc = fn ($k) => match ($k) {
        'goed' => 'text-emerald-400', 'middel' => 'text-orange-400',
        'slecht' => 'text-rose-400', default => 'text-slate-500',
    };
    $upc = function ($v) {
        if ($v === null) return 'text-slate-600';
        if ($v < 0) return 'text-rose-400';
        if ($v >= 3) return 'text-emerald-400';
        if ($v >= 0.5) return 'text-amber-300';
        return 'text-slate-500';
    };
    $fmt = fn ($v, $d = 2) => $v === null ? '—' : number_format((float) $v, $d);
    // gekleurde linker-rand per groep (zelfde stijging = zelfde kleur, opeenvolgende rijen verbonden)
    $grpBorder = function ($g) {
        if (! $g) return 'border-l-4 border-transparent';
        $c = ['border-emerald-500', 'border-sky-500', 'border-violet-500', 'border-amber-500'];
        return 'border-l-4 ' . $c[$g % 4];
    };
@endphp

<div class="p-6 text-slate-200 bg-slate-950 min-h-screen">
    <div class="flex flex-wrap items-center gap-3 mb-5">
        <h1 class="text-xl font-semibold text-white mr-2">Promising labeler</h1>

        <select wire:model.live="coin" class="bg-slate-800 border-slate-700 rounded-lg text-sm py-1.5">
            @foreach ($this->coins() as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>

        <div class="flex items-center gap-1">
            <button wire:click="step(-1)" class="px-2.5 py-1.5 bg-slate-800 hover:bg-slate-700 rounded-lg text-sm">‹ dag</button>
            <input type="date" wire:model.live="date" class="bg-slate-800 border-slate-700 rounded-lg text-sm py-1.5">
            <button wire:click="step(1)" class="px-2.5 py-1.5 bg-slate-800 hover:bg-slate-700 rounded-lg text-sm">dag ›</button>
        </div>

        <select wire:model.live="view" class="bg-slate-800 border-slate-700 rounded-lg text-sm py-1.5" title="filter">
            <option value="promising">alleen promising</option>
            <option value="all">alle momenten</option>
            <option value="trades">alleen trades</option>
            <option value="executed">alleen uitgevoerd</option>
        </select>

        <span class="text-xs text-slate-500" title="promising = max upside ≥ 3% (op +15m of later) EN vroege dip ≥ −0,5%">promising-regel ⓘ</span>

        <span class="text-xs text-slate-400">{{ $dayCount }} dagen</span>

        <div class="ml-auto flex items-center gap-2 text-sm">
            <span class="px-2 py-1 rounded bg-slate-800 text-slate-300">{{ count($rows) }}{{ $truncated ? '/'.$total : '' }} momenten</span>
            <span class="px-2 py-1 rounded bg-amber-900/60 text-amber-300">{{ $labeledCount }} gelabeld</span>
        </div>
    </div>

    @if ($truncated)
        <div class="mb-4 text-xs text-amber-300 bg-amber-950/40 border border-amber-900/50 rounded-lg px-3 py-2">
            {{ $total }} momenten voldoen aan het filter — eerste {{ $rowCap }} getoond. Kies een ander filter om te verfijnen.
        </div>
    @endif

    <div class="bg-slate-900/60 border border-slate-800 rounded-xl p-4 mb-6">
        <div wire:ignore wire:key="chart-{{ $coin }}-{{ $date }}" x-data="labelerChart(@js($chart))">
            <div class="flex justify-end mb-1">
                <button @click="chart && chart.resetZoom()" class="text-xs text-slate-400 hover:text-white">↺ zoom reset</button>
            </div>
            <div class="relative h-[300px]"><canvas x-ref="cv"></canvas></div>
        </div>
        <p class="text-xs text-slate-500 mt-2">
            Stippen = uitgevoerde trades. Klik een rij voor de grafiek + kwaliteit/reden.
            <span class="text-rose-400">Rode rij</span> = upside was er (&gt;1%) maar onze sell-winst is negatief → sell-engine liet geld liggen.
        </p>
    </div>

    <div class="overflow-x-auto rounded-lg border border-slate-800">
        <table class="w-full text-sm whitespace-nowrap">
            <thead class="bg-slate-800/60 text-slate-400">
                <tr>
                    <th class="text-left px-3 py-2">tijd</th>
                    <th class="text-left px-3 py-2">trade</th>
                    <th class="text-center px-2 py-2" title="volume-rule (volume_found) op deze tick">vol</th>
                    <th class="text-center px-3 py-2">ok?</th>
                    @foreach ($horizons as $h)
                        <th class="text-right px-2 py-2">+{{ $h }}m</th>
                    @endforeach
                    <th class="text-right px-2 py-2">max up%</th>
                    <th class="text-right px-2 py-2">dip%</th>
                    <th class="text-right px-3 py-2">onze sell%</th>
                    <th class="text-left px-2 py-2">auto</th>
                    <th class="text-left px-2 py-2">legacy</th>
                    <th class="text-left px-2 py-2">mijn</th>
                    <th class="text-left px-2 py-2" title="groep van instapmomenten in dezelfde stijging">groep</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $r)
                    <tr wire:key="row-{{ $r['key'] }}" wire:click="selectMoment('{{ $r['key'] }}')"
                        class="border-t border-slate-800 cursor-pointer hover:bg-slate-800/40 {{ $grpBorder($r['group']) }}
                               {{ $r['sell_gap'] ? 'bg-rose-950/40' : '' }}
                               {{ $selKey === $r['key'] ? 'bg-slate-800/60' : '' }}">
                        <td class="px-3 py-1.5 font-mono text-xs">{{ $r['time'] }}</td>
                        <td class="px-3 py-1.5 text-xs">
                            @if ($r['is_trade'])
                                <span class="text-sky-300">R{{ $r['rule'] }}</span>
                                @unless ($r['is_executed'])<span class="text-slate-500" title="schaduw">·s</span>@endunless
                            @else
                                <span class="text-slate-600">—</span>
                            @endif
                        </td>
                        <td class="px-2 py-1.5 text-center text-xs">{{ $r['vol'] ? '✓' : '' }}</td>
                        <td class="px-3 py-1.5 text-center">
                            <div class="inline-flex gap-1">
                                <button wire:click.stop="setDecision('{{ $r['key'] }}', 'yes')"
                                        class="px-1.5 py-0.5 rounded text-xs font-semibold transition
                                               {{ $r['decision'] === 'yes' ? 'bg-emerald-600 text-white' : 'text-emerald-400 hover:bg-emerald-900/50' }}"
                                        title="ok (klik nogmaals = wissen)">✓</button>
                                <button wire:click.stop="setDecision('{{ $r['key'] }}', 'no')"
                                        class="px-1.5 py-0.5 rounded text-xs font-semibold transition
                                               {{ $r['decision'] === 'no' ? 'bg-rose-600 text-white' : 'text-rose-400 hover:bg-rose-900/50' }}"
                                        title="niet ok">✗</button>
                            </div>
                        </td>
                        @foreach ($r['horizons'] as $hz)
                            <td class="px-2 py-1.5 text-right font-mono text-xs {{ $upc($hz['val']) }}"
                                title="piek om {{ $hz['peak_at'] ?? '—' }}">{{ $fmt($hz['val']) }}</td>
                        @endforeach
                        <td class="px-2 py-1.5 text-right font-mono text-xs {{ $upc($r['max_up']) }} font-semibold">{{ $fmt($r['max_up']) }}</td>
                        <td class="px-2 py-1.5 text-right font-mono text-xs {{ ($r['low10'] ?? 0) < -0.1 ? 'text-rose-400' : 'text-slate-500' }}">{{ $fmt($r['low10']) }}</td>
                        <td class="px-3 py-1.5 text-right font-mono text-xs {{ ($r['profit_loss'] ?? 0) < 0 ? 'text-rose-400' : 'text-slate-300' }}">{{ $r['profit_loss'] === null ? '—' : $fmt($r['profit_loss']) }}</td>
                        <td class="px-2 py-1.5 text-xs {{ $klc($r['auto']) }}">{{ $r['auto'] === 'onbekend' ? '—' : $r['auto'] }}</td>
                        <td class="px-2 py-1.5 text-xs {{ $klc($r['legacy']) }}">{{ $r['legacy'] ?? '—' }}</td>
                        <td class="px-2 py-1.5 text-xs">
                            @if ($r['manual'])
                                <span class="font-medium {{ $klc($r['manual']) }}">✎ {{ $r['manual'] }}</span>
                            @elseif ($r['decision'])
                                <span class="text-slate-400">{{ $r['decision'] }}</span>
                            @else
                                <span class="text-slate-600">—</span>
                            @endif
                        </td>
                        <td class="px-2 py-1.5 text-xs font-mono text-slate-400">{{ $r['group'] ? $r['group_lead'].' ·'.$r['group_size'] : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ 11 + count($horizons) }}" class="px-3 py-4 text-center text-slate-500">Geen momenten voor dit filter.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ───────── detail / label modal ───────── --}}
    @if ($detail)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" wire:key="detail-{{ $selKey }}">
            <div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-3xl max-h-[90vh] overflow-y-auto shadow-2xl">
                <div class="flex items-center justify-between px-5 py-3 border-b border-slate-800">
                    <div class="flex items-center gap-2">
                        <button wire:click="navDetail(-1)" title="vorige" class="px-2 py-0.5 bg-slate-800 hover:bg-slate-700 rounded text-sm">‹</button>
                        <h3 class="font-semibold text-white">{{ $detail['title'] }}</h3>
                        <button wire:click="navDetail(1)" title="volgende" class="px-2 py-0.5 bg-slate-800 hover:bg-slate-700 rounded text-sm">›</button>
                    </div>
                    <button wire:click="closeDetail" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
                </div>

                <div class="p-5">
                    <div x-data="zoomChart(@js($detail))" wire:key="zoom-{{ $selKey }}">
                        <div class="relative h-64 mb-4"><canvas x-ref="zv"></canvas></div>
                    </div>

                    <div class="flex flex-wrap gap-2 mb-4">
                        @foreach ($detail['horizons'] as $hz)
                            <div class="px-2.5 py-1 rounded-lg bg-slate-800/70 text-xs">
                                <span class="text-slate-500">+{{ $hz['h'] }}m</span>
                                <span class="font-mono {{ $upc($hz['val']) }}">{{ $fmt($hz['val']) }}%</span>
                            </div>
                        @endforeach
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-x-6 gap-y-1.5 text-sm mb-4">
                        @foreach ($detail['stats'] as $k => $v)
                            <div class="flex justify-between border-b border-slate-800/60 py-0.5">
                                <span class="text-slate-400">{{ $k }}</span>
                                <span class="font-mono text-slate-200">{{ is_numeric($v) ? rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.') : $v }}</span>
                            </div>
                        @endforeach
                    </div>

                    <div class="flex items-center gap-4 text-sm mb-4 border-t border-slate-800 pt-4">
                        <span><span class="text-slate-500">auto:</span> <span class="{{ $klc($detail['auto_klasse']) }}">{{ $detail['auto_klasse'] === 'onbekend' ? '—' : $detail['auto_klasse'] }}</span></span>
                        <span><span class="text-slate-500">legacy:</span> <span class="{{ $klc($detail['legacy_klasse']) }}">{{ $detail['legacy_klasse'] ?? '—' }}</span></span>
                        <span><span class="text-slate-500">promising:</span> <span class="{{ $detail['promising'] ? 'text-emerald-400' : 'text-slate-400' }}">{{ $detail['promising'] ? 'ja' : 'nee' }}</span></span>
                        <span><span class="text-slate-500">volume-rule:</span> <span class="{{ $detail['vol'] ? 'text-emerald-400' : 'text-slate-500' }}">{{ $detail['vol'] ? '✓' : '—' }}</span></span>
                    </div>

                    @if ($detail['is_ok'])
                        <div class="border-t border-slate-800 pt-4 mb-4">
                            <div class="flex items-center gap-2 flex-wrap mb-2">
                                <span class="text-xs text-slate-500">Groep (zelfde stijging = 1 trade):</span>
                                @if ($detail['has_prev_ok'])
                                    <button wire:click="setGroupBreak('{{ $detail['key'] }}', 'join')"
                                            class="px-2 py-1 rounded text-xs border transition {{ $detail['group_break'] === 'join' ? 'bg-sky-600 border-sky-500 text-white' : 'bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700' }}">⛓ koppel aan vorige</button>
                                @endif
                                <button wire:click="setGroupBreak('{{ $detail['key'] }}', 'break')"
                                        class="px-2 py-1 rounded text-xs border transition {{ $detail['group_break'] === 'break' ? 'bg-amber-600 border-amber-500 text-white' : 'bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700' }}">✂ ontkoppel hier</button>
                                <span class="text-xs {{ $detail['group_break'] ? 'text-amber-400' : 'text-slate-600' }}">{{ $detail['group_break'] ? 'handmatig' : 'auto' }}</span>
                            </div>
                            @if (count($detail['group']) > 1)
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($detail['group'] as $gm)
                                        <button wire:click="selectMoment('{{ $gm['key'] }}')"
                                                class="px-2 py-1 rounded text-xs font-mono border transition {{ $gm['key'] === $detail['key'] ? 'border-sky-400 bg-slate-800 text-white' : 'border-slate-700 bg-slate-800/50 text-slate-300 hover:bg-slate-700' }}">
                                            {{ $gm['time'] }}@if ($gm['manual'])<span class="{{ $klc($gm['manual']) }}"> ✎{{ $gm['manual'] }}</span>@endif
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="border-t border-slate-800 pt-4 space-y-3">
                        <h4 class="text-sm font-semibold text-slate-300">Label dit moment</h4>
                        <div class="flex flex-wrap items-center gap-3">
                            @php $actCls = ['yes' => 'bg-emerald-600 border-emerald-500 text-white', 'no' => 'bg-rose-600 border-rose-500 text-white', 'no_volume' => 'bg-amber-600 border-amber-500 text-white']; @endphp
                            <div class="flex flex-col">
                                <span class="text-xs text-slate-500 mb-0.5">beslissing (direct opgeslagen)</span>
                                <div class="inline-flex gap-1">
                                    @foreach (['yes' => 'ok (kopen)', 'no' => 'niet ok', 'no_volume' => 'geen volume'] as $val => $lbl)
                                        <button wire:click="setDecision('{{ $detail['key'] }}', '{{ $val }}')"
                                                class="px-3 py-1.5 rounded-lg text-sm border transition {{ $decision === $val ? $actCls[$val] : 'bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700' }}">{{ $lbl }}</button>
                                    @endforeach
                                </div>
                            </div>
                            <div class="flex flex-col">
                                <span class="text-xs text-slate-500 mb-0.5">kwaliteit</span>
                                <select wire:model="klasse" class="bg-slate-800 border border-slate-700 rounded-lg text-sm py-1.5 px-2 text-slate-200">
                                    <option value="">— berekend ({{ $detail['auto_klasse'] === 'onbekend' ? '—' : $detail['auto_klasse'] }}) —</option>
                                    <option value="goed">goed (≥3%)</option>
                                    <option value="middel">middel (0.5–3%)</option>
                                    <option value="slecht">slecht (&lt;0.5%)</option>
                                </select>
                            </div>
                            <div class="flex flex-col">
                                <span class="text-xs text-slate-500 mb-0.5">reden</span>
                                <select wire:model="category" class="bg-slate-800 border border-slate-700 rounded-lg text-sm py-1.5 px-2 text-slate-200 min-w-[12rem]">
                                    <option value="">—</option>
                                    @foreach ($categories as $c)
                                        <option value="{{ $c }}">{{ $c }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <textarea wire:model="comment" rows="2" placeholder="opmerking (optioneel)"
                                  class="w-full bg-slate-800 border border-slate-700 rounded-lg text-sm py-1.5 px-2 text-slate-200 placeholder-slate-500 resize-none"></textarea>
                        <div class="flex items-center gap-3">
                            <button wire:click="saveLabel" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg text-sm font-medium">Opslaan</button>
                            <span x-data="flashMsg()" x-show="shown" x-on:label-saved.window="flash()" class="text-xs text-emerald-400">opgeslagen ✓</span>
                            <span class="text-xs text-slate-500">leeg + opslaan = label intrekken</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

@push('scripts')
<script>
function flashMsg() { return { shown: false, flash() { this.shown = true; setTimeout(() => { this.shown = false; }, 1500); } }; }
function __ann() { if (window.__annReg !== true && window['chartjs-plugin-annotation']) { Chart.register(window['chartjs-plugin-annotation']); window.__annReg = true; } }
function __xaxis() { return { type: 'linear', ticks: { color: '#64748b', maxTicksLimit: 10,
    callback: (v) => new Date(v).toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit', timeZone: 'UTC' }) },
    grid: { color: 'rgba(51,65,85,0.3)' } }; }

const __crosshair = { id: 'crosshair', afterDraw(chart) {
    const act = chart.tooltip?.getActiveElements?.() || [];
    if (!act.length) return;
    const x = act[0].element.x, { top, bottom } = chart.chartArea, ctx = chart.ctx;
    ctx.save(); ctx.beginPath(); ctx.moveTo(x, top); ctx.lineTo(x, bottom);
    ctx.lineWidth = 1; ctx.strokeStyle = 'rgba(148,163,184,0.55)'; ctx.setLineDash([3, 3]); ctx.stroke(); ctx.restore();
} };

function __baseOptions(withZoom) {
    const o = {
        responsive: true, maintainAspectRatio: false, animation: false,
        interaction: { mode: 'index', intersect: false },
        scales: { x: __xaxis(), y: { ticks: { color: '#64748b' }, grid: { color: 'rgba(51,65,85,0.3)' } } },
        plugins: { legend: { display: false }, tooltip: { callbacks: {
            title: (it) => new Date(it[0].parsed.x).toLocaleTimeString('nl-NL', { timeZone: 'UTC' }),
            label: (it) => 'prijs: ' + it.parsed.y } } },
    };
    if (withZoom) o.plugins.zoom = {
        zoom: { wheel: { enabled: true }, pinch: { enabled: true }, drag: { enabled: false }, mode: 'x' },
        pan: { enabled: true, mode: 'x' },
    };
    return o;
}

function labelerChart(data) {
    return {
        chart: null,
        init() {
            __ann();
            const ex = Chart.getChart(this.$refs.cv); if (ex) ex.destroy();
            const annotations = {};
            const klc = { goed: 'rgba(16,185,129,0.9)', middel: 'rgba(251,146,60,0.9)', slecht: 'rgba(244,63,94,0.9)' };
            (data.fires || []).forEach((f, i) => { const col = klc[f.klasse] || 'rgba(148,163,184,0.8)';
                annotations['fire' + i] = { type: 'line', xMin: f.x, xMax: f.x,
                    borderColor: col, borderWidth: 1.5, borderDash: [4, 3],
                    label: { display: true, content: 'R' + f.rule, position: 'start', backgroundColor: col, color: '#fff', font: { size: 9 }, padding: 2 } }; });
            const opts = __baseOptions(true);
            opts.plugins.annotation = { annotations };
            this.chart = new Chart(this.$refs.cv, { type: 'line', plugins: [__crosshair],
                data: { datasets: [{ label: 'prijs', data: data.price, borderColor: 'rgba(148,163,184,0.9)', borderWidth: 1.2, pointRadius: 0, tension: 0.1, parsing: false }] },
                options: opts });
        },
    };
}

function zoomChart(d) {
    return {
        chart: null,
        init() {
            __ann();
            const ex = Chart.getChart(this.$refs.zv); if (ex) ex.destroy();
            const m = d.markers || {}, ann = {};
            if (m.pfrom && m.pto) ann.band = { type: 'box', xMin: m.pfrom, xMax: m.pto,
                backgroundColor: 'rgba(16,185,129,0.10)', borderColor: 'rgba(16,185,129,0.35)', borderWidth: 1 };
            const line = (x, color, txt, pos) => ({ type: 'line', xMin: x, xMax: x, borderColor: color, borderWidth: 1.5,
                label: { display: true, content: txt, position: pos || 'start', backgroundColor: color, color: '#fff', font: { size: 9 }, padding: 2 } });
            if (m.buy) ann.buy = line(m.buy, 'rgba(56,189,248,0.95)', 'koop');
            if (m.sell) ann.sell = line(m.sell, 'rgba(244,63,94,0.95)', 'onze sell');
            if (m.bestsell) ann.bestsell = line(m.bestsell, 'rgba(168,85,247,0.95)', 'beste sell', 'end');
            if (m.peak) ann.peak = line(m.peak, 'rgba(251,191,36,0.95)', 'piek', 'end');
            ['h5','h10','h15','h30','h45','h60'].forEach((k) => {
                if (m[k]) ann[k] = { type: 'line', xMin: m[k], xMax: m[k], borderColor: 'rgba(148,163,184,0.3)', borderWidth: 1, borderDash: [2, 2] };
            });
            const opts = __baseOptions(true);
            opts.plugins.annotation = { annotations: ann };
            this.chart = new Chart(this.$refs.zv, { type: 'line', plugins: [__crosshair],
                data: { datasets: [{ data: d.price, borderColor: 'rgba(148,163,184,0.95)', borderWidth: 1.3, pointRadius: 0, tension: 0.1, parsing: false }] },
                options: opts });
        },
    };
}
</script>
@endpush
