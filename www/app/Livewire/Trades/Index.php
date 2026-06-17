<?php

namespace App\Livewire\Trades;

use App\Models\CoinFire;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Trades — browse OUR fires from the brain DB (coin_fires), filtered by coin, rule and outcome.
 * Reads only brain: coins/rules are whatever we actually rebuilt (DOGEAI + NOS, rules 20-23).
 */
#[Layout('layouts.trading')]
class Index extends Component
{
    use WithPagination;

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

    public function render()
    {
        $trades = $this->baseQuery()->orderByDesc('datetime')->paginate(50);

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

        return view('livewire.trades.index', compact('trades', 'coins', 'rules', 'pills', 'totals'));
    }
}
