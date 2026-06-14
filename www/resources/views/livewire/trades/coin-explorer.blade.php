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
            <span class="px-2 py-1 rounded bg-sky-900/60 text-sky-300">{{ $goodToday }} goede fires</span>
            <span class="px-2 py-1 rounded bg-rose-900/60 text-rose-300">{{ $badToday }} slechte fires</span>
        </div>
    </div>

    <div class="bg-slate-900/60 border border-slate-800 rounded-xl p-4 mb-6">
        <div wire:key="chart-{{ $coin }}-{{ $date }}" x-data="coinChart(@js($chart))">
            <div class="relative h-[420px]"><canvas x-ref="cv"></canvas></div>
        </div>
        <p class="text-xs text-slate-500 mt-2">
            Klik op een fire-stip of een groene periode (of een rij hieronder) voor detail + label.
            Groene vlakken = promising. Stippen = fires (groen = in promising, rood = buiten).
        </p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div>
            <h2 class="text-sm font-semibold text-slate-300 mb-2">Fires deze dag ({{ $fires->count() }})</h2>
            <div class="overflow-x-auto rounded-lg border border-slate-800">
                <table class="w-full text-sm">
                    <thead class="bg-slate-800/60 text-slate-400">
                        <tr><th class="text-left px-3 py-2">tijd</th><th class="text-left px-3 py-2">rule</th>
                            <th class="text-left px-3 py-2">resultaat</th><th class="text-left px-3 py-2">promising</th>
                            <th class="text-right px-3 py-2">P&L %</th><th class="text-left px-3 py-2">label</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($fires as $f)
                            <tr wire:click="selectFire({{ $f->id }})"
                                class="border-t border-slate-800 cursor-pointer hover:bg-slate-800/40 {{ $selType==='fire' && $selId===$f->id ? 'bg-slate-800/60' : '' }}">
                                <td class="px-3 py-1.5 font-mono text-xs">{{ $f->datetime->format('H:i:s') }}</td>
                                <td class="px-3 py-1.5">{{ $f->rule }}</td>
                                <td class="px-3 py-1.5">
                                    @php $map = [1 => ['goed','text-emerald-400'], 2 => ['middel','text-amber-400'], 3 => ['slecht','text-rose-400']]; @endphp
                                    <span class="{{ $map[$f->result][1] ?? 'text-slate-500' }}">{{ $map[$f->result][0] ?? '—' }}</span>
                                </td>
                                <td class="px-3 py-1.5">{!! $f->in_good_period ? '<span class="text-emerald-400">✓</span>' : '<span class="text-rose-400">buiten</span>' !!}</td>
                                <td class="px-3 py-1.5 text-right font-mono {{ $f->profit_loss >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">{{ $f->profit_loss !== null ? number_format($f->profit_loss, 2) : '—' }}</td>
                                <td class="px-3 py-1.5 text-xs text-amber-300">{{ optional($annotations->get('fire:'.$f->id))->category }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-4 text-center text-slate-500">Geen fires deze dag.</td></tr>
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
                    <h3 class="font-semibold text-white">{{ $detail['title'] }}</h3>
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

                    @if (!empty($detail['legacy_remark']))
                        <div class="mb-4 text-sm">
                            <span class="text-slate-400">legacy remark:</span>
                            <span class="text-amber-300">“{{ $detail['legacy_remark'] }}”</span>
                        </div>
                    @endif

                    <div class="border-t border-slate-800 pt-4">
                        <h4 class="text-sm font-semibold text-slate-300 mb-2">Label dit {{ $selType === 'fire' ? 'trade' : 'moment' }}</h4>
                        <div class="flex flex-wrap items-start gap-3">
                            <select wire:model="annCategory" class="bg-slate-800 border-slate-700 rounded-lg text-sm py-1.5 min-w-[14rem]">
                                <option value="">— kies —</option>
                                @foreach ($categories as $c)
                                    <option value="{{ $c }}">{{ $c }}</option>
                                @endforeach
                            </select>
                            <textarea wire:model="annComment" rows="2" placeholder="opmerking (optioneel)"
                                      class="flex-1 min-w-[12rem] bg-slate-800 border-slate-700 rounded-lg text-sm py-1.5"></textarea>
                            <button wire:click="saveAnnotation"
                                    class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg text-sm font-medium">Opslaan</button>
                        </div>
                        <p class="text-xs text-emerald-400 mt-2" x-data x-show="false"
                           x-on:annotation-saved.window="$el.style.display='block'; setTimeout(()=>$el.style.display='none',1500)">opgeslagen ✓</p>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

@push('scripts')
<script>
function __ann() { if (window.__annReg !== true && window['chartjs-plugin-annotation']) { Chart.register(window['chartjs-plugin-annotation']); window.__annReg = true; } }
function __xaxis() { return { type: 'linear', ticks: { color: '#64748b', maxTicksLimit: 10,
    callback: (v) => new Date(v).toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' }) },
    grid: { color: 'rgba(51,65,85,0.3)' } }; }

function coinChart(data) {
    return {
        chart: null,
        init() {
            __ann();
            const annotations = {};
            (data.periods || []).forEach((p, i) => { annotations['per' + i] = { type: 'box', xMin: p.from, xMax: p.to, backgroundColor: 'rgba(16,185,129,0.12)', borderWidth: 0 }; });
            (data.fires || []).forEach((f, i) => { annotations['fire' + i] = { type: 'line', xMin: f.x, xMax: f.x,
                borderColor: f.good ? 'rgba(16,185,129,0.9)' : 'rgba(244,63,94,0.9)', borderWidth: 1.5, borderDash: [4, 3],
                label: { display: true, content: 'R' + f.rule, position: 'start', backgroundColor: f.good ? 'rgba(16,185,129,0.85)' : 'rgba(244,63,94,0.85)', color: '#fff', font: { size: 9 }, padding: 2 } }; });
            const wire = this.$wire;
            this.chart = new Chart(this.$refs.cv, {
                type: 'line',
                data: { datasets: [{ label: 'prijs', data: data.price, borderColor: 'rgba(148,163,184,0.9)', borderWidth: 1.2, pointRadius: 0, tension: 0.1, parsing: false }] },
                options: {
                    responsive: true, maintainAspectRatio: false, animation: false,
                    onClick: (e) => {
                        const x = this.chart.scales.x.getValueForPixel(e.x);
                        let best = null, bd = Infinity;
                        (data.fires || []).forEach(f => { const d = Math.abs(f.x - x); if (d < bd) { bd = d; best = f; } });
                        if (best && bd < 150000) { wire.selectFire(best.id); return; }
                        const per = (data.periods || []).find(p => x >= p.from && x <= p.to);
                        if (per) wire.selectPeriod(per.id);
                    },
                    scales: { x: __xaxis(), y: { ticks: { color: '#64748b' }, grid: { color: 'rgba(51,65,85,0.3)' } } },
                    plugins: { legend: { display: false }, annotation: { annotations },
                        tooltip: { callbacks: { title: (it) => new Date(it[0].parsed.x).toLocaleTimeString('nl-NL') } } },
                },
            });
        },
    };
}

function zoomChart(d) {
    return {
        chart: null,
        init() {
            __ann();
            const m = d.markers || {}, ann = {};
            if (m.pfrom && m.pto) ann.band = { type: 'box', xMin: m.pfrom, xMax: m.pto,
                backgroundColor: 'rgba(16,185,129,0.10)', borderColor: 'rgba(16,185,129,0.35)', borderWidth: 1 };
            const line = (x, color, txt, pos) => ({ type: 'line', xMin: x, xMax: x, borderColor: color, borderWidth: 1.5,
                label: { display: true, content: txt, position: pos || 'start', backgroundColor: color, color: '#fff', font: { size: 9 }, padding: 2 } });
            // promising markers (shown on both period and fire detail when in a period)
            if (m.pbest) ann.pbest = line(m.pbest, 'rgba(16,185,129,0.95)', 'beste instap', 'end');
            if (m.peak) ann.peak = line(m.peak, 'rgba(251,191,36,0.95)', 'piek / verkoop', 'end');
            // trade markers
            if (m.buy) ann.buy = line(m.buy, 'rgba(56,189,248,0.95)', 'koop');
            if (m.sell) ann.sell = line(m.sell, 'rgba(244,63,94,0.95)', 'verkoop');
            if (m.best) ann.best = line(m.best, 'rgba(132,204,22,0.9)', 'best (legacy)');
            this.chart = new Chart(this.$refs.zv, {
                type: 'line',
                data: { datasets: [{ data: d.price, borderColor: 'rgba(148,163,184,0.95)', borderWidth: 1.3, pointRadius: 0, tension: 0.1, parsing: false }] },
                options: { responsive: true, maintainAspectRatio: false, animation: false,
                    scales: { x: __xaxis(), y: { ticks: { color: '#64748b' }, grid: { color: 'rgba(51,65,85,0.3)' } } },
                    plugins: { legend: { display: false }, annotation: { annotations: ann },
                        tooltip: { callbacks: { title: (it) => new Date(it[0].parsed.x).toLocaleTimeString('nl-NL') } } } },
            });
        },
    };
}
</script>
@endpush
