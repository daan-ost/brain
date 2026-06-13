<?php

namespace App\Livewire\Trades;

use App\Models\Trading\TradingSimulation;
use App\Models\Trading\TradingSymbol;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Trades — browse the legacy found/labeled trades (wp_trading_simulation, READ-ONLY)
 * with filters on coin, period, rule and result. WorkMyAgent-style sidebar + table.
 */
#[Layout('layouts.trading')]
class Index extends Component
{
    use WithPagination;

    #[Url] public string $coin = '2525';     // default DOGEAI
    #[Url] public string $rule = '';
    #[Url] public string $result = '';        // '1' | '2' | '3' | 'unlabeled' | ''
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
        $this->reset(['rule', 'result', 'from', 'to']);
        $this->resetPage();
    }

    private function baseQuery(): Builder
    {
        return TradingSimulation::query()
            ->when($this->coin !== '', fn (Builder $q) => $q->where('trading_symbol_id', (int) $this->coin))
            ->when($this->rule !== '', fn (Builder $q) => $q->where('rule', (int) $this->rule))
            ->when($this->result === 'unlabeled', fn (Builder $q) => $q->whereNull('result'))
            ->when(in_array($this->result, ['1', '2', '3'], true), fn (Builder $q) => $q->where('result', (int) $this->result))
            ->when($this->from !== '', fn (Builder $q) => $q->whereDate('datetime', '>=', $this->from))
            ->when($this->to !== '', fn (Builder $q) => $q->whereDate('datetime', '<=', $this->to));
    }

    public function render()
    {
        $trades = $this->baseQuery()
            ->with('symbol')
            ->orderByDesc('datetime')
            ->paginate(50);

        // filter option lists
        $coins = TradingSymbol::query()
            ->whereIn('ID', TradingSimulation::query()->select('trading_symbol_id')->distinct())
            ->orderBy('symbol')
            ->get(['ID', 'symbol', 'timeframe']);

        $rules = TradingSimulation::query()
            ->when($this->coin !== '', fn (Builder $q) => $q->where('trading_symbol_id', (int) $this->coin))
            ->select('rule')->distinct()->orderBy('rule')->pluck('rule');

        // per-result aggregates for the pills: count + summed profit_loss %
        $agg = (clone $this->baseQuery())
            ->selectRaw('result, COUNT(*) as n, SUM(profit_loss) as pl')
            ->groupBy('result')->get()->keyBy('result');

        $totals = [
            'n' => (int) $agg->sum('n'),
            'pl' => (float) $agg->sum('pl'),
        ];

        return view('livewire.trades.index', compact('trades', 'coins', 'rules', 'agg', 'totals'));
    }
}
