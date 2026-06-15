<?php

namespace App\Livewire\Trades;

use App\Models\CoinAnnotation;
use App\Models\CoinFire;
use App\Models\CoinMomentLabel;
use App\Models\CoinPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
    public const HORIZONS = [5, 10, 15, 30, 45, 60];

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
        CoinMomentLabel::updateOrCreate(
            ['trading_symbol_id' => $f->trading_symbol_id, 'datetime' => $f->datetime, 'rule' => $f->rule, 'source' => 'manual'],
            [
                'symbol' => $f->symbol,
                'decision' => $this->decision ?: null,
                'manual_klasse' => $this->klasse ?: null,
                'category' => $this->category ?: null,
                'comment' => $this->comment ?: null,
                'set_by' => auth()->user()?->email,
                'set_at' => now(),
            ],
        );
        // best-effort back-compat cache on the fire (re-fire wipes it)
        $f->manual_klasse = $this->klasse ?: null;
        $f->save();
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

    /** This day's fires (filtered), with manual + legacy labels attached. */
    private function dayFires()
    {
        $start = Carbon::parse($this->date)->startOfDay();
        $end = (clone $start)->endOfDay();
        $q = CoinFire::query()->where('trading_symbol_id', $this->coin)
            ->whereBetween('datetime', [$start, $end])->orderBy('datetime');
        if ($this->onlyExecuted) {
            $q->where('is_executed', true);
        }
        $fires = $q->get();

        $manual = CoinMomentLabel::query()->where('trading_symbol_id', $this->coin)->where('source', 'manual')
            ->whereBetween('datetime', [$start, $end])->get()
            ->keyBy(fn ($l) => CoinMomentLabel::mapKey($l->datetime, $l->rule));
        foreach ($fires as $f) {
            $f->manualLabel = $manual->get(CoinMomentLabel::mapKey($f->datetime, $f->rule));
        }
        return $fires;
    }

    /** Format a UTC Carbon datetime in Amsterdam local time. */
    private function localFmt(?Carbon $dt, string $fmt = 'H:i:s'): ?string
    {
        return $dt?->setTimezone('Europe/Amsterdam')->format($fmt);
    }

    private function windowAround(array $markers): array
    {
        $ms = array_values(array_filter($markers, fn ($v) => $v !== null));
        return [
            Carbon::createFromTimestampMs(min($ms))->subMinutes(5),
            Carbon::createFromTimestampMs(max($ms))->addMinutes(5),
        ];
    }

    /** Downsampled volumeud price between two datetimes (read from brain.indicators). */
    private function priceBetween($from, $to, int $cap = 400): array
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

    private function detail(): ?array
    {
        if (! $this->selId) return null;
        $f = CoinFire::find($this->selId);
        if (! $f) return null;

        $markers = ['buy' => $f->datetime->getTimestampMs()];
        if ($f->selling_datetime) $markers['sell'] = $f->selling_datetime->getTimestampMs();
        if ($f->best_sell_datetime) $markers['bestsell'] = $f->best_sell_datetime->getTimestampMs();

        // horizon peak markers (where each horizon's max sits) + the period band
        $hz = $f->horizons ?? [];
        foreach (self::HORIZONS as $h) {
            $peakAt = $hz[(string) $h]['peak_at'] ?? null;
            if ($peakAt) $markers["h{$h}"] = Carbon::parse($peakAt)->getTimestampMs();
        }
        if ($f->period_id && ($per = CoinPeriod::find($f->period_id))) {
            $markers['pfrom'] = $per->period_from->getTimestampMs();
            $markers['pto'] = $per->period_to->getTimestampMs();
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

    /** [{h, up, peak_at}] for the horizon columns/tooltips. */
    private function horizonRow(CoinFire $f): array
    {
        $hz = $f->horizons ?? [];
        return collect(self::HORIZONS)->map(fn ($h) => [
            'h' => $h,
            'up' => $hz[(string) $h]['up'] ?? null,
            'peak_at' => isset($hz[(string) $h]['peak_at'])
                ? $this->localFmt(Carbon::parse($hz[(string) $h]['peak_at'])) : null,
        ])->all();
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
