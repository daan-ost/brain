<?php

namespace App\Livewire\Trades;

use App\Models\CoinFire;
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

    #[Url] public string $tab = 'summary';    // 'summary' | 'list'
    #[Url] public string $coin = '';
    #[Url] public string $rule = '';
    #[Url] public string $outcome = '';       // '' | goed | middel | slecht | shadow
    #[Url] public string $from = '';
    #[Url] public string $to = '';

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
        $this->reset(['rule', 'outcome', 'from', 'to']);
        $this->resetPage();
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['summary', 'list'], true) ? $tab : 'summary';
        $this->resetPage();
    }

    private function baseQuery(): Builder
    {
        // Klasse-thresholds gerealiseerd resultaat: goed >=3%, middel 0..3%, slecht <0% (verlies).
        // Komt overeen met CoinFire::autoKlasseKey() voor executed trades.
        return CoinFire::query()
            ->when($this->coin !== '', fn (Builder $q) => $q->where('trading_symbol_id', (int) $this->coin))
            ->when($this->rule !== '', fn (Builder $q) => $q->where('rule', (int) $this->rule))
            ->when($this->outcome === 'goed', fn (Builder $q) => $q->where('is_executed', true)->where('profit_loss', '>=', 3))
            ->when($this->outcome === 'middel', fn (Builder $q) => $q->where('is_executed', true)->where('profit_loss', '>=', 0)->where('profit_loss', '<', 3))
            ->when($this->outcome === 'slecht', fn (Builder $q) => $q->where('is_executed', true)->where('profit_loss', '<', 0))
            ->when($this->outcome === 'shadow', fn (Builder $q) => $q->where('is_executed', false))
            ->when($this->from !== '', fn (Builder $q) => $q->whereDate('datetime', '>=', $this->from))
            ->when($this->to !== '', fn (Builder $q) => $q->whereDate('datetime', '<=', $this->to));
    }

    /** Per-maand-per-coin aggregatie voor de Samenvatting-tab. Telt alleen EXECUTED trades. */
    private function summaryRows(): array
    {
        $q = DB::connection(config('database.default'))
            ->table('coin_fires')
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
            ->when($this->to !== '', fn ($q) => $q->whereDate('datetime', '<=', $this->to))
            ->groupBy('ym', 'trading_symbol_id', 'symbol')
            ->orderByDesc('ym')
            ->orderBy('symbol');

        return $q->get()->map(fn ($r) => (array) $r)->all();
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

        // tab-specifieke data: alleen ophalen wat de tab nodig heeft
        $trades = $this->tab === 'list'
            ? $this->baseQuery()->orderByDesc('datetime')->paginate(50)
            : null;
        $summary = $this->tab === 'summary' ? $this->summaryRows() : [];

        return view('livewire.trades.index', compact('trades', 'summary', 'coins', 'rules', 'pills', 'totals'));
    }
}
