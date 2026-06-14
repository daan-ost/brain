<div class="p-6 text-slate-200">
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
        <div wire:key="chart-{{ $coin }}-{{ $date }}" x-data="coinChart(@js($chart))" wire:ignore>
            <div class="relative h-[420px]">
                <canvas x-ref="cv"></canvas>
            </div>
        </div>
        <p class="text-xs text-slate-500 mt-2">
            Groene vlakken = promising perioden. Stippen = rule-fires (groen = in promising, rood = buiten). Hover voor rule + resultaat.
        </p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div>
            <h2 class="text-sm font-semibold text-slate-300 mb-2">Fires deze dag ({{ $fires->count() }})</h2>
            <div class="overflow-x-auto rounded-lg border border-slate-800">
                <table class="w-full text-sm">
                    <thead class="bg-slate-800/60 text-slate-400">
                        <tr>
                            <th class="text-left px-3 py-2">tijd</th>
                            <th class="text-left px-3 py-2">rule</th>
                            <th class="text-left px-3 py-2">resultaat</th>
                            <th class="text-left px-3 py-2">promising</th>
                            <th class="text-right px-3 py-2">P&L %</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($fires as $f)
                            <tr class="border-t border-slate-800">
                                <td class="px-3 py-1.5 font-mono text-xs">{{ $f->datetime->format('H:i:s') }}</td>
                                <td class="px-3 py-1.5">{{ $f->rule }}</td>
                                <td class="px-3 py-1.5">
                                    @php $map = [1 => ['goed','text-emerald-400'], 2 => ['middel','text-amber-400'], 3 => ['slecht','text-rose-400']]; @endphp
                                    <span class="{{ $map[$f->result][1] ?? 'text-slate-500' }}">{{ $map[$f->result][0] ?? '—' }}</span>
                                </td>
                                <td class="px-3 py-1.5">
                                    @if ($f->in_good_period)
                                        <span class="text-emerald-400">✓ in periode</span>
                                    @else
                                        <span class="text-rose-400">buiten</span>
                                    @endif
                                </td>
                                <td class="px-3 py-1.5 text-right font-mono {{ $f->profit_loss >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                                    {{ $f->profit_loss !== null ? number_format($f->profit_loss, 2) : '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-4 text-center text-slate-500">Geen fires deze dag.</td></tr>
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
                        <tr>
                            <th class="text-left px-3 py-2">van</th>
                            <th class="text-left px-3 py-2">tot</th>
                            <th class="text-left px-3 py-2">beste instap</th>
                            <th class="text-right px-3 py-2">upside %</th>
                            <th class="text-right px-3 py-2">#mom</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($periods as $p)
                            <tr class="border-t border-slate-800">
                                <td class="px-3 py-1.5 font-mono text-xs">{{ $p->period_from->format('H:i:s') }}</td>
                                <td class="px-3 py-1.5 font-mono text-xs">{{ $p->period_to->format('H:i:s') }}</td>
                                <td class="px-3 py-1.5 font-mono text-xs text-emerald-300">{{ $p->best_entry->format('H:i:s') }}</td>
                                <td class="px-3 py-1.5 text-right font-mono text-emerald-400">{{ number_format($p->best_upside, 2) }}</td>
                                <td class="px-3 py-1.5 text-right">{{ $p->n_moments }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-4 text-center text-slate-500">Geen perioden deze dag.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function coinChart(data) {
    return {
        chart: null,
        init() {
            if (window.__annReg !== true && window['chartjs-plugin-annotation']) {
                Chart.register(window['chartjs-plugin-annotation']); window.__annReg = true;
            }
            const annotations = {};
            (data.periods || []).forEach((p, i) => {
                annotations['per' + i] = {
                    type: 'box', xMin: p.from, xMax: p.to,
                    backgroundColor: 'rgba(16,185,129,0.12)', borderWidth: 0,
                };
            });
            (data.fires || []).forEach((f, i) => {
                annotations['fire' + i] = {
                    type: 'line', xMin: f.x, xMax: f.x,
                    borderColor: f.good ? 'rgba(16,185,129,0.9)' : 'rgba(244,63,94,0.9)',
                    borderWidth: 1.5, borderDash: [4, 3],
                    label: { display: true, content: 'R' + f.rule, position: 'start',
                        backgroundColor: f.good ? 'rgba(16,185,129,0.85)' : 'rgba(244,63,94,0.85)',
                        color: '#fff', font: { size: 9 }, padding: 2 },
                };
            });

            this.chart = new Chart(this.$refs.cv, {
                type: 'line',
                data: { datasets: [{
                    label: 'prijs', data: data.price,
                    borderColor: 'rgba(148,163,184,0.9)', borderWidth: 1.2,
                    pointRadius: 0, tension: 0.1, parsing: false,
                }] },
                options: {
                    responsive: true, maintainAspectRatio: false, animation: false,
                    scales: {
                        x: { type: 'linear', ticks: { color: '#64748b', maxTicksLimit: 12,
                            callback: (v) => new Date(v).toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' }) },
                            grid: { color: 'rgba(51,65,85,0.3)' } },
                        y: { ticks: { color: '#64748b' }, grid: { color: 'rgba(51,65,85,0.3)' } },
                    },
                    plugins: {
                        legend: { display: false },
                        annotation: { annotations },
                        tooltip: { callbacks: {
                            title: (items) => new Date(items[0].parsed.x).toLocaleTimeString('nl-NL'),
                        } },
                    },
                },
            });
        },
        destroy() { this.chart?.destroy(); },
    };
}
</script>
@endpush
