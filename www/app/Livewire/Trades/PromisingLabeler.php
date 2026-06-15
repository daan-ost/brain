<?php

namespace App\Livewire\Trades;

use App\Livewire\Trades\Concerns\InteractsWithCoinChart;
use App\Models\CoinAnnotation;
use App\Models\CoinFire;
use App\Models\CoinMomentLabel;
use App\Models\CoinPeriod;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Promising labeler (Epic L) — the rebuilt legacy simulate_buy review table. Per buy-moment
 * (coin_fire) it shows the multi-horizon upside (+5/+10/+15/+30/+45/+60 min, sell-INdependent
 * buy-moment quality) next to OUR sell-engine result (profit_loss), the auto-verdict, the
 * imported legacy label, and Daan's own label. A row with positive upside but negative
 * profit_loss is a sell-engine defect, not a bad buy-moment.
 *
 * Labels live in coin_moment_labels (natural key coin+datetime+rule) so they survive the
 * persist_to_brain re-fire that wipes coin_fires.
 */
#[Layout('layouts.trading')]
class PromisingLabeler extends Component
{
    use InteractsWithCoinChart;

    public const HORIZONS = [5, 10, 15, 30, 45, 60];

    /** Request-scoped memo of the day's fires (render + a same-request action share one query set). */
    private ?\Illuminate\Support\Collection $fireMemo = null;
    private ?string $fireMemoKey = null;

    #[Url] public string $coin = '2525';
    #[Url] public string $date = '';
    #[Url] public bool $onlyExecuted = true;     // labeler focuses on real trades (shadows didn't trade)

