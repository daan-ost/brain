<?php

namespace App\Livewire\Trades;

use App\Models\CoinFire;
use App\Models\CoinPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Coin day-navigator (Epic A): pick a coin, step through its days, and see the price
 * graph with the promising periods, the rule-fires (rule + result), and good/bad
 * membership overlaid. Periods + fires come from the brain DB (persist_to_brain.py);
 * price is read live from the read-only bot_signals connection.
 */
#[Layout('layouts.trading')]
class CoinExplorer extends Component
{
    #[Url] public string $coin = '2525';
    #[Url] public string $date = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
        if ($this->date === '') {
            $this->date = optional($this->dayList()->first())?->format('Y-m-d') ?? now()->toDateString();
        }
    }

    /** Coins that have persisted periods. */
    public function coins(): array
    {
        return CoinPeriod::query()
            ->select('trading_symbol_id', 'symbol')
            ->distinct()
            ->orderBy('symbol')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->trading_symbol_id => $r->symbol ?: $r->trading_symbol_id])
            ->all();
    }

    /** Distinct days (by best_entry) that have activity for this coin, ascending. */
    public function dayList()
    {
        return CoinPeriod::query()
            ->where('trading_symbol_id', $this->coin)
            ->selectRaw('DATE(best_entry) as d')
            ->distinct()
            ->orderBy('d')
            ->pluck('d')
            ->map(fn ($d) => Carbon::parse($d));
    }

    public function step(int $dir): void
    {
        $days = $this->dayList()->map->format('Y-m-d')->values();
        $i = $days->search($this->date);
        if ($i === false) {
            $this->date = $days->first() ?? $this->date;
            return;
        }
        $j = max(0, min($days->count() - 1, $i + $dir));
        $this->date = $days[$j];
    }

    public function updatedCoin(): void
    {
        $this->date = optional($this->dayList()->first())?->format('Y-m-d') ?? $this->date;
    }

    public function render()
    {
        $start = Carbon::parse($this->date)->startOfDay();
        $end = (clone $start)->endOfDay();

        $periods = CoinPeriod::query()
            ->where('trading_symbol_id', $this->coin)
            ->where('period_from', '<=', $end)
            ->where('period_to', '>=', $start)
            ->orderBy('period_from')
            ->get();

        $fires = CoinFire::query()
            ->where('trading_symbol_id', $this->coin)
            ->whereBetween('datetime', [$start, $end])
            ->orderBy('datetime')
            ->get();

        // price (volumeud) for the day from the read-only source, downsampled to ~600 pts
        $rows = DB::connection('bot_signals')
            ->table('wp_trading_indicator')
            ->select('datetime', 'price')
            ->where('trading_symbol_id', $this->coin)
            ->where('indicator', 'volumeud')
            ->whereNotNull('price')
            ->whereBetween('datetime', [$start, $end])
            ->orderBy('datetime')
            ->get();
        $step = max(1, (int) ceil($rows->count() / 600));
        $price = $rows->values()->filter(fn ($r, $i) => $i % $step === 0)
            ->map(fn ($r) => ['x' => Carbon::parse($r->datetime)->getTimestampMs(), 'y' => (float) $r->price])
            ->values();

        $chart = [
            'price' => $price,
            'periods' => $periods->map(fn ($p) => [
                'from' => $p->period_from->getTimestampMs(),
                'to' => $p->period_to->getTimestampMs(),
                'upside' => $p->best_upside,
            ])->values(),
            'fires' => $fires->map(fn ($f) => [
                'x' => $f->datetime->getTimestampMs(),
                'rule' => $f->rule,
                'result' => $f->result,
                'good' => $f->in_good_period,
                'pl' => $f->profit_loss,
            ])->values(),
        ];

        return view('livewire.trades.coin-explorer', [
            'periods' => $periods,
            'fires' => $fires,
            'chart' => $chart,
            'dayCount' => $this->dayList()->count(),
            'goodToday' => $fires->where('in_good_period', true)->count(),
            'badToday' => $fires->where('in_good_period', false)->count(),
        ]);
    }
}
