<?php

namespace App\Livewire\Trades;

use App\Models\CoinFire;
use App\Models\CoinRegime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Trades — browse OUR fires from the brain DB (coin_fires), filtered by coin, rule and outcome.
 * Two tabs:
 *  - 'summary' (default) — per-maand-per-coin aggregatie (aantal + Σwinst per klasse + totaal).
 *  - 'list' — de bestaande trades-tabel (gepagineerd).
 * Reads only brain: coins/rules are whatever we actually rebuilt (DOGEAI + NOS, rules 20-23).
 */
#[Layout('layouts.trading')]
class Index extends Component
{
    use WithPagination;

    /**
     * Vangnet tegen corrupte sell-rijen: realiseerbare winst zit binnen deze grenzen (>1000% = data-defect).
     * Sinds de outlier-guard bij ingest (engine/src/outlier_guard.py) corrupte prijs-ticks afvangt, hoeft
     * deze filter niets meer weg te filteren — hij blijft staan als defense-in-depth.
     */
    private const SANE_MIN_PL = -100.0;
    private const SANE_MAX_PL = 1000.0;

    #[Url] public string $tab = 'summary';    // 'summary' | 'list'
    #[Url] public string $coin = '';
    #[Url] public string $rule = '';
    #[Url] public string $outcome = '';       // '' | goed | middel | slecht | shadow
    #[Url] public string $from = '';
    #[Url] public string $to = '';
    #[Url] public bool $activeOnly = true;

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['rule', 'outcome', 'from', 'to', 'activeOnly']);
        $this->activeOnly = true;
        $this->resetPage();
    }

    public function toggleActiveOnly(): void
    {
        $this->activeOnly = ! $this->activeOnly;
        $this->resetPage();
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['summary', 'list'], true) ? $tab : 'summary';
        $this->resetPage();
    }

    private function baseQuery(): Builder
    {
        $q = CoinFire::query()
            ->when($this->coin !== '', fn (Builder $q) => $q->where('trading_symbol_id', (int) $this->coin))
            ->when($this->rule !== '', fn (Builder $q) => $q->where('rule', (int) $this->rule))
            ->when($this->outcome === 'goed', fn (Builder $q) => $q->where('is_executed', true)->where('profit_loss', '>=', 3))
            ->when($this->outcome === 'middel', fn (Builder $q) => $q->where('is_executed', true)->where('profit_loss', '>=', 0)->where('profit_loss', '<', 3))
            ->when($this->outcome === 'slecht', fn (Builder $q) => $q->where('is_executed', true)->where('profit_loss', '<', 0))
            ->when($this->outcome === 'shadow', fn (Builder $q) => $q->where('is_executed', false))
            ->when($this->from !== '', fn (Builder $q) => $q->whereDate('datetime', '>=', $this->from))
            ->when($this->to !== '', fn (Builder $q) => $q->whereDate('datetime', '<=', $this->to));

        if ($this->activeOnly) {
            CoinRegime::scopeActiveOnly($q);
        }

        return $q;
    }

    /**
     * Promising momenten → gegroepeerd tot losse KANSEN (één positie tegelijk), per maand/coin.
     *
     * Bron: coin_moment_sells = de promising-universe (max60 >= 3% AND vroege-dip >= -0,5%,
     * plus handmatig ok-gemarkeerde momenten) mét de gerealiseerde sell-engine winst per moment.
     *
     * HOLD-WINDOW-groepering (i.p.v. een vast tijd-gat): een promising moment telt alleen als
     * losse kans als het ná de verkoop (selling_datetime) van de vorige kans ligt. Momenten
     * binnen dat hold-window — bv. rule 20 start een trade en rule 21 vuurt 2 min later —
     * horen bij dezelfde positie en worden NIET dubbel geteld. Zo overdrijft de Σ niet door
     * overlappende trades. Het VROEGSTE moment van een kans is de representant (vroeg instappen
     * = meeste upside = beste sell-winst); zijn profit_loss telt mee.
     *
     * Per (maand, coin): prom_n = aantal losse kansen (potentie), prom_pl = Σ winst van de
     * vroegste momenten, prom_traded = kansen waarin we minstens één echte trade hadden.
     *
     * Promising-TOTAAL is MOMENT-niveau → rule-onafhankelijk (een goed koop-moment hangt niet
     * van een rule af; dat is het punt van "potentie voor nieuwe rules"). De prom_traded-telling
     * respecteert wél de rule-filter: welk deel van de potentie ving de gekozen rule. Corrupte
     * sell-rijen (>1000%, een data-defect) worden uitgesloten. Keyed op "ym|symbol_id".
     */
    private function groupedPromising(): \Illuminate\Support\Collection
    {
        $db = DB::connection(config('database.default'));

        // Echte trade-posities als hold-windows [buy, sell] per coin (respecteert de rule-filter).
        // Een kans telt als "verhandeld" zodra een ECHTE positie er (deels) overheen loopt — óók als
        // die trade in een EERDERE kans startte en met zijn hold-window over deze kans heen loopt
        // (je kon daar geen nieuwe trade openen, dus het is geen gemiste kans).
        $tradeWins = []; // symbol_id => [[startTs, endTs], ...]
        $twQ = $db->table('coin_fires')->where('is_executed', true)->whereNotNull('selling_datetime')
            ->when($this->coin !== '', fn ($q) => $q->where('trading_symbol_id', (int) $this->coin))
            ->when($this->rule !== '', fn ($q) => $q->where('rule', (int) $this->rule))
            ->select('trading_symbol_id', 'datetime', 'selling_datetime')->orderBy('datetime');
        if ($this->activeOnly) {
            $twQ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')->from('coin_regime')
                    ->whereColumn('coin_regime.trading_symbol_id', 'coin_fires.trading_symbol_id')
                    ->where('coin_regime.state', 'inactive')
                    ->whereRaw('coin_fires.datetime >= coin_regime.period_from AND coin_fires.datetime < coin_regime.period_to + INTERVAL 1 DAY');
            });
        }
        $twQ->get()->each(function ($f) use (&$tradeWins) {
                $tradeWins[$f->trading_symbol_id][] = [strtotime((string) $f->datetime),
                                                       strtotime((string) $f->selling_datetime)];
            });
        // merge overlappende windows per coin → niet-overlappende, gesorteerde intervallen (snelle check)
        foreach ($tradeWins as $sid => $wins) {
            usort($wins, fn ($a, $b) => $a[0] <=> $b[0]);
            $merged = [];
            foreach ($wins as $w) {
                if ($merged && $w[0] <= $merged[count($merged) - 1][1]) {
                    $merged[count($merged) - 1][1] = max($merged[count($merged) - 1][1], $w[1]);
                } else {
                    $merged[] = $w;
                }
            }
            $tradeWins[$sid] = $merged;
        }
        // dekkings-check (binary search): valt tijdstip $ts binnen een echte trade-positie van deze coin?
        $covered = function ($sid, $ts) use ($tradeWins) {
            $wins = $tradeWins[$sid] ?? [];
            $lo = 0; $hi = count($wins) - 1;
            while ($lo <= $hi) {
                $mid = intdiv($lo + $hi, 2);
                if ($ts < $wins[$mid][0]) { $hi = $mid - 1; }
                elseif ($ts > $wins[$mid][1]) { $lo = $mid + 1; }
                else { return true; }
            }
            return false;
        };

        $momQ = $db->table('coin_moment_sells')
            ->select('trading_symbol_id', 'symbol', 'datetime', 'selling_datetime', 'profit_loss')
            ->whereNotNull('profit_loss')
            ->whereBetween('profit_loss', [self::SANE_MIN_PL, self::SANE_MAX_PL])
            ->when($this->coin !== '', fn ($q) => $q->where('trading_symbol_id', (int) $this->coin))
            ->when($this->from !== '', fn ($q) => $q->whereDate('datetime', '>=', $this->from))
            ->when($this->to !== '', fn ($q) => $q->whereDate('datetime', '<=', $this->to))
            ->orderBy('trading_symbol_id')->orderBy('datetime');
        if ($this->activeOnly) {
            $momQ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')->from('coin_regime')
                    ->whereColumn('coin_regime.trading_symbol_id', 'coin_moment_sells.trading_symbol_id')
                    ->where('coin_regime.state', 'inactive')
                    ->whereRaw('coin_moment_sells.datetime >= coin_regime.period_from AND coin_moment_sells.datetime < coin_regime.period_to + INTERVAL 1 DAY');
            });
        }
        $moments = $momQ->get();

        $prom = [];      // key "ym|symbol_id" => array
        $curSym = null; $curSellTs = null; $curKey = null; $curTraded = false;
        foreach ($moments as $m) {
            $startTs = strtotime((string) $m->datetime);
            $sellTs  = $m->selling_datetime ? strtotime((string) $m->selling_datetime) : $startTs;
            $isCov   = $covered($m->trading_symbol_id, $startTs); // moment binnen een echte trade-positie?

            // nieuwe losse kans? alleen als deze ná de verkoop van de vorige kans start.
            if ($m->trading_symbol_id !== $curSym || $startTs >= $curSellTs) {
                $key = substr((string) $m->datetime, 0, 7).'|'.$m->trading_symbol_id;
                $prom[$key] ??= ['ym' => substr((string) $m->datetime, 0, 7),
                                 'trading_symbol_id' => $m->trading_symbol_id, 'symbol' => $m->symbol,
                                 'prom_n' => 0, 'prom_pl' => 0.0, 'prom_traded' => 0];
                $prom[$key]['prom_n']++;
                $prom[$key]['prom_pl'] += (float) $m->profit_loss; // vroegste moment = representant
                $curSym = $m->trading_symbol_id; $curSellTs = $sellTs; $curKey = $key; $curTraded = false;
                if ($isCov) { $prom[$curKey]['prom_traded']++; $curTraded = true; }
            } else {
                // binnen het hold-window: zelfde positie. Verleng het venster als deze later sluit.
                if ($sellTs > $curSellTs) $curSellTs = $sellTs;
                // nog niet gedekt en dit moment valt binnen een echte positie (start óf overloop) → verhandeld.
                if (! $curTraded && $isCov) { $prom[$curKey]['prom_traded']++; $curTraded = true; }
            }
        }

        return collect($prom)->map(fn ($a) => (object) $a)->keyBy(fn ($r) => $r->ym.'|'.$r->trading_symbol_id);
    }

    /** Per-maand-per-coin aggregatie voor de Samenvatting-tab; $prom = vooraf berekende rises. */
    private function summaryRows(\Illuminate\Support\Collection $prom): array
    {
        $db = DB::connection(config('database.default'));

        // 1) executed trades per maand/coin
        $tradesQ = $db->table('coin_fires')
            ->selectRaw("DATE_FORMAT(datetime, '%Y-%m') AS ym, trading_symbol_id, symbol,
                COUNT(*) AS n_total,
                SUM(CASE WHEN profit_loss >= 3 THEN 1 ELSE 0 END) AS n_goed,
                SUM(CASE WHEN profit_loss >= 0 AND profit_loss < 3 THEN 1 ELSE 0 END) AS n_middel,
                SUM(CASE WHEN profit_loss < 0 THEN 1 ELSE 0 END) AS n_slecht,
                COALESCE(SUM(CASE WHEN profit_loss >= 3 THEN profit_loss END), 0) AS pl_goed,
                COALESCE(SUM(CASE WHEN profit_loss >= 0 AND profit_loss < 3 THEN profit_loss END), 0) AS pl_middel,
                COALESCE(SUM(CASE WHEN profit_loss < 0 THEN profit_loss END), 0) AS pl_slecht,
                COALESCE(SUM(profit_loss), 0) AS pl_total")
            ->where('is_executed', true)
            ->whereNotNull('profit_loss')
            ->when($this->coin !== '', fn ($q) => $q->where('trading_symbol_id', (int) $this->coin))
            ->when($this->rule !== '', fn ($q) => $q->where('rule', (int) $this->rule))
            ->when($this->from !== '', fn ($q) => $q->whereDate('datetime', '>=', $this->from))
            ->when($this->to !== '', fn ($q) => $q->whereDate('datetime', '<=', $this->to));
        if ($this->activeOnly) {
            $tradesQ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')->from('coin_regime')
                    ->whereColumn('coin_regime.trading_symbol_id', 'coin_fires.trading_symbol_id')
                    ->where('coin_regime.state', 'inactive')
                    ->whereRaw('coin_fires.datetime >= coin_regime.period_from AND coin_fires.datetime < coin_regime.period_to + INTERVAL 1 DAY');
            });
        }
        $trades = $tradesQ->groupBy('ym', 'trading_symbol_id', 'symbol')->get();

        // 2) merge per (maand, coin) — ook maanden mét promising maar ZONDER trade tonen
        $rows = [];
        $blankTrade = ['n_total' => 0, 'n_goed' => 0, 'n_middel' => 0, 'n_slecht' => 0,
                       'pl_goed' => 0.0, 'pl_middel' => 0.0, 'pl_slecht' => 0.0, 'pl_total' => 0.0];
        $blankProm = ['prom_n' => 0, 'prom_pl' => 0.0, 'prom_traded' => 0];

        foreach ($trades as $t) {
            $key = $t->ym.'|'.$t->trading_symbol_id;
            $rows[$key] = array_merge((array) $t, $blankProm);
        }
        foreach ($prom as $key => $p) {
            if (isset($rows[$key])) {
                $rows[$key]['prom_n']      = (int) $p->prom_n;
                $rows[$key]['prom_pl']     = (float) $p->prom_pl;
                $rows[$key]['prom_traded'] = (int) $p->prom_traded;
            } else {
                $rows[$key] = array_merge($blankTrade, [
                    'ym' => $p->ym, 'trading_symbol_id' => $p->trading_symbol_id, 'symbol' => $p->symbol,
                    'prom_n' => (int) $p->prom_n, 'prom_pl' => (float) $p->prom_pl,
                    'prom_traded' => (int) $p->prom_traded,
                ]);
            }
        }

        // sorteer: maand aflopend, dan coin
        usort($rows, fn ($a, $b) => [$b['ym'], $a['symbol']] <=> [$a['ym'], $b['symbol']]);

        return array_values($rows);
    }

    public function render()
    {
        // dropdowns from brain only (what we actually rebuilt: DOGEAI + NOS, rules 20-23)
        $coins = CoinFire::query()->select('trading_symbol_id', 'symbol')->distinct()
            ->orderBy('symbol')->get();
        $rules = CoinFire::query()->select('rule')->distinct()->orderBy('rule')->pluck('rule');

        // class pills (gerealiseerde profit_loss): aantal + gem. + Σprofit per klasse
        $pills = [];
        foreach (['goed' => [3, null], 'middel' => [0, 3], 'slecht' => [null, 0]] as $k => [$lo, $hi]) {
            $q = (clone $this->baseQuery())->where('is_executed', true);
            if ($lo !== null) $q->where('profit_loss', '>=', $lo);
            if ($hi !== null) $q->where('profit_loss', '<', $hi);
            $pills[$k] = ['n' => (clone $q)->count(), 'avg' => (float) (clone $q)->avg('profit_loss'),
                          'pl' => (float) (clone $q)->sum('profit_loss')];
        }
        $execQ = (clone $this->baseQuery())->where('is_executed', true);
        $totals = ['n' => (clone $execQ)->count(),
                   'avg' => (float) (clone $execQ)->avg('profit_loss'),
                   'pl' => (float) (clone $execQ)->sum('profit_loss')];

        // promising-rises (één keer berekend): voedt zowel de badge als de Samenvatting-tabel
        $prom = $this->groupedPromising();
        $promPill = [
            'n'      => (int) $prom->sum('prom_n'),
            'pl'     => (float) $prom->sum('prom_pl'),
            'traded' => (int) $prom->sum('prom_traded'),
        ];
        $promPill['pct'] = $promPill['n'] > 0 ? $promPill['traded'] / $promPill['n'] * 100 : 0.0;

        // Σ-alles: als activeOnly aan staat, toon het verschil (de bespaarde verliezers).
        // Bouw allQ met dezelfde filters als baseQuery() ZONDER regime-filter, zodat het vergelijk
        // appels-met-appels is bij elke outcome-filter.
        $regimeSaving = null;
        if ($this->activeOnly) {
            $allQ = CoinFire::query()
                ->when($this->coin !== '', fn (Builder $q) => $q->where('trading_symbol_id', (int) $this->coin))
                ->when($this->rule !== '', fn (Builder $q) => $q->where('rule', (int) $this->rule))
                ->when($this->outcome === 'goed', fn (Builder $q) => $q->where('is_executed', true)->where('profit_loss', '>=', 3))
                ->when($this->outcome === 'middel', fn (Builder $q) => $q->where('is_executed', true)->where('profit_loss', '>=', 0)->where('profit_loss', '<', 3))
                ->when($this->outcome === 'slecht', fn (Builder $q) => $q->where('is_executed', true)->where('profit_loss', '<', 0))
                ->when($this->outcome === 'shadow', fn (Builder $q) => $q->where('is_executed', false))
                ->when($this->outcome === '', fn (Builder $q) => $q->where('is_executed', true))
                ->when($this->from !== '', fn (Builder $q) => $q->whereDate('datetime', '>=', $this->from))
                ->when($this->to !== '', fn (Builder $q) => $q->whereDate('datetime', '<=', $this->to));
            $regimeSaving = [
                'n_all' => (clone $allQ)->count(),
                'pl_all' => (float) (clone $allQ)->sum('profit_loss'),
                'slecht_all' => (clone $allQ)->where('profit_loss', '<', 0)->count(),
            ];
            $regimeSaving['n_filtered'] = $regimeSaving['n_all'] - $totals['n'];
            $regimeSaving['pl_saved'] = $totals['pl'] - $regimeSaving['pl_all'];
            $regimeSaving['slecht_saved'] = $regimeSaving['slecht_all'] - $pills['slecht']['n'];
        }

        // tab-specifieke data: alleen ophalen wat de tab nodig heeft
        $trades = $this->tab === 'list'
            ? $this->baseQuery()->orderByDesc('datetime')->paginate(50)
            : null;
        $summary = $this->tab === 'summary' ? $this->summaryRows($prom) : [];

        return view('livewire.trades.index', compact('trades', 'summary', 'coins', 'rules', 'pills', 'totals', 'promPill', 'regimeSaving'));
    }
}
