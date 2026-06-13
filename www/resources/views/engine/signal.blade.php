@extends('layouts.trading')

@section('content')
<div class="py-8">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div>
            <a href="{{ route('engine.index', ['run' => $signal->run_id]) }}"
               class="text-sm text-indigo-600 hover:text-indigo-800">← terug naar trades</a>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-gray-800">
                        {{ $signal->datetime->format('d-m-Y H:i:s') }}
                    </h1>
                    <p class="text-sm text-gray-500">
                        {{ $signal->run?->symbol }} · rule {{ $signal->run?->rule_number }}
                        @if ($signal->price !== null) · prijs {{ rtrim(rtrim(number_format($signal->price, 8, '.', ''), '0'), '.') }} @endif
                    </p>
                </div>
                @if ($signal->passed)
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold bg-green-100 text-green-800">TRADE (alle subrules groen)</span>
                @else
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold bg-red-100 text-red-800">Geen trade — eerste rood bij sort {{ $signal->failed_at_sort }}</span>
                @endif
            </div>
        </div>

        {{-- per-subrule values --}}
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-3 py-3">sort</th>
                        <th class="px-3 py-3">indicator</th>
                        <th class="px-3 py-3">berekening</th>
                        <th class="px-3 py-3">def1</th>
                        <th class="px-3 py-3 text-right">waarde</th>
                        <th class="px-3 py-3 text-right">b_min</th>
                        <th class="px-3 py-3 text-right">b_max</th>
                        <th class="px-3 py-3 text-center">status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($signal->subruleValues as $v)
                        <tr class="@if ($v->passed === false) bg-red-50 @endif">
                            <td class="px-3 py-2 text-gray-400">{{ $v->sort }}</td>
                            <td class="px-3 py-2 font-medium text-gray-800">{{ $v->indicator }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $v->subrulename }}</td>
                            <td class="px-3 py-2 text-gray-500">{{ $v->def1 ? rtrim(rtrim((string) $v->def1, '0'), '.') : '' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $v->computed_value !== null ? rtrim(rtrim(number_format($v->computed_value, 4, '.', ''), '0'), '.') : '—' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-gray-500">{{ $v->b_min !== null ? rtrim(rtrim(number_format($v->b_min, 4, '.', ''), '0'), '.') : '' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-gray-500">{{ $v->b_max !== null ? rtrim(rtrim(number_format($v->b_max, 4, '.', ''), '0'), '.') : '' }}</td>
                            <td class="px-3 py-2 text-center">
                                @if ($v->passed === true)
                                    <span class="inline-block w-3 h-3 rounded-full bg-green-500" title="groen"></span>
                                @elseif ($v->passed === false)
                                    <span class="inline-block w-3 h-3 rounded-full bg-red-500" title="rood"></span>
                                @else
                                    <span class="text-gray-300">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
