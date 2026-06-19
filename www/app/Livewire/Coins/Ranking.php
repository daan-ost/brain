<?php

namespace App\Livewire\Coins;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Munten — gesorteerd op KANSRIJKHEID. Toont per coin de meest recente metrics uit coin_daily_metrics:
 * up_7d (de kansrijk-score = 7-daags gem. van het % momenten met >=3% stijging binnen 60 min, cross-coin
 * het sterkst gecorreleerd met winst/trade), vol_7d (beweeglijkheid) en n_ticks (liquiditeit).
 * Read-only. Schaalt naar N coins; de routine `coin-metrics` vult de tabel dagelijks.
 */
#[Layout('layouts.trading')]
class Ranking extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
    }

    /** Meest recente metrics-rij per coin, gesorteerd op kansrijkheid (up_7d desc; NULL onderaan). */
    private function ranking(): array
    {
        $latestDate = DB::connection(config('database.default'))
            ->table('coin_daily_metrics')
            ->select('trading_symbol_id', DB::raw('MAX(date) AS md'))
            ->groupBy('trading_symbol_id');

        $rows = DB::connection(config('database.default'))
            ->table('coin_daily_metrics as m')
            ->joinSub($latestDate, 'x', fn ($j) => $j
                ->on('m.trading_symbol_id', '=', 'x.trading_symbol_id')
                ->on('m.date', '=', 'x.md'))
            ->leftJoin('coins as c', 'c.id', '=', 'm.trading_symbol_id')
            ->selectRaw('m.trading_symbol_id, c.symbol, m.date, m.up_7d, m.vol_7d, m.n_ticks')
            ->orderByRaw('m.up_7d IS NULL, m.up_7d DESC')
            ->get();

        return $rows->map(fn ($r) => (array) $r)->all();
    }

    public function render()
    {
        return view('livewire.coins.ranking', ['ranking' => $this->ranking()]);
    }
}
