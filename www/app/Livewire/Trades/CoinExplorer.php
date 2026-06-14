<?php

namespace App\Livewire\Trades;

use App\Models\CoinAnnotation;
use App\Models\CoinFire;
use App\Models\CoinPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Coin day-navigator (Epic A): pick a coin, step through its days, see the price graph
 * with promising periods + rule-fires overlaid. Click a fire or a promising period to
 * open a zoomed detail (5 min before buy → 5 min after sell), with stats and a manual
 * annotation (pulldown + comment) that feeds rule discovery — flag the promising trades
 * that won't work in practice (too-fast rise, too volatile, exchange-not-executable, ...).
 * Periods + fires come from brain; price + legacy remark are read from read-only bot_signals.
 */
#[Layout('layouts.trading')]
class CoinExplorer extends Component
{
    /** Max trade hold in minutes — must match engine config.py FORWARD_MINUTES. */
    public const HOLD_MINUTES = 60;

    #[Url] public string $coin = '2525';
    #[Url] public string $date = '';

    // detail/annotation state
    public ?string $selType = null;     // 'fire' | 'period'
    public ?int $selId = null;
    public string $annCategory = '';
    public string $annComment = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
        if ($this->date === '') {
            $this->date = optional($this->dayList()->first())?->format('Y-m-d') ?? now()->toDateString();
        }
    }

    public function coins(): array
    {
        return CoinPeriod::query()->select('trading_symbol_id', 'symbol')->distinct()
            ->orderBy('symbol')->get()
            ->mapWithKeys(fn ($r) => [$r->trading_symbol_id => $r->symbol ?: $r->trading_symbol_id])->all();
    }

    public function dayList()
    {
        return CoinPeriod::query()->where('trading_symbol_id', $this->coin)
            ->selectRaw('DATE(best_entry) as d')->distinct()->orderBy('d')
            ->pluck('d')->map(fn ($d) => Carbon::parse($d));
    }

    public function step(int $dir): void
    {
        $days = $this->dayList()->map->format('Y-m-d')->values();
        $i = $days->search($this->date);
        if ($i === false) {
            $this->date = $days->first() ?? $this->date;
            return;
        }
        $this->date = $days[max(0, min($days->count() - 1, $i + $dir))];
    }

    public function updatedCoin(): void
    {
        $this->date = optional($this->dayList()->first())?->format('Y-m-d') ?? $this->date;
        $this->closeDetail();
    }

    // ---- selection / detail ----
    public function selectFire(int $id): void { $this->open('fire', $id); }
    public function selectPeriod(int $id): void { $this->open('period', $id); }

    private function open(string $type, int $id): void
    {
        $this->selType = $type;
        $this->selId = $id;
        $ann = CoinAnnotation::where('target_type', $type)->where('target_id', $id)->first();
        $this->annCategory = $ann?->category ?? '';
        $this->annComment = $ann?->comment ?? '';
    }

    public function closeDetail(): void
    {
        $this->reset(['selType', 'selId', 'annCategory', 'annComment']);
    }

    /** Step to the next/previous fire (or period) of the same day, inside the modal. */
    public function navDetail(int $dir): void
    {
        if (! $this->selType) {
            return;
        }
        $ids = $this->dayTargetIds($this->selType);
        $i = array_search($this->selId, $ids, true);
        if ($i === false) {
            return;
        }
        $this->open($this->selType, $ids[max(0, min(count($ids) - 1, $i + $dir))]);
    }

    private function dayTargetIds(string $type): array
    {
        $start = Carbon::parse($this->date)->startOfDay();
        $end = (clone $start)->endOfDay();
        if ($type === 'fire') {
            return CoinFire::where('trading_symbol_id', $this->coin)
                ->whereBetween('datetime', [$start, $end])->orderBy('datetime')->pluck('id')->all();
        }
        return CoinPeriod::where('trading_symbol_id', $this->coin)
            ->where('period_from', '<=', $end)->where('period_to', '>=', $start)
            ->orderBy('period_from')->pluck('id')->all();
    }

    public function saveAnnotation(): void
    {
        if (! $this->selType || ! $this->selId) {
            return;
        }
        $target = $this->selType === 'fire' ? CoinFire::find($this->selId) : CoinPeriod::find($this->selId);
        if (! $target) {
            return;
        }
        $dt = $this->selType === 'fire' ? $target->datetime : $target->best_entry;
        CoinAnnotation::updateOrCreate(
            ['target_type' => $this->selType, 'target_id' => $this->selId],
            ['trading_symbol_id' => $this->coin, 'symbol' => $target->symbol,
             'target_datetime' => $dt, 'category' => $this->annCategory ?: null, 'comment' => $this->annComment ?: null],
        );
        $this->dispatch('annotation-saved');
    }

    /**
     * Shadow fires: once a trade is running it holds until it sells (capped at HOLD_MINUTES),
     * so any fire during that window can't execute — it's a "shadow" of the running trade,
     * not an independent good/bad entry. Returns [fireId => Carbon parent-trade-datetime|null].
     * $fires must be ordered by datetime ascending.
     */
    private function shadowMap($fires): array
    {
        $map = [];
        $openUntil = null;
        $openAt = null;
        foreach ($fires as $f) {
            if ($openUntil !== null && $f->datetime <= $openUntil) {
                $map[$f->id] = $openAt;                       // shadow of the trade at $openAt
                continue;
            }
            $map[$f->id] = null;                              // a real, executed trade
            $cap = (clone $f->datetime)->addMinutes(self::HOLD_MINUTES);
            $openUntil = $f->selling_datetime && $f->selling_datetime->lt($cap) ? $f->selling_datetime : $cap;
            $openAt = $f->datetime;
        }
        return $map;
    }

    private function dayFires(): \Illuminate\Support\Collection
    {
        $start = Carbon::parse($this->date)->startOfDay();
        $end = (clone $start)->endOfDay();
        return CoinFire::where('trading_symbol_id', $this->coin)
            ->whereBetween('datetime', [$start, $end])->orderBy('datetime')->get();
    }

    /** A zoom window [from,to] that contains every marker, with 5 min margin on each side. */
    private function windowAround(array $markers): array
    {
        $ms = array_values($markers);
        return [
            Carbon::createFromTimestampMs(min($ms))->subMinutes(5),
            Carbon::createFromTimestampMs(max($ms))->addMinutes(5),
        ];
    }

    /** Downsampled volumeud price between two datetimes, from the read-only source. */
    private function priceBetween($from, $to, int $cap = 400): array
    {
        // price comes from brain.indicators (the imported series) — screens read only brain
        $rows = DB::table('indicators')
            ->select('datetime', 'price')->where('trading_symbol_id', $this->coin)
            ->where('indicator', 'volumeud')->whereNotNull('price')
            ->whereBetween('datetime', [$from, $to])->orderBy('datetime')->get();
        $step = max(1, (int) ceil($rows->count() / $cap));
        return $rows->values()->filter(fn ($r, $i) => $i % $step === 0)
            ->map(fn ($r) => ['x' => Carbon::parse($r->datetime)->getTimestampMs(), 'y' => (float) $r->price])
            ->values()->all();
    }

    private function detail(): ?array
    {
        if (! $this->selType || ! $this->selId) {
            return null;
        }
        if ($this->selType === 'fire') {
            $f = CoinFire::find($this->selId);
            if (! $f) {
                return null;
            }
            // legacy trade detail (read-only) for best price / excursions / remark
            $leg = DB::connection('bot_signals')->table('wp_trading_simulation')
                ->where('trading_symbol_id', $this->coin)->where('rule', $f->rule)
                ->where('datetime', $f->datetime->format('Y-m-d H:i:s'))->first();
            $markers = ['buy' => $f->datetime->getTimestampMs()];
            if ($leg && $leg->selling_date) $markers['sell'] = Carbon::parse($leg->selling_date)->getTimestampMs();
            if ($leg && $leg->datetime_best) $markers['best'] = Carbon::parse($leg->datetime_best)->getTimestampMs();
            // overlay the promising period this fire belongs to (if any): band + best entry + peak
            if ($f->period_id && ($per = CoinPeriod::find($f->period_id))) {
                $markers['pfrom'] = $per->period_from->getTimestampMs();
                $markers['pto'] = $per->period_to->getTimestampMs();
                $markers['pbest'] = $per->best_entry->getTimestampMs();
                if ($per->peak_datetime) $markers['peak'] = $per->peak_datetime->getTimestampMs();
            }
            [$from, $to] = $this->windowAround($markers);
            return [
                'type' => 'fire', 'id' => $f->id, 'title' => "Trade · rule {$f->rule} · {$f->datetime->format('d M H:i:s')}",
                'price' => $this->priceBetween($from, $to),
                'markers' => $markers,
                'shadow_of' => ($sp = $this->shadowMap($this->dayFires())[$f->id] ?? null) ? $sp->format('H:i:s') : null,
                'stats' => array_filter([
                    'rule' => $f->rule,
                    'resultaat' => [1 => 'goed', 2 => 'middel', 3 => 'slecht'][$f->result] ?? '—',
                    'in promising' => $sp ? '↳ schaduw van ' . $sp->format('H:i:s') : ($f->in_good_period ? 'ja' : 'nee'),
                    'aankoopprijs' => $leg->price ?? $f->buy_price,
                    'verkoopprijs' => $leg->selling_price ?? null,
                    'beste prijs' => $leg->best_price ?? null,
                    'winst %' => $leg->profit_loss ?? $f->profit_loss,
                    'hoogste %' => $leg->highest_profit_loss ?? null,
                    'laagste %' => $leg->lowest_profit_loss ?? null,
                ], fn ($v) => $v !== null && $v !== ''),
                'legacy_remark' => $leg->remark ?? null,
            ];
        }
        $p = CoinPeriod::find($this->selId);
        if (! $p) {
            return null;
        }
        $markers = ['pbest' => $p->best_entry->getTimestampMs(),
                    'pfrom' => $p->period_from->getTimestampMs(), 'pto' => $p->period_to->getTimestampMs()];
        if ($p->peak_datetime) {
            $markers['peak'] = $p->peak_datetime->getTimestampMs();
        }
        [$from, $to] = $this->windowAround($markers);
        return [
            'type' => 'period', 'id' => $p->id, 'title' => "Promising · {$p->best_entry->format('d M H:i:s')}",
            'price' => $this->priceBetween($from, $to),
            'markers' => $markers,
            'stats' => array_filter([
                'beste instap' => $p->best_entry->format('H:i:s'),
                'piek / verkoop' => $p->peak_datetime?->format('H:i:s'),
                'upside %' => $p->best_upside,
                'vroege dip %' => $p->best_lowest10,
                'momenten' => $p->n_moments,
            ], fn ($v) => $v !== null && $v !== ''),
            'legacy_remark' => null,
        ];
    }

    public function render()
    {
        $start = Carbon::parse($this->date)->startOfDay();
        $end = (clone $start)->endOfDay();

        $periods = CoinPeriod::query()->where('trading_symbol_id', $this->coin)
            ->where('period_from', '<=', $end)->where('period_to', '>=', $start)
            ->orderBy('period_from')->get();
        $fires = CoinFire::query()->where('trading_symbol_id', $this->coin)
            ->whereBetween('datetime', [$start, $end])->orderBy('datetime')->get();

        // annotations for this day's targets
        $ann = CoinAnnotation::query()->where('trading_symbol_id', $this->coin)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('target_type', 'fire')->whereIn('target_id', $fires->pluck('id')))
                ->orWhere(fn ($w) => $w->where('target_type', 'period')->whereIn('target_id', $periods->pluck('id'))))
            ->get()->keyBy(fn ($a) => $a->target_type . ':' . $a->target_id);

        $price = $this->priceBetween($start, $end, 600);

        $chart = [
            'price' => $price,
            'periods' => $periods->map(fn ($p) => ['id' => $p->id, 'from' => $p->period_from->getTimestampMs(),
                'to' => $p->period_to->getTimestampMs(), 'best' => $p->best_entry->getTimestampMs(), 'upside' => $p->best_upside])->values(),
            'fires' => $fires->map(fn ($f) => ['id' => $f->id, 'x' => $f->datetime->getTimestampMs(),
                'rule' => $f->rule, 'result' => $f->result, 'good' => $f->in_good_period, 'pl' => $f->profit_loss])->values(),
        ];

        $shadows = $this->shadowMap($fires);
        // good/bad counts ignore shadow fires (they wouldn't execute — a trade is already open)
        $real = $fires->filter(fn ($f) => $shadows[$f->id] === null);

        return view('livewire.trades.coin-explorer', [
            'periods' => $periods,
            'fires' => $fires,
            'shadows' => $shadows,
            'annotations' => $ann,
            'chart' => $chart,
            'detail' => $this->detail(),
            'categories' => CoinAnnotation::CATEGORIES,
            'dayCount' => $this->dayList()->count(),
            'goodToday' => $real->where('in_good_period', true)->count(),
            'badToday' => $real->where('in_good_period', false)->count(),
        ]);
    }
}