    // modal / label state
    public ?int $selId = null;
    public string $decision = '';
    public string $klasse = '';
    public string $category = '';
    public string $comment = '';

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
        return CoinFire::query()->where('trading_symbol_id', $this->coin)
            ->selectRaw('DATE(datetime) as d')->distinct()->orderBy('d')
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
        $this->closeDetail();
    }

    public function updatedCoin(): void
    {
        $this->date = optional($this->dayList()->first())?->format('Y-m-d') ?? $this->date;
        $this->closeDetail();
    }

    /** Filter wijzigt de zichtbare set → sluit de modal zodat selId niet buiten dayFireIds() valt. */
    public function updatedOnlyExecuted(): void
    {
        $this->closeDetail();
    }

    public function selectFire(int $id): void { $this->open($id); }

    private function open(int $id): void
    {
        $f = CoinFire::find($id);
        if (! $f) return;
        $this->selId = $id;
        $l = $this->label($f, 'manual');
        $this->decision = $l?->decision ?? '';
        $this->klasse = $l?->manual_klasse ?? '';
        $this->category = $l?->category ?? '';
        $this->comment = $l?->comment ?? '';
    }

    public function closeDetail(): void
    {
        $this->reset(['selId', 'decision', 'klasse', 'category', 'comment']);
    }

    /** Step to the next/previous fire of the same day inside the modal. */
    public function navDetail(int $dir): void
    {
        $ids = $this->dayFireIds();
        $i = array_search($this->selId, $ids, true);
        if ($i === false) return;
        $this->open($ids[max(0, min(count($ids) - 1, $i + $dir))]);
    }

    public function saveLabel(): void
    {
        if (! $this->selId) return;
        $f = CoinFire::find($this->selId);
        if (! $f) return;

        $key = ['trading_symbol_id' => $f->trading_symbol_id, 'datetime' => $f->datetime, 'rule' => $f->rule, 'source' => 'manual'];

        // niets ingevuld? trek een eventueel bestaand label in i.p.v. een lege rij te schrijven
        if (! ($this->decision || $this->klasse || $this->category || trim($this->comment))) {
            CoinMomentLabel::where($key)->delete();
            $this->dispatch('label-saved');
            return;
        }

        // coin_moment_labels is de enige bron — geen mirror naar de dode coin_fires.manual_klasse
        CoinMomentLabel::updateOrCreate($key, [
            'symbol' => $f->symbol,
            'decision' => $this->decision ?: null,
            'manual_klasse' => $this->klasse ?: null,
            'category' => $this->category ?: null,
            'comment' => $this->comment ?: null,
            'set_by' => auth()->user()?->email,
            'set_at' => now(),
        ]);
        $this->dispatch('label-saved');
    }

    // ---- helpers ----

    private function label(CoinFire $f, string $source): ?CoinMomentLabel
    {
        return CoinMomentLabel::where('trading_symbol_id', $f->trading_symbol_id)
            ->where('datetime', $f->datetime)->where('rule', $f->rule)
            ->where('source', $source)->first();
    }

    private function dayFireIds(): array
    {
        return $this->dayFires()->pluck('id')->all();
    }

    /** This day's fires (filtered), with manual labels attached. Memoized per (coin, date, filter). */
    private function dayFires()
    {
        $key = "{$this->coin}|{$this->date}|" . (int) $this->onlyExecuted;
        if ($this->fireMemo !== null && $this->fireMemoKey === $key) {
            return $this->fireMemo;
        }
        $start = Carbon::parse($this->date)->startOfDay();
        $end = (clone $start)->endOfDay();
        $q = CoinFire::query()->where('trading_symbol_id', $this->coin)
            ->whereBetween('datetime', [$start, $end])->orderBy('datetime');
        if ($this->onlyExecuted) {
            $q->where('is_executed', true);
        }
        $fires = $q->get();
        CoinMomentLabel::attachManual($fires, $this->coin, $start, $end);

        $this->fireMemo = $fires;
        $this->fireMemoKey = $key;
        return $fires;
    }

    private function detail(): ?array
    {
        if (! $this->selId) return null;
        $f = CoinFire::with('period')->find($this->selId);
        if (! $f) return null;

        $markers = ['buy' => $f->datetime->getTimestampMs()];
        if ($f->selling_datetime) $markers['sell'] = $f->selling_datetime->getTimestampMs();
        if ($f->best_sell_datetime) $markers['bestsell'] = $f->best_sell_datetime->getTimestampMs();

        // horizon peak markers (where each horizon's max sits) + the period band
        $hz = $f->horizons ?? [];
        foreach (self::HORIZONS as $h) {
            $entry = $hz[(string) $h] ?? null;
            $peakAt = is_array($entry) ? ($entry['peak_at'] ?? null) : null;
            if ($peakAt) $markers["h{$h}"] = Carbon::parse($peakAt)->getTimestampMs();
        }
        if ($per = $f->period) {
            $markers['pfrom'] = $per->period_from->getTimestampMs();
            $markers['pto'] = $per->period_to->getTimestampMs();
            $markers['pbest'] = $per->best_entry->getTimestampMs();
            if ($per->peak_datetime) $markers['peak'] = $per->peak_datetime->getTimestampMs();
        }
        [$from, $to] = $this->windowAround($markers);

        $manualLabel = $this->label($f, 'manual');

        return [
            'id' => $f->id,
            'title' => 'Trade · rule ' . $f->rule . ' · ' . $this->localFmt($f->datetime, 'd M H:i:s'),
            'is_executed' => $f->is_executed,
            'auto_klasse' => $f->autoKlasseKey(),
            'legacy_klasse' => $f->legacyKlasseKey(),
            'manual_klasse' => $manualLabel?->manual_klasse ?? '',
            'price' => $this->priceBetween($from, $to),
            'markers' => $markers,
            'horizons' => $this->horizonRow($f),
            'stats' => array_filter([
                'rule' => $f->rule,
                'beste upside % (60m)' => $f->best_upside,
                'vroege dip %' => $f->lowest10,
                'aankoopprijs' => $f->buy_price,
                'onze sell-winst %' => $f->is_executed ? $f->profit_loss : null,
                'beste sell binnen hold' => ($f->best_sell_price && $f->buy_price)
                    ? round(($f->best_sell_price - $f->buy_price) / $f->buy_price * 100, 2) . '%' : null,
                'legacy P&L' => $f->legacy_profit_loss,
            ], fn ($v) => $v !== null && $v !== ''),
        ];
    }

    /** [{h, up, peak_at}] for the horizon columns/tooltips. Null-safe: a horizon may be absent
     *  (window had no forward data → persist omits it). */
    private function horizonRow(CoinFire $f): array
    {
        $hz = $f->horizons ?? [];
        return collect(self::HORIZONS)->map(function ($h) use ($hz) {
            $e = $hz[(string) $h] ?? null;
            $e = is_array($e) ? $e : [];
            return [
                'h' => $h,
                'up' => $e['up'] ?? null,
                'peak_at' => isset($e['peak_at']) ? $this->localFmt(Carbon::parse($e['peak_at'])) : null,
            ];
        })->all();
    }

    public function render()
    {
        $start = Carbon::parse($this->date)->startOfDay();
        $end = (clone $start)->endOfDay();

        $fires = $this->dayFires();

        $chart = [
            'price' => $this->priceBetween($start, $end, 600),
            'fires' => $fires->where('is_executed', true)->map(fn ($f) => [
                'id' => $f->id, 'x' => $f->datetime->getTimestampMs(),
                'rule' => $f->rule, 'klasse' => $f->klasseKey(), 'best' => $f->best_upside,
            ])->values(),
        ];

        // rows for the table
        $rows = $fires->map(fn ($f) => [
            'id' => $f->id,
            'time' => $this->localFmt($f->datetime),
            'rule' => $f->rule,
            'is_executed' => $f->is_executed,
            'shadow_parent' => $this->localFmt($f->shadow_parent),
            'horizons' => $this->horizonRow($f),
            'best_upside' => $f->best_upside,
            'lowest10' => $f->lowest10,
            'profit_loss' => $f->is_executed ? $f->profit_loss : null,
            'auto' => $f->autoKlasseKey(),
            'legacy' => $f->legacyKlasseKey(),
            'manual' => $f->manualLabel?->manual_klasse,
            'decision' => $f->manualLabel?->decision,
            // sell-engine left money on the table: positive upside, negative realised P&L
            'sell_gap' => ($f->is_executed && $f->best_upside !== null && $f->best_upside > 1
                           && $f->profit_loss !== null && $f->profit_loss < 0),
        ])->values();

        $labeled = $fires->filter(fn ($f) => $f->manualLabel?->manual_klasse)->count();

        return view('livewire.trades.promising-labeler', [
            'rows' => $rows,
            'chart' => $chart,
            'detail' => $this->detail(),
            'horizons' => self::HORIZONS,
            'categories' => CoinAnnotation::CATEGORIES,
            'decisions' => CoinMomentLabel::DECISIONS,
            'dayCount' => $this->dayList()->count(),
            'fireCount' => $fires->count(),
            'labeledCount' => $labeled,
        ]);
    }
}
