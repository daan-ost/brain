<?php

namespace App\Livewire\Trades\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Shared day-navigator chart helpers for the trading screens (CoinExplorer + PromisingLabeler):
 * Amsterdam time formatting, the zoom-window computation, and the downsampled volumeud price series
 * (read from brain.indicators). Keeps the two screens from drifting apart. Expects the using
 * component to expose `$coin` and `$date`.
 */
trait InteractsWithCoinChart
{
    /**
     * Format a UTC Carbon datetime in Amsterdam local time. MUST copy first: Carbon is mutable and
     * setTimezone() shifts in place — without the copy it would corrupt shared series datetimes
     * (e.g. a moment's peak_at points into the price series; formatting it would move that tick +1h).
     */
    protected function localFmt(?Carbon $dt, string $fmt = 'H:i:s'): ?string
    {
        return $dt?->copy()->setTimezone('Europe/Amsterdam')->format($fmt);
    }

    /** A zoom window [from,to] that contains every (non-null) marker, with 5 min margin each side. */
    protected function windowAround(array $markers): array
    {
        $ms = array_values(array_filter($markers, fn ($v) => $v !== null));
        if (empty($ms)) {
            $d = Carbon::parse($this->date);
            return [$d->copy()->startOfDay(), $d->copy()->endOfDay()];
        }
        return [
            Carbon::createFromTimestampMs(min($ms))->subMinutes(5),
            Carbon::createFromTimestampMs(max($ms))->addMinutes(5),
        ];
    }

    /** Downsampled volumeud price between two datetimes (read from brain.indicators only). */
    protected function priceBetween($from, $to, int $cap = 400): array
    {
        $rows = DB::table('indicators')
            ->select('datetime', 'price')->where('trading_symbol_id', $this->coin)
            ->where('indicator', 'volumeud')->whereNotNull('price')
            ->whereBetween('datetime', [$from, $to])->orderBy('datetime')->get();
        $step = max(1, (int) ceil($rows->count() / $cap));
        return $rows->values()->filter(fn ($r, $i) => $i % $step === 0)
            ->map(fn ($r) => ['x' => Carbon::parse($r->datetime)->getTimestampMs(), 'y' => (float) $r->price])
            ->values()->all();
    }
}
