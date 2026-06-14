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
    #[Url] public string $outcome = '';       // '' | good | bad | shadow
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
        return CoinFire::query()
            ->when($this->coin !== '', fn (Builder $q) => $q->where('trading_symbol_id', (int) $this->coin))
            ->when($this->rule !== '', fn (Builder $q) => $q->where('rule', (int) $this->rule))
            ->when($this->outcome === 'good', fn (Builder $q) => $q->where('is_executed', true)->where('in_good_period', true))
            ->when($this->outcome === 'bad', fn (Builder $q) => $q->where('is_executed', true)->where('in_good_period', false))
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

        // outcome pills: count + summed OUR P&L (executed only)
        $pills = [];
        foreach (['good', 'bad', 'shadow'] as $k) {
            $q = (clone $this->baseQuery());
            if ($k === 'good') $q->where('is_executed', true)->where('in_good_period', true);
            if ($k === 'bad') $q->where('is_executed', true)->where('in_good_period', false);
            if ($k === 'shadow') $q->where('is_executed', false);
            $pills[$k] = ['n' => (clone $q)->count(), 'pl' => (float) (clone $q)->sum('profit_loss')];
        }
        $execQ = (clone $this->baseQuery())->where('is_executed', true);
        $totals = ['n' => (clone $execQ)->count(), 'pl' => (float) (clone $execQ)->sum('profit_loss')];

        return view('livewire.trades.index', compact('trades', 'coins', 'rules', 'pills', 'totals'));
    }
}
