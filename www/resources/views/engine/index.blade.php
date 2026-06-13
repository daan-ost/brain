@extends('layouts.app')

@section('content')
<div class="py-8">
    <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold text-gray-800">Trading engine — gevonden trades</h1>
            @if ($runs->isNotEmpty())
                <form method="GET" class="text-sm">
                    <select name="run" onchange="this.form.submit()"
                            class="rounded-md border-gray-300 text-sm">
                        @foreach ($runs as $r)
                            <option value="{{ $r->id }}" @selected($run && $r->id === $run->id)>
                                Run #{{ $r->id }} — {{ $r->symbol }} rule {{ $r->rule_number }}
                                ({{ optional($r->period_from)->format('d-m-Y') }} … {{ optional($r->period_to)->format('d-m-Y') }})
                            </option>
                        @endforeach
                    </select>
                </form>
            @endif
        </div>

        @if (! $run)
            <div class="bg-white rounded-lg shadow p-6 text-gray-500">
                Nog geen engine-run. Draai <code>brain/engine/src/run_engine.py</code> om data te vullen.
            </div>
        @else
            {{-- run summary --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                @foreach ([
                    'Symbol / rule' => $run->symbol.' · rule '.$run->rule_number,
                    'Periode' => optional($run->period_from)->format('d-m-Y').' … '.optional($run->period_to)->format('d-m-Y'),
                    'Kandidaten (volume-gated)' => number_format($run->candidates, 0, ',', '.'),
                    'Trades gevonden (fires)' => number_format($run->fires, 0, ',', '.'),
                ] as $label => $value)
                    <div class="bg-white rounded-lg shadow p-4">
                        <div class="text-xs uppercase tracking-wide text-gray-400">{{ $label }}</div>
                        <div class="mt-1 text-lg font-semibold text-gray-800">{{ $value }}</div>
                    </div>
                @endforeach
            </div>

            {{-- fires table --}}
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3">Datumtijd</th>
                            <th class="px-4 py-3">Prijs</th>
                            <th class="px-4 py-3 text-right">Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($fires as $fire)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 font-medium text-gray-800">{{ $fire->datetime->format('d-m-Y H:i:s') }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $fire->price !== null ? rtrim(rtrim(number_format($fire->price, 8, '.', ''), '0'), '.') : '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('engine.signal', $fire) }}"
                                       class="text-indigo-600 hover:text-indigo-800 font-medium">27 subrules →</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-6 text-gray-500">Geen trades in deze run.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div>{{ $fires->links() }}</div>
        @endif
    </div>
</div>
@endsection
