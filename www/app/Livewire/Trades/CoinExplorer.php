<?php

namespace App\Livewire\Trades;

use App\Livewire\Trades\Concerns\InteractsWithCoinChart;
use App\Models\CoinAnnotation;
use App\Models\CoinFire;
use App\Models\CoinMomentLabel;
use App\Models\CoinPeriod;
use App\Models\CoinRegime;
use Illuminate\Support\Carbon;
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
    use InteractsWithCoinChart;

    /** Max trade hold in minutes — must match engine config.py FORWARD_MINUTES. */
    public const HOLD_MINUTES = 60;

    #[Url] public string $coin = '2525';
    #[Url] public string $date = '';

    // detail/annotation state
    public ?string $selType = null;     // 'fire' | 'period'
    public ?int $selId = null;
    public string $annCategory = '';
    public string $annComment = '';
    public string $manualKlasse = '';   // '' = berekend, 'goed'|'middel'|'slecht' = handmatig override
    public string $bestSell = '';       // override beste sell — alleen TIJD (H:i:s); datum = koopdatum
    public string $hardSell = '';       // harde verkoopdatum — alleen TIJD (H:i:s); datum = koopdatum
    public bool $skipShadows = true;    // nav-pijltjes slaan schaduw-trades over (default aan)
    #[Url] public bool $activeOnly = true;

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
        $this->manualKlasse = '';
        $this->bestSell = '';
        $this->hardSell = '';
        if ($type === 'fire' && ($f = CoinFire::find($id))) {
            // moment-level label (rule-independent): klasse + beste/harde sell overrides
            $l = CoinMomentLabel::where('trading_symbol_id', $f->trading_symbol_id)
                ->where('datetime', $f->datetime)->where('source', 'manual')->first();
            $this->manualKlasse = $l?->manual_klasse ?? '';
            // Alleen het tijdgedeelte tonen — datum is altijd de koop-datum (hold max 60 min).
            $this->bestSell = $l?->best_sell_datetime ? Carbon::parse($l->best_sell_datetime)->format('H:i:s') : '';
            $this->hardSell = $l?->hard_sell_datetime ? Carbon::parse($l->hard_sell_datetime)->format('H:i:s') : '';
        }
    }

    public function closeDetail(): void
    {
        $this->reset(['selType', 'selId', 'annCategory', 'annComment', 'manualKlasse', 'bestSell', 'hardSell']);
    }

    public function saveManualKlasse(): void
    {
        if ($this->selType !== 'fire' || ! $this->selId) return;
        $f = CoinFire::find($this->selId);
        if (! $f) return;
        // Combineer tijd-input (H:i:s) met de KOOP-datum tot een volledige datetime — sneller
        // invoeren omdat de datum altijd dezelfde dag is (hold max 60 min). manual_set_at=now()
        // markeert het als handmatig, zodat heranalyse het niet overschrijft.
        $buyDate = $f->datetime->copy()->startOfDay();
        $combine = fn (string $t) => $t ? $buyDate->copy()->setTimeFromTimeString($t) : null;
        CoinMomentLabel::setManual($f->trading_symbol_id, $f->symbol, $f->datetime, [
            'manual_klasse' => $this->manualKlasse ?: null,
            'best_sell_datetime' => $combine($this->bestSell),
            'hard_sell_datetime' => $combine($this->hardSell),
            'manual_set_at' => now(),
        ], auth()->user()?->email);
        $this->dispatch('annotation-saved');
    }

    /** Step to the next/previous fire (across day-boundaries) or period (within day). */
    public function navDetail(int $dir): void
    {
        if (! $this->selType) return;

        // Period-navigatie blijft binnen de huidige dag — periodes zijn per definitie dag-gebonden.
        if ($this->selType === 'period') {
            $ids = $this->dayTargetIds($this->selType);
            $i = array_search($this->selId, $ids, true);
            if ($i === false) return;
            $this->open($this->selType, $ids[max(0, min(count($ids) - 1, $i + $dir))]);
            return;
        }

        // Fire-navigatie: zoek de eerstvolgende fire over dag-grenzen heen. Als die op een andere
        // dag valt, update $this->date zodat het scherm onder de modal mee verschuift.
        $cur = CoinFire::find($this->selId);
        if (! $cur) return;
        $q = CoinFire::where('trading_symbol_id', $this->coin)
            ->where('datetime', $dir > 0 ? '>' : '<', $cur->datetime);
        if ($this->skipShadows) $q->where('is_executed', true);
        if ($this->activeOnly) CoinRegime::scopeActiveOnly($q);
        $next = $q->orderBy('datetime', $dir > 0 ? 'asc' : 'desc')->first();
        if (! $next) return;

        $newDate = $next->datetime->format('Y-m-d');
        if ($newDate !== $this->date) {
            $this->date = $newDate;
        }
        $this->open('fire', $next->id);
    }

    private function dayTargetIds(string $type): array
    {
        $start = Carbon::parse($this->date)->startOfDay();
        $end = (clone $start)->endOfDay();
        if ($type === 'fire') {
            $q = CoinFire::where('trading_symbol_id', $this->coin)
                ->whereBetween('datetime', [$start, $end]);
            if ($this->skipShadows) $q->where('is_executed', true);
            if ($this->activeOnly) CoinRegime::scopeActiveOnly($q);
            return $q->orderBy('datetime')->pluck('id')->all();
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
            CoinMomentLabel::attachOne($f);   // so klasse()/uitkomst reflects the manual override
            // OUR fire detail (brain only): buy/sell from our sell-engine; legacy result = reference.
            $markers = ['buy' => $f->datetime->getTimestampMs()];
            if ($f->selling_datetime) $markers['sell'] = $f->selling_datetime->getTimestampMs();
            // beste-sell voorrang: handmatig > berekend (binnen [koop, onze verkoop]).
            // Legacy ligt vaak na onze verkoop (de oude bot sloot anders) en is NIET door jou
            // gekozen — tonen we als losse info-regel, niet als de getoonde beste-sell-waarde.
            $manualLabel = CoinMomentLabel::where('trading_symbol_id', $f->trading_symbol_id)
                ->where('datetime', $f->datetime)->where('source', 'manual')->first();
            $legacyRow = CoinMomentLabel::where('trading_symbol_id', $f->trading_symbol_id)
                ->where('datetime', $f->datetime)->where('source', 'legacy')->first();
            $bestSellDt = $manualLabel?->best_sell_datetime ?? $f->best_sell_datetime;
            $bestSellSrc = $manualLabel?->best_sell_datetime ? 'handmatig'
                : ($f->best_sell_datetime ? 'berekend' : null);
            // % obv prijs op die exacte tick (anders fallback op coin_fires.best_sell_price voor de berekende)
            $bestSellPct = null;
            if ($bestSellDt && $f->buy_price) {
                $px = \DB::table('indicators')->where('trading_symbol_id', $f->trading_symbol_id)
                    ->where('indicator', 'volumeud')->where('datetime', $bestSellDt)->value('price');
                if ($px === null && $bestSellSrc === 'berekend' && $f->best_sell_price) $px = $f->best_sell_price;
                if ($px !== null) $bestSellPct = round(($px - $f->buy_price) / $f->buy_price * 100, 2);
            }
            if ($bestSellDt) $markers['bestsell'] = Carbon::parse($bestSellDt)->getTimestampMs();
            if ($manualLabel?->hard_sell_datetime) $markers['hardsell'] = $manualLabel->hard_sell_datetime->getTimestampMs();
            // changelog (klasse-veranderingen door heranalyse — auto schrijft hier, handmatig niet)
            $changes = \DB::table('coin_fires_changelog')->where('trading_symbol_id', $f->trading_symbol_id)
                ->where('datetime', $f->datetime)->orderByDesc('id')->limit(5)->get()->all();
            // overlay the promising period this fire belongs to (if any): band + best entry.
            // De peak van de PERIODE laten we hier weg — voor deze trade is "beste sell" (binnen
            // [koop, onze verkoop]) de relevante exit, niet de piek van de hele promising-periode
            // (die ligt vaak vóór de koop en is misleidend). Periode-detail toont peak nog wel.
            $promising = null;
            if ($f->period_id && ($per = CoinPeriod::find($f->period_id))) {
                $markers['pfrom'] = $per->period_from->getTimestampMs();
                $markers['pto'] = $per->period_to->getTimestampMs();
                $markers['pbest'] = $per->best_entry->getTimestampMs();
                $deltaMin = $per->best_entry->diffInRealSeconds($f->datetime, false) / 60.0;
                $inPeriod = $f->datetime->between($per->period_from, $per->period_to);
                $promising = ['in_period' => $inPeriod, 'delta_min' => round($deltaMin, 1),
                              'best_entry' => $this->localFmt($per->best_entry)];
            }
            [$from, $to] = $this->windowAround($markers);
            return [
                'type' => 'fire', 'id' => $f->id,
                'title' => "Trade · rule {$f->rule} · " . $this->localFmt($f->datetime, 'd M H:i:s'),
                'manual_klasse' => $this->manualKlasse,
                'manual_klasse_set' => (bool) $manualLabel?->manual_klasse,
                'is_executed' => $f->is_executed,
                'auto_klasse' => $f->autoKlasseKey(),
                'bestsell_pct' => $bestSellPct,
                'price' => $this->priceBetween($from, $to),
                'markers' => $markers,
                'best_sell' => $bestSellDt ? [
                    'datetime' => $this->localFmt($bestSellDt),
                    'pct' => $bestSellPct,
                    'source' => $bestSellSrc,
                ] : null,
                'legacy_best_sell' => $legacyRow?->best_sell_datetime
                    ? $this->localFmt($legacyRow->best_sell_datetime) : null,
                'promising' => $promising,
                'hard_sell' => $manualLabel?->hard_sell_datetime ? $this->localFmt($manualLabel->hard_sell_datetime) : null,
                'changes' => array_map(fn ($r) => [
                    'when' => Carbon::parse($r->created_at)->format('Y-m-d H:i'),
                    'field' => $r->field, 'from' => $r->old_value, 'to' => $r->new_value, 'reason' => $r->reason,
                ], $changes),
                'stats' => array_filter([
                    'rule' => $f->rule,
                    'uitkomst' => ! $f->is_executed ? '↳ schaduw van ' . $this->localFmt($f->shadow_parent) : $f->klasse()[0],
                    'aankoopprijs' => $f->buy_price,
                    'verkoopprijs (onze sell)' => $f->selling_price,
                    'winst % (onze sell)' => $f->is_executed ? $f->profit_loss : null,
                    'legacy result' => [1 => 'goed', 2 => 'middel', 3 => 'slecht'][$f->legacy_result] ?? null,
                    'legacy P&L' => $f->legacy_profit_loss,
                ], fn ($v) => $v !== null && $v !== ''),
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
            'type' => 'period', 'id' => $p->id,
            'title' => "Promising · " . $this->localFmt($p->best_entry, 'd M H:i:s'),
            'manual_klasse' => '',
            'price' => $this->priceBetween($from, $to),
            'markers' => $markers,
            'stats' => array_filter([
                'beste instap' => $this->localFmt($p->best_entry),
                'piek / verkoop' => $this->localFmt($p->peak_datetime),
                'upside %' => $p->best_upside,
                'vroege dip %' => $p->best_lowest10,
                'momenten' => $p->n_moments,
            ], fn ($v) => $v !== null && $v !== ''),
        ];
    }

    public function render()
    {
        $start = Carbon::parse($this->date)->startOfDay();
        $end = (clone $start)->endOfDay();

        $periods = CoinPeriod::query()->where('trading_symbol_id', $this->coin)
            ->where('period_from', '<=', $end)->where('period_to', '>=', $start)
            ->orderBy('period_from')->get();
        $firesQ = CoinFire::query()->where('trading_symbol_id', $this->coin)
            ->whereBetween('datetime', [$start, $end])->orderBy('datetime');
        if ($this->activeOnly) {
            CoinRegime::scopeActiveOnly($firesQ);
        }
        $fires = $firesQ->get();

        // attach manual moment-labels so klasseKey() applies the override on the chart + table
        // (single source of truth = coin_moment_labels, survives the persist re-fire).
        CoinMomentLabel::attachManual($fires, $this->coin, $start, $end);

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
            // chart markers: only EXECUTED trades (shadows wouldn't trade), coloured by class
            'fires' => $fires->where('is_executed', true)->map(fn ($f) => ['id' => $f->id, 'x' => $f->datetime->getTimestampMs(),
                'rule' => $f->rule, 'klasse' => $f->klasseKey(), 'best' => $f->best_upside])->values(),
        ];

        // goed/middel/slecht counts over EXECUTED trades (best_upside class)
        $exec = $fires->where('is_executed', true)->groupBy(fn ($f) => $f->klasseKey());

        $dayActive = CoinRegime::isActive((int) $this->coin, $start);

        return view('livewire.trades.coin-explorer', [
            'periods' => $periods,
            'fires' => $fires,
            'annotations' => $ann,
            'chart' => $chart,
            'detail' => $this->detail(),
            'categories' => CoinAnnotation::CATEGORIES,
            'dayCount' => $this->dayList()->count(),
            'goedToday' => $exec->get('goed', collect())->count(),
            'middelToday' => $exec->get('middel', collect())->count(),
            'slechtToday' => $exec->get('slecht', collect())->count(),
            'dayActive' => $dayActive,
        ]);
    }
}
