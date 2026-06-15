<div class="p-6 text-slate-200 bg-slate-950 min-h-screen">
    <div class="flex flex-wrap items-center gap-3 mb-5">
        <h1 class="text-xl font-semibold text-white mr-2">Coin explorer</h1>

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

        <span class="text-xs text-slate-400">{{ $dayCount }} dagen met perioden</span>

        <div class="ml-auto flex items-center gap-2 text-sm">
            <span class="px-2 py-1 rounded bg-emerald-900/60 text-emerald-300">{{ $periods->count() }} promising</span>
            <span class="px-2 py-1 rounded bg-emerald-900/60 text-emerald-300">{{ $goedToday }} goed</span>
            <span class="px-2 py-1 rounded bg-orange-900/60 text-orange-300">{{ $middelToday }} middel</span>
            <span class="px-2 py-1 rounded bg-rose-900/60 text-rose-300">{{ $slechtToday }} slecht</span>
        </div>
    </div>

    <div class="bg-slate-900/60 border border-slate-800 rounded-xl p-4 mb-6">
        <div wire:ignore wire:key="chart-{{ $coin }}-{{ $date }}" x-data="coinChart(@js($chart))">
            <div class="flex justify-end mb-1">
                <button @click="chart && chart.resetZoom()" class="text-xs text-slate-400 hover:text-white">↺ zoom reset</button>
            </div>
            <div class="relative h-[420px]"><canvas x-ref="cv"></canvas></div>
        </div>
        <p class="text-xs text-slate-500 mt-2">
            Klik op een trade-stip of een groene periode (of een rij hieronder) voor detail + label.
            Scroll = in/uitzoomen op tijd, sleep = schuiven. Groene vlakken = promising. Stippen = trades (groen = in promising, rood = buiten).
        </p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div>
            <h2 class="text-sm font-semibold text-slate-300 mb-2">Trades deze dag ({{ $fires->count() }})</h2>
            <div class="overflow-x-auto rounded-lg border border-slate-800">
                <table class="w-full text-sm">
                    <thead class="bg-slate-800/60 text-slate-400">
                        <tr><th class="text-left px-3 py-2">tijd</th><th class="text-left px-3 py-2">rule</th>
                            <th class="text-left px-3 py-2">uitkomst</th><th class="text-right px-3 py-2">beste up%</th>
                            <th class="text-right px-3 py-2">onze sell%</th><th class="text-left px-3 py-2">label</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($fires as $f)
                            @php $sh = ! $f->is_executed; [$kl, $klc] = $f->klasse(); @endphp
                            <tr wire:click="selectFire({{ $f->id }})"
                                class="border-t border-slate-800 cursor-pointer hover:bg-slate-800/40 {{ $sh ? 'opacity-50' : '' }} {{ $selType==='fire' && $selId===$f->id ? 'bg-slate-800/60' : '' }}">
                                <td class="px-3 py-1.5 font-mono text-xs {{ $sh ? 'text-slate-500' : '' }}">{{ $f->datetime->format('H:i:s') }}</td>
                                <td class="px-3 py-1.5 {{ $sh ? 'text-slate-500' : '' }}">{{ $f->rule }}</td>
                                <td class="px-3 py-1.5">
                                    @if ($sh)
                                        <span class="text-slate-500" title="zit in de trade van {{ optional($f->shadow_parent)->format('H:i:s') }}">↳ in trade {{ optional($f->shadow_parent)->format('H:i:s') }}</span>
                                    @else
                                        <span class="{{ $klc }}">{{ $kl }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-1.5 text-right font-mono {{ $sh ? 'text-slate-500' : 'text-emerald-300' }}">{{ $f->best_upside !== null ? number_format($f->best_upside, 2) : '—' }}</td>
                                <td class="px-3 py-1.5 text-right font-mono {{ $sh ? 'text-slate-500' : (($f->profit_loss ?? 0) >= 0 ? 'text-slate-300' : 'text-rose-400') }}">{{ ($f->is_executed && $f->profit_loss !== null) ? number_format($f->profit_loss, 2) : '—' }}</td>
                                <td class="px-3 py-1.5 text-xs text-amber-300">{{ optional($annotations->get('fire:'.$f->id))->category }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-4 text-center text-slate-500">Geen trades deze dag.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div>
            <h2 class="text-sm font-semibold text-slate-300 mb-2">Promising perioden deze dag ({{ $periods->count() }})</h2>
            <div class="overflow-x-auto rounded-lg border border-slate-800">
                <table class="w-full text-sm">
                    <thead class="bg-slate-800/60 text-slate-400">
                        <tr><th class="text-left px-3 py-2">van</th><th class="text-left px-3 py-2">tot</th>
                            <th class="text-left px-3 py-2">beste instap</th><th class="text-right px-3 py-2">upside %</th>
                            <th class="text-left px-3 py-2">label</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($periods as $p)
                            <tr wire:click="selectPeriod({{ $p->id }})"
                                class="border-t border-slate-800 cursor-pointer hover:bg-slate-800/40 {{ $selType==='period' && $selId===$p->id ? 'bg-slate-800/60' : '' }}">
                                <td class="px-3 py-1.5 font-mono text-xs">{{ $p->period_from->format('H:i:s') }}</td>
                                <td class="px-3 py-1.5 font-mono text-xs">{{ $p->period_to->format('H:i:s') }}</td>
                                <td class="px-3 py-1.5 font-mono text-xs text-emerald-300">{{ $p->best_entry->format('H:i:s') }}</td>
                                <td class="px-3 py-1.5 text-right font-mono text-emerald-400">{{ number_format($p->best_upside, 2) }}</td>
                                <td class="px-3 py-1.5 text-xs text-amber-300">{{ optional($annotations->get('period:'.$p->id))->category }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-4 text-center text-slate-500">Geen perioden deze dag.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ───────── detail modal ───────── --}}
    @if ($detail)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" wire:key="detail-{{ $selType }}-{{ $selId }}">
            <div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-3xl max-h-[90vh] overflow-y-auto shadow-2xl">
                <div class="flex items-center justify-between px-5 py-3 border-b border-slate-800">
                    <div class="flex items-center gap-2">
                        <button wire:click="navDetail(-1)" title="vorige" class="px-2 py-0.5 bg-slate-800 hover:bg-slate-700 rounded text-sm">‹</button>
                        <div class="flex items-baseline gap-2">
                            <h3 class="font-semibold text-white">{{ $detail['title'] }}</h3>
                            <span class="text-xs text-slate-500 font-mono">#{{ $detail['id'] }}</span>
                        </div>
                        <button wire:click="navDetail(1)" title="volgende" class="px-2 py-0.5 bg-slate-800 hover:bg-slate-700 rounded text-sm">›</button>
                    </div>
                    <button wire:click="closeDetail" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
                </div>

                <div class="p-5">
                    <div x-data="zoomChart(@js($detail))" wire:key="zoom-{{ $selType }}-{{ $selId }}">
                        <div class="relative h-64 mb-4"><canvas x-ref="zv"></canvas></div>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-x-6 gap-y-1.5 text-sm mb-4">
                        @foreach ($detail['stats'] as $k => $v)
                            <div class="flex justify-between border-b border-slate-800/60 py-0.5">
                                <span class="text-slate-400">{{ $k }}</span>
                                <span class="font-mono text-slate-200">{{ is_numeric($v) ? rtrim(rtrim(number_format((float)$v, 6, '.', ''), '0'), '.') : $v }}</span>
                            </div>
                        @endforeach
                    </div>

                    {{-- uitkomst override (alleen voor uitgevoerde trades; schaduwen traden niet) --}}
                    @if ($selType === 'fire' && ($detail['is_executed'] ?? false))
                    <div class="border-t border-slate-800 pt-4 mb-4">
                        <div class="flex items-center gap-3 flex-wrap">
                            <span class="text-sm text-slate-400 shrink-0">Uitkomst overschrijven:</span>
                            <select wire:model.live="manualKlasse"
                                    class="bg-slate-800 border border-slate-700 rounded-lg text-sm py-1.5 px-2 text-slate-200">
                                <option value="">— berekend ({{ $detail['auto_klasse'] ?? '?' }}) —</option>
                                <option value="goed">goed (≥3%)</option>
                                <option value="middel">middel (0.5–3%)</option>
                                <option value="slecht">slecht (&lt;0.5%)</option>
                            </select>
                            <button wire:click="saveManualKlasse"
                                    class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white rounded-lg text-sm">Opslaan</button>
                            @if ($detail['manual_klasse'])
                                <span class="text-xs text-amber-400 font-medium">✎ handmatig: {{ $detail['manual_klasse'] }}</span>
                            @endif
                        </div>
                        <p class="text-xs text-slate-500 mt-1.5">Opgeslagen in <code>coin_moment_labels</code> (overleeft een re-fire). Wordt zichtbaar in de Promising labeler.</p>
                    </div>
                    @endif

                    <div class="border-t border-slate-800 pt-4">
                        <h4 class="text-sm font-semibold text-slate-300 mb-2">Label dit {{ $selType === 'fire' ? 'trade' : 'moment' }}</h4>
                        <div class="flex items-center gap-3 mb-2">
                            <select wire:model="annCategory"
                                    class="bg-slate-800 border border-slate-700 rounded-lg text-sm py-1.5 px-2 text-slate-200 min-w-[14rem]">
                                <option value="">— kies —</option>
                                @foreach ($categories as $c)
                                    <option value="{{ $c }}">{{ $c }}</option>
                                @endforeach
                            </select>
                            <button wire:click="saveAnnotation"
                                    class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg text-sm font-medium">Opslaan</button>
                            <span x-data="annFlash()" x-show="shown" x-on:annotation-saved.window="flash()"
                                  class="text-xs text-emerald-400">opgeslagen ✓</span>
                        </div>
                        <textarea wire:model="annComment" rows="2" placeholder="opmerking (optioneel)"
                                  class="w-full bg-slate-800 border border-slate-700 rounded-lg text-sm py-1.5 px-2 text-slate-200 placeholder-slate-500 resize-none"></textarea>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

@push('scripts')
<script>
function annFlash() { return { shown: false, flash() { this.shown = true; setTimeout(() => { this.shown = false; }, 1500); } }; }
function __ann() { if (window.__annReg !== true && window['chartjs-plugin-annotation']) { Chart.register(window['chartjs-plugin-annotation']); window.__annReg = true; } }
function __xaxis() { return { type: 'linear', ticks: { color: '#64748b', maxTicksLimit: 10,
    callback: (v) => new Date(v).toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' }) },
    grid: { color: 'rgba(51,65,85,0.3)' } }; }

// vertical crosshair line at the hovered point
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
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: {
                title: (it) => new Date(it[0].parsed.x).toLocaleTimeString('nl-NL'),
                label: (it) => 'prijs: ' + it.parsed.y,
            } },
        },
    };
    if (withZoom) o.plugins.zoom = {
        zoom: { wheel: { enabled: true }, pinch: { enabled: true }, drag: { enabled: false }, mode: 'x' },
        pan: { enabled: true, mode: 'x' },
    };
    return o;
}

