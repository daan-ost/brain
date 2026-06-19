<?php

namespace App\Livewire\Coins;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * MEXC-marktscan — volatiele, handelbare USDT-paren als rotatie-kandidaten. Snapshot uit
 * mexc_market_scan (dagelijks gevuld door routine mexc-scan). Sorteersleutel = volat_pct
 * (24u prijs-range); volume + mcap = liquiditeit-filters. Gescheiden van de engine-coins
 * (coin_daily_metrics); een kandidaat kan handmatig aan de engine worden toegevoegd.
 */
#[Layout('layouts.trading')]
class MexcScan extends Component
{
    public int $mcapMin = 10_000_000;
    public int $minVol24h = 100_000;
    public bool $hideUnder7d = true;

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
    }

    private function coins(): array
    {
        $query = DB::table('mexc_market_scan')
            ->orderByDesc('volat_pct');

        $query->where(function ($q) {
            $q->where('mcap_usd', '>', $this->mcapMin)
              ->orWhereNull('mcap_usd');
        });

        $query->where('vol24h_usd', '>', $this->minVol24h);

        if ($this->hideUnder7d) {
            $query->where(function ($q) {
                $q->where('age_days', '>=', 7)
                  ->orWhereNull('age_days');
            });
        }

        return $query->get()->map(fn ($r) => (array) $r)->all();
    }

    private function fetchedAt(): ?string
    {
        return DB::table('mexc_market_scan')->value('fetched_at');
    }

    public function render()
    {
        return view('livewire.coins.mexc-scan', [
            'coins' => $this->coins(),
            'fetchedAt' => $this->fetchedAt(),
        ]);
    }
}
