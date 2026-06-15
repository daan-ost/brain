<div class="max-w-5xl mx-auto space-y-6">

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Routines</h1>
            <p class="text-sm text-gray-500">
                Logboek per routine-set. De set <span class="font-medium text-gray-700">Rule-precisie</span>
                scherpt bestaande rules aan om slechte trades te elimineren (0 goede verloren).
            </p>
        </div>
        <button wire:click="runNow" wire:loading.attr="disabled" wire:target="runNow"
                class="shrink-0 inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm
                       font-medium text-white hover:bg-emerald-500 disabled:opacity-50">
            <svg wire:loading wire:target="runNow" class="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"></path>
            </svg>
            <span wire:loading.remove wire:target="runNow">Nu draaien (analyse)</span>
            <span wire:loading wire:target="runNow">Bezig…</span>
        </button>
    </div>

    @if ($error)
        <div class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{{ $error }}</div>
    @endif

    @forelse ($runs as $run)
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            {{-- run header --}}
            <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-gray-100 bg-gray-50/60">
                <div class="flex items-center gap-3">
                    @php
                        $dot = ['success' => 'bg-emerald-500', 'running' => 'bg-amber-400', 'failed' => 'bg-rose-500'][$run->status] ?? 'bg-gray-400';
                    @endphp
                    <span class="w-2.5 h-2.5 rounded-full {{ $dot }}"></span>
                    <span class="text-sm font-medium text-gray-900">Run #{{ $run->id }}</span>
                    @if ($run->set_name)
                        <span class="text-xs rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700 font-medium">{{ $run->set_name }}</span>
                    @endif
                    <span class="text-xs text-gray-500">{{ $run->started_at?->format('d-m-Y H:i') }}</span>
                    <span class="text-xs rounded-full bg-gray-100 px-2 py-0.5 text-gray-600">{{ $run->trigger }}</span>
                    @if ($run->durationSeconds() !== null)
                        <span class="text-xs text-gray-400">{{ $run->durationSeconds() }}s</span>
                    @endif
                </div>
                <span class="text-xs font-medium
                    {{ $run->status === 'success' ? 'text-emerald-600' : ($run->status === 'failed' ? 'text-rose-600' : 'text-amber-600') }}">
                    {{ ucfirst($run->status) }}
                </span>
            </div>

            {{-- log lines --}}
            <ul class="divide-y divide-gray-50">
                @foreach ($run->logs as $line)
                    @php
                        $badge = [
                            'result'  => ['Ratio', 'bg-sky-50 text-sky-700'],
                            'finding' => ['Voorstel', 'bg-amber-50 text-amber-700'],
                            'change'  => ['Toegepast', 'bg-emerald-50 text-emerald-700'],
                            'error'   => ['Fout', 'bg-rose-50 text-rose-700'],
                            'info'    => ['Info', 'bg-gray-50 text-gray-600'],
                        ][$line->level] ?? ['Info', 'bg-gray-50 text-gray-600'];
                    @endphp
                    <li class="flex items-start gap-3 px-4 py-2.5 text-sm">
                        <span class="shrink-0 mt-0.5 text-[11px] font-medium rounded px-1.5 py-0.5 {{ $badge[1] }}">
                            {{ $badge[0] }}@if($line->rule_number) · r{{ $line->rule_number }}@endif
                        </span>
                        <span class="text-gray-700">{{ $line->message }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @empty
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8 text-center text-sm text-gray-500">
            Nog geen routine-runs. Klik op <span class="font-medium text-gray-700">Nu draaien</span> of wacht op de geplande run.
        </div>
    @endforelse
</div>