function coinChart(data) {
    return {
        chart: null,
        init() {
            __ann();
            const ex = Chart.getChart(this.$refs.cv); if (ex) ex.destroy();
            const annotations = {};
            (data.periods || []).forEach((p, i) => { annotations['per' + i] = { type: 'box', xMin: p.from, xMax: p.to, backgroundColor: 'rgba(16,185,129,0.12)', borderWidth: 0 }; });
            const klc = { goed: 'rgba(16,185,129,0.9)', middel: 'rgba(251,146,60,0.9)', slecht: 'rgba(244,63,94,0.9)' };
            (data.fires || []).forEach((f, i) => { const col = klc[f.klasse] || 'rgba(148,163,184,0.8)';
                annotations['fire' + i] = { type: 'line', xMin: f.x, xMax: f.x,
                borderColor: col, borderWidth: 1.5, borderDash: [4, 3],
                label: { display: true, content: 'R' + f.rule, position: 'start', backgroundColor: col, color: '#fff', font: { size: 9 }, padding: 2 } }; });
            const wire = this.$wire, opts = __baseOptions(true);
            opts.plugins.annotation = { annotations };
            opts.onClick = (e) => {
                const x = this.chart.scales.x.getValueForPixel(e.x);
                let best = null, bd = Infinity;
                (data.fires || []).forEach(f => { const d = Math.abs(f.x - x); if (d < bd) { bd = d; best = f; } });
                if (best && bd < 150000) { wire.selectFire(best.id); return; }
                const per = (data.periods || []).find(p => x >= p.from && x <= p.to);
                if (per) wire.selectPeriod(per.id);
            };
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
            if (m.pbest) ann.pbest = line(m.pbest, 'rgba(16,185,129,0.95)', 'beste instap', 'end');
            if (m.peak) ann.peak = line(m.peak, 'rgba(251,191,36,0.95)', 'piek (beste exit)', 'end');
            if (m.buy) ann.buy = line(m.buy, 'rgba(56,189,248,0.95)', 'koop');
            if (m.sell) ann.sell = line(m.sell, 'rgba(244,63,94,0.95)', 'onze verkoop');
            if (m.bestsell) ann.bestsell = line(m.bestsell, 'rgba(168,85,247,0.95)',
                'beste sell' + (d.bestsell_pct != null ? ' (' + (d.bestsell_pct >= 0 ? '+' : '') + d.bestsell_pct + '%)' : ''), 'end');
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
