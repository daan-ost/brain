<div class="max-w-7xl mx-auto space-y-6">

    <div>
        <h1 class="text-2xl font-semibold text-gray-900">Munten</h1>
        <p class="text-sm text-gray-500">Kansrijkheid + beweeglijkheid per week, met een kandidaat aan/uit-streep (gate) — per munt over de volledige periode.</p>
    </div>

    {{-- Tab-balk --}}
    <div class="flex gap-1 border-b border-gray-200">
        <a href="{{ route('coins.ranking') }}"
           class="px-4 py-2 text-sm font-medium rounded-t-lg transition
                  {{ request()->routeIs('coins.ranking') ? 'bg-white border border-b-white -mb-px text-gray-900' : 'text-gray-500 hover:text-gray-700' }}">
            Kansrijk (engine)
        </a>
        <a href="{{ route('coins.weekly') }}"
           class="px-4 py-2 text-sm font-medium rounded-t-lg transition
                  {{ request()->routeIs('coins.weekly') ? 'bg-white border border-b-white -mb-px text-gray-900' : 'text-gray-500 hover:text-gray-700' }}">
            Per week
        </a>
        <a href="{{ route('coins.mexc') }}"
           class="px-4 py-2 text-sm font-medium rounded-t-lg transition
                  {{ request()->routeIs('coins.mexc') ? 'bg-white border border-b-white -mb-px text-gray-900' : 'text-gray-500 hover:text-gray-700' }}">
            MEXC-markt
        </a>
    </div>

    @if (count($coins))
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 flex items-baseline justify-between">
                <h3 class="font-semibold text-gray-800">Kansrijkheid per week</h3>
                <span class="text-xs text-gray-500">kans = % momenten met ≥3% stijging binnen 1 uur · gemiddeld per ISO-week</span>
            </div>

            <div class="divide-y divide-gray-100">
                @foreach ($coins as $coin)
                    <div class="px-4 py-4">
                        <div class="flex items-baseline justify-between mb-2">
                            <div class="font-medium text-gray-800">{{ $coin['symbol'] }}</div>
                            <div class="text-xs text-gray-500 tabular-nums">
                                {{ count($coin['weeks']) }} weken ·
                                gem. {{ number_format($coin['avg'], 1, ',', '.') }}% ·
                                piek {{ number_format($coin['max'], 1, ',', '.') }}%
                                @if ($coin['off_weeks'] > 0)
                                    @php $missed = $coin['off_pl'] > 0; @endphp
                                    · <span class="font-medium {{ $missed ? 'text-amber-600' : 'text-emerald-700' }}">gate: {{ $coin['off_weeks'] }} wk uit ·
                                      {{ $coin['off_bad'] }} verliezers eruit ·
                                      Σ {{ number_format($coin['off_pl'], 0, ',', '.') }}% {{ $missed ? 'gemiste winst' : 'vermeden verlies' }}</span>
                                @endif
                            </div>
                        </div>

                        {{-- Status-streep (gate) + 2 heatmap-rijen (kansrijk + beweeglijk) + maand/jaar-as --}}
                        <div class="flex gap-3">
                            {{-- Vaste labels links (scrollen niet mee) --}}
                            <div class="shrink-0 select-none text-xs font-medium text-gray-500">
                                <div class="h-9 flex items-center">Status</div>
                                <div class="h-9 flex items-center mt-1">Kansrijk</div>
                                <div class="h-9 flex items-center mt-1">Beweeglijk</div>
                                <div class="h-9 mt-1"></div>
                            </div>

                            {{-- Scrollbaar tijdspoor: ÉÉN grid met vaste kolommen per week → rijen + maand-as
                                 staan gegarandeerd uitgelijnd. grid-auto-flow vult per rij van links naar rechts. --}}
                            <div class="overflow-x-auto pb-1">
                                @php $n = count($coin['weeks']); @endphp
                                <div class="grid gap-1"
                                     style="grid-template-columns: repeat({{ $n }}, 2.25rem); grid-auto-rows: 2.25rem;">

                                    {{-- Rij 0: STATUS-streep — groen = aan, rood = uit (resultaat-gate). Flip-datum eronder. --}}
                                    @php $prevRegime = null; @endphp
                                    @foreach ($coin['weeks'] as $w)
                                        @php
                                            $pre  = $w['regime'] === 'pre';
                                            $off  = $w['regime'] === 'off';
                                            $flip = ! $pre && $prevRegime !== null && $prevRegime !== 'pre' && $w['regime'] !== $prevRegime;
                                            $prevRegime = $w['regime'];
                                        @endphp
                                        <div class="group relative flex flex-col items-center justify-center gap-0.5">
                                            <div class="w-full h-2.5 rounded-full {{ $pre ? 'bg-gray-200' : ($off ? 'bg-rose-500' : 'bg-emerald-500') }}
                                                        {{ $flip ? 'ring-2 ring-offset-1 ring-gray-500' : '' }}"></div>
                                            @if ($flip)
                                                <div class="text-[8px] leading-none font-medium {{ $off ? 'text-rose-600' : 'text-emerald-700' }} whitespace-nowrap">{{ $w['startShort'] }}</div>
                                            @endif
                                            <div class="pointer-events-none absolute z-10 bottom-full left-1/2 -translate-x-1/2 mb-1 hidden group-hover:block
                                                        whitespace-nowrap rounded bg-gray-900 text-white text-xs px-2 py-1 shadow-lg">
                                                <div class="font-semibold">{{ $pre ? 'nog geen trades' : ($off ? 'UIT' : 'AAN') }} · week van {{ $w['start'] }}</div>
                                                @if ($flip)<div class="text-amber-300">↔ schakelt naar {{ $off ? 'UIT' : 'AAN' }} op {{ $w['start'] }}</div>@endif
                                                <div>{{ $w['reason'] }}</div>
                                                <div class="text-gray-300">week: {{ $w['week_n'] }} trades, Σ {{ number_format($w['week_pl'], 1, ',', '.') }}%
                                                     · rollend {{ number_format($w['result_roll'], 1, ',', '.') }}%</div>
                                            </div>
                                        </div>
                                    @endforeach

                                    {{-- Rij 1: kansrijkheid (groen) --}}
                                    @foreach ($coin['weeks'] as $w)
                                        @php
                                            $intensity = max(0.06, min(1, $w['up'] / $scaleMax));
                                            $textDark  = $intensity < 0.55;
                                        @endphp
                                        <div class="group relative">
                                            <div class="w-full h-full rounded flex items-center justify-center text-[10px] font-medium tabular-nums cursor-default
                                                        {{ $textDark ? 'text-gray-600' : 'text-white' }}"
                                                 style="background-color: rgba(16,185,129,{{ $intensity }});">
                                                {{ number_format($w['up'], 0) }}
                                            </div>
                                            <div class="pointer-events-none absolute z-10 bottom-full left-1/2 -translate-x-1/2 mb-1 hidden group-hover:block
                                                        whitespace-nowrap rounded bg-gray-900 text-white text-xs px-2 py-1 shadow-lg">
                                                <div class="font-semibold">{{ $w['label'] }} · week van {{ $w['start'] }}</div>
                                                <div>kansrijk: {{ number_format($w['up'], 1, ',', '.') }}%</div>
                                                <div class="text-gray-300">{{ $w['days'] }} dagen · {{ number_format($w['ticks'], 0, ',', '.') }} ticks</div>
                                            </div>
                                        </div>
                                    @endforeach

                                    {{-- Rij 2: beweeglijkheid (indigo) --}}
                                    @foreach ($coin['weeks'] as $w)
                                        @php
                                            $hasVol  = $w['vol'] !== null;
                                            $vint    = $hasVol ? max(0.06, min(1, $w['vol'] / $scaleMaxVol)) : 0;
                                            $vDark   = $vint < 0.55;
                                        @endphp
                                        <div class="group relative">
                                            <div class="w-full h-full rounded flex items-center justify-center text-[10px] font-medium tabular-nums cursor-default
                                                        {{ $hasVol ? ($vDark ? 'text-gray-600' : 'text-white') : 'text-gray-300' }}"
                                                 style="background-color: {{ $hasVol ? "rgba(99,102,241,$vint)" : '#f3f4f6' }};">
                                                {{ $hasVol ? number_format($w['vol'], 1, ',', '.') : '—' }}
                                            </div>
                                            <div class="pointer-events-none absolute z-10 bottom-full left-1/2 -translate-x-1/2 mb-1 hidden group-hover:block
                                                        whitespace-nowrap rounded bg-gray-900 text-white text-xs px-2 py-1 shadow-lg">
                                                <div class="font-semibold">{{ $w['label'] }} · week van {{ $w['start'] }}</div>
                                                <div>beweeglijkheid: {{ $hasVol ? number_format($w['vol'], 2, ',', '.') : 'geen meting' }}</div>
                                                <div class="text-gray-300">{{ $w['days'] }} dagen · {{ number_format($w['ticks'], 0, ',', '.') }} ticks</div>
                                            </div>
                                        </div>
                                    @endforeach

                                    {{-- Rij 3: maand/jaar-as — streepje + label waar een nieuwe maand begint --}}
                                    @php $prevMon = null; $prevYear = null; @endphp
                                    @foreach ($coin['weeks'] as $w)
                                        @php
                                            $monStart  = $w['mon'] !== $prevMon || $w['year'] !== $prevYear;
                                            $yearStart = $w['year'] !== $prevYear;
                                            $prevMon = $w['mon']; $prevYear = $w['year'];
                                        @endphp
                                        @if ($monStart)
                                            <div class="group relative border-l border-gray-300 pl-1">
                                                <div class="text-[10px] leading-tight text-gray-500 cursor-default">{{ $w['mshort'] }}</div>
                                                @if ($yearStart)
                                                    <div class="text-[10px] leading-tight font-semibold text-gray-700">{{ $w['year'] }}</div>
                                                @endif
                                                <div class="pointer-events-none absolute z-10 bottom-full left-0 mb-1 hidden group-hover:block
                                                            whitespace-nowrap rounded bg-gray-900 text-white text-xs px-2 py-1 shadow-lg capitalize">
                                                    {{ $w['mfull'] }} {{ $w['year'] }}
                                                </div>
                                            </div>
                                        @else
                                            <div></div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Legenda --}}
            <div class="px-4 py-3 border-t border-gray-100 flex flex-wrap items-center gap-x-6 gap-y-2 text-xs text-gray-500">
                <div class="flex items-center gap-2">
                    <span>Status</span>
                    <span class="inline-block w-6 h-2.5 rounded-full bg-emerald-500"></span><span>aan</span>
                    <span class="inline-block w-6 h-2.5 rounded-full bg-rose-500 ml-1"></span><span>uit</span>
                </div>
                <div class="flex items-center gap-2">
                    <span>Kansrijk</span>
                    <div class="flex gap-0.5">
                        @foreach ([0.1, 0.3, 0.5, 0.7, 1.0] as $step)
                            <div class="w-6 h-4 rounded-sm" style="background-color: rgba(16,185,129,{{ $step }});"></div>
                        @endforeach
                    </div>
                    <span>t/m {{ number_format($scaleMax, 0) }}%</span>
                </div>
                <div class="flex items-center gap-2">
                    <span>Beweeglijk</span>
                    <div class="flex gap-0.5">
                        @foreach ([0.1, 0.3, 0.5, 0.7, 1.0] as $step)
                            <div class="w-6 h-4 rounded-sm" style="background-color: rgba(99,102,241,{{ $step }});"></div>
                        @endforeach
                    </div>
                    <span>t/m {{ number_format($scaleMaxVol, 2, ',', '.') }}</span>
                </div>
                <span class="ml-auto">getal = waarde per week · streepje = nieuwe maand</span>
            </div>
        </div>

        {{-- Uitleg kandidaat-gate v2 --}}
        <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-xs text-amber-900 space-y-1">
            <p class="font-semibold">Status-streep = kandidaat-gate v2 (backtest ter kalibratie — blokkeert niets)</p>
            <p>
                Signaal = het <span class="font-medium">rollende trade-resultaat</span> over de laatste {{ $gate['roll'] }} weken (≈1 maand).
                <span class="font-medium text-rose-700">UIT</span> na {{ $gate['stopConfirm'] }} weken aaneen
                <code>&lt; {{ (int) $gate['stopFloor'] }}%</code> (snel eruit, maar niet op één dip);
                weer <span class="font-medium text-emerald-700">AAN</span> pas na {{ $gate['restartConfirm'] }} weken aaneen
                <code>≥ {{ (int) $gate['restartFloor'] }}%</code> (hogere lat = demping → niet op een losse goede maand).
                De stop-lat van {{ (int) $gate['stopFloor'] }}% is Daans "onder ~20%/maand is na slippage geen echte winst".
                Grijze streep = nog geen trades. Ring + datum = omslagweek. Kansrijk/beweeglijkheid zijn alleen context.
            </p>
        </div>

        <p class="text-xs text-gray-400">
            Bron: <code>coin_daily_metrics</code> (kansrijk/beweeglijk per ISO-week) + <code>coin_fires</code> (trade-resultaat per week).
            Niet-overlappende periodes: elke munt toont zijn eigen meetbereik. Kleuren zijn cross-munt vergelijkbaar (gedeelde schaal per maat).
        </p>
    @else
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8 text-center text-gray-500">
            Nog geen metrics. Draai de routine <code>coin-metrics</code> om de tabel te vullen.
        </div>
    @endif
</div>
