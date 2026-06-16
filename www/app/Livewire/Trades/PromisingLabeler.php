<?php

namespace App\Livewire\Trades;

use App\Livewire\Trades\Concerns\InteractsWithCoinChart;
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
 * Promising labeler (Epic L) — the rebuilt legacy simulate_buy review grid. Iterates EVERY distinct
 * volumeud datetime of the day (not just rule-fires) and, per moment, computes the multi-horizon
 * upside (+5/+10/+15/+30/+45/+60 min, sell-INdependent buy-quality) on the fly. A "promising" filter
 * (max upside over the horizons ≥ a threshold) keeps the shown set small so you don't render every tick.
 *
 * Quick ok/niet-ok ticks inline (no modal), saved instantly; quality + reason via the modal. Labels are
 * MOMENT-level (coin_moment_labels, rule=MOMENT_RULE) so they survive the persist re-fire and feed the
 * per-datetime promising tuning. A row that was also a trade shows its rule + OUR sell profit_loss; a
 * high upside with a negative profit_loss is a sell-engine defect, not a bad buy-moment.
 */
#[Layout('layouts.trading')]
class PromisingLabeler extends Component
{
    use InteractsWithCoinChart;

    public const HORIZONS = [5, 10, 15, 30, 45, 60];
    public const ROW_CAP = 1500;            // dekt een volledige dag volumeud-ticks (max ~1163/dag)

    // Unified promising-definitie (filter == auto). Promising = (up5 >= UP5_MIN OF up15 > REACH)
    // EN vroege_dip >= DIP_MIN. Dit is ook de default auto-klasse en straks de default-invulling.
    public const PROM_UP5 = 0.5;
    public const PROM_REACH = 3.0;
    public const PROM_DIP = -0.5;

    #[Url] public string $coin = '2525';
    #[Url] public string $date = '';
    #[Url] public string $view = 'promising';   // promising | all | trades | executed

    // modal / label state
    public ?string $selKey = null;              // 'Y-m-d H:i:s' van het geselecteerde moment
    public string $decision = '';
    public string $klasse = '';
    public string $category = '';
    public string $comment = '';

    /** Request-scoped memo's. */
    private ?array $seriesMemo = null;
    private ?string $seriesMemoKey = null;
    private ?array $momentMemo = null;
    private ?string $momentMemoKey = null;

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
        } else {
            $this->date = $days[max(0, min($days->count() - 1, $i + $dir))];
        }
        $this->resetMemo();
        $this->closeDetail();
    }

    public function updatedCoin(): void
    {
        $this->date = optional($this->dayList()->first())?->format('Y-m-d') ?? $this->date;
        $this->resetMemo();
        $this->closeDetail();
    }

    public function updatedDate(): void { $this->resetMemo(); $this->closeDetail(); }
    public function updatedView(): void { $this->resetMemo(); $this->closeDetail(); }

    private function resetMemo(): void
    {
        $this->seriesMemo = $this->seriesMemoKey = $this->momentMemo = $this->momentMemoKey = null;
    }

    // ---- inline quick tick (no modal) ----

    /** Toggle the ok/niet-ok decision for a moment and save instantly. */
    public function setDecision(string $key, string $val): void
    {
        $dt = Carbon::parse($key);
        $existing = CoinMomentLabel::where('trading_symbol_id', $this->coin)
            ->where('datetime', $dt)->where('source', 'manual')->first();
        $new = ($existing?->decision === $val) ? null : $val;   // klik op de actieve = uitzetten
        CoinMomentLabel::setManual($this->coin, $existing?->symbol, $dt, [
            'decision' => $new,
            'manual_klasse' => $existing?->manual_klasse,
            'category' => $existing?->category,
            'comment' => $existing?->comment,
        ], auth()->user()?->email);
        if ($this->selKey === $key) $this->decision = $new ?? '';   // modal-radio reflecteren
        $this->momentMemo = $this->momentMemoKey = null;   // row reflecteert de nieuwe staat
    }

    // ---- modal ----

    public function selectMoment(string $key): void { $this->open($key); }

    private function open(string $key): void
    {
        $this->selKey = $key;
        $l = CoinMomentLabel::where('trading_symbol_id', $this->coin)
            ->where('datetime', Carbon::parse($key))->where('source', 'manual')->first();
        $this->decision = $l?->decision ?? '';
        $this->klasse = $l?->manual_klasse ?? '';
        $this->category = $l?->category ?? '';
        $this->comment = $l?->comment ?? '';
    }

    public function closeDetail(): void
    {
        $this->reset(['selKey', 'decision', 'klasse', 'category', 'comment']);
    }

    public function navDetail(int $dir): void
    {
        $keys = array_column($this->dayMoments()['rows'], 'key');
        $i = array_search($this->selKey, $keys, true);
        if ($i === false) return;
        $this->open($keys[max(0, min(count($keys) - 1, $i + $dir))]);
    }

    public function saveLabel(): void
    {
        if (! $this->selKey) return;
        CoinMomentLabel::setManual($this->coin, null, Carbon::parse($this->selKey), [
            'decision' => $this->decision ?: null,
            'manual_klasse' => $this->klasse ?: null,
            'category' => $this->category ?: null,
            'comment' => $this->comment ?: null,
        ], auth()->user()?->email);
        $this->momentMemo = $this->momentMemoKey = null;
        $this->dispatch('label-saved');
    }

    // ---- data ----

    /**
     * Raw (un-downsampled) volumeud price series for [startOfDay, endOfDay + 60min]; the candidate
     * MOMENTS. Only volumeud ticks are valid buy-moments: every buy rule (20-23) has a volumeud
     * `currentvalue` subrule with time_ago=5 (the volumeud must be ≤5s fresh), so on a non-volumeud
     * datetime the last volumeud is stale and the rule can't fire. The other indicators are still
     * available AS-OF at each volumeud tick (the engine reads their last-known value ≤ T).
     */
    private function series(): array
    {
        $key = "{$this->coin}|{$this->date}";
        if ($this->seriesMemo !== null && $this->seriesMemoKey === $key) {
            return $this->seriesMemo;
        }
        $start = Carbon::parse($this->date)->startOfDay();
        $tail = (clone $start)->endOfDay()->addMinutes(max(self::HORIZONS));
        $rows = DB::table('indicators')->select('datetime', 'price', 'volume_found')
            ->where('trading_symbol_id', $this->coin)->where('indicator', 'volumeud')
            ->whereNotNull('price')->whereBetween('datetime', [$start, $tail])
            ->orderBy('datetime')->get();
        $dt = $px = $vf = [];
        foreach ($rows as $r) { $dt[] = Carbon::parse($r->datetime); $px[] = (float) $r->price; $vf[] = (int) $r->volume_found; }
        $this->seriesMemo = [$dt, $px, $vf];
        $this->seriesMemoKey = $key;
        return $this->seriesMemo;
    }

    /** Multi-horizon upside + early dip from a start index (single forward pass). */
    private function metricsFrom(array $dt, array $px, int $i): array
    {
        $buy = $px[$i];
        $n = count($dt);
        $j = $i; $runMax = $buy; $runMin = $buy; $runMaxAt = $dt[$i]; $maxAll = $buy;
        $hz = [];
        foreach (self::HORIZONS as $h) {
            $limit = $dt[$i]->copy()->addMinutes($h);
            while ($j < $n && $dt[$j] <= $limit) {
                if ($px[$j] > $runMax) { $runMax = $px[$j]; $runMaxAt = $dt[$j]; }
                if ($px[$j] < $runMin) { $runMin = $px[$j]; }
                $j++;
            }
            $up = $buy > 0 ? round(($runMax - $buy) / $buy * 100, 3) : null;
            $down = $buy > 0 ? round(($runMin - $buy) / $buy * 100, 3) : null;
            // signed waarde: de upside als die er was, anders de (negatieve) drawdown — toont dat +5
            // niet "0.00 vlak" maar negatief was (12:41:01).
            $val = ($up !== null && $up > 0.005) ? $up : $down;
            $hz[$h] = ['up' => $up, 'down' => $down, 'val' => $val, 'peak_at' => $runMaxAt];
            $maxAll = max($maxAll, $runMax);
        }
        $low = $buy;
        for ($k = $i; $k < min($i + 10, $n); $k++) {
            if ($px[$k] < $low) $low = $px[$k];
        }
        return [
            'max' => $buy > 0 ? round(($maxAll - $buy) / $buy * 100, 3) : null,
            'low10' => $buy > 0 ? round(($low - $buy) / $buy * 100, 3) : null,
            'hz' => $hz,
        ];
    }

    /**
     * THE unified promising-definitie — gebruikt door de filter én de auto-klasse (ze zijn hetzelfde).
     * Promising = de stijging komt snel genoeg ((up5 >= UP5_MIN) OF (up15 > REACH)) EN er is geen
     * diskwalificerende vroege dip (vroege_dip >= DIP_MIN).
     */
    public static function isPromising(?float $up5, ?float $up15, ?float $dip): bool
    {
        if ($up5 === null || $up15 === null) return false;
        return (($up5 >= self::PROM_UP5) || ($up15 > self::PROM_REACH)) && (($dip ?? -99) >= self::PROM_DIP);
    }

    /** Auto-klasse: goed == promising (zelfde definitie als de filter); anders middel/slecht op upside. */
    private static function autoKlasse(array $m): string
    {
        if (self::isPromising($m['hz'][5]['up'] ?? null, $m['hz'][15]['up'] ?? null, $m['low10'])) {
            return 'goed';
        }
        if (($m['max'] ?? 0) >= 0.5) return 'middel';
        return 'slecht';
    }

    /** The day's rows after the view filter + a row cap. Memoized per (coin,date,view). */
    private function dayMoments(): array
    {
        $key = "{$this->coin}|{$this->date}|{$this->view}";
        if ($this->momentMemo !== null && $this->momentMemoKey === $key) {
            return $this->momentMemo;
        }
        $start = Carbon::parse($this->date)->startOfDay();
        $end = (clone $start)->endOfDay();
        [$dt, $px, $vf] = $this->series();

        // fires of the day keyed by moment (pick the executed one if present)
        $fires = CoinFire::query()->where('trading_symbol_id', $this->coin)
            ->whereBetween('datetime', [$start, $end])->orderByDesc('is_executed')->get()
            ->groupBy(fn ($f) => CoinMomentLabel::momentKey($f->datetime))
            ->map(fn ($g) => $g->first());            // executed wins (sorted desc)
        $labels = CoinMomentLabel::manualByMoment($this->coin, $start, $end);
        $legacy = CoinMomentLabel::legacyByMoment($this->coin, $start, $end);   // snapped to our ticks

        $rows = [];
        $total = 0;
        $seen = [];
        $endTs = $end->getTimestamp();
        foreach ($dt as $i => $when) {
            if ($when->getTimestamp() > $endTs) break;     // tail (forward window) is not its own row
            $k = CoinMomentLabel::momentKey($when);
            if (isset($seen[$k])) continue;                // distinct datumtijd
            $seen[$k] = true;
            $fire = $fires->get($k);

            // view-filter dat geen horizons nodig heeft eerst
            if ($this->view === 'trades' && ! $fire) continue;
            if ($this->view === 'executed' && ! ($fire && $fire->is_executed)) continue;

            // horizons alleen berekenen waar nodig (promising-filter, of binnen de render-cap)
            $m = null;
            if ($this->view === 'promising') {
                $m = $this->metricsFrom($dt, $px, $i);
                if (! self::isPromising($m['hz'][5]['up'] ?? null, $m['hz'][15]['up'] ?? null, $m['low10'])) continue;
            }

            $total++;
            if (count($rows) >= self::ROW_CAP) continue;   // tel door, render begrensd
            $m ??= $this->metricsFrom($dt, $px, $i);

            $label = $labels->get($k);
            $rows[] = [
                'key' => $k,
                'time' => $this->localFmt($when),
                'is_trade' => (bool) $fire,
                'rule' => $fire?->rule,
                'is_executed' => (bool) ($fire?->is_executed),
                'vol' => $vf[$i] === 1,                          // volume_found op deze tick (punt 6)
                'horizons' => collect(self::HORIZONS)->map(fn ($h) => [
                    'h' => $h, 'val' => $m['hz'][$h]['val'],     // signed: upside, of de negatieve dip
                    'peak_at' => $this->localFmt($m['hz'][$h]['peak_at']),
                ])->all(),
                'max_up' => $m['max'],
                'low10' => $m['low10'],
                'profit_loss' => ($fire && $fire->is_executed) ? $fire->profit_loss : null,
                'auto' => self::autoKlasse($m),
                'legacy' => $legacy->get($k)?->manual_klasse,   // snapped legacy label on this moment
                'manual' => $label?->manual_klasse,
                'decision' => $label?->decision,
                'sell_gap' => ($fire && $fire->is_executed && ($m['max'] ?? 0) > 1
                               && $fire->profit_loss !== null && $fire->profit_loss < 0),
            ];
        }

        $this->momentMemo = [
            'rows' => $rows,
            'total' => $total,
            'truncated' => $total > count($rows),
            'labeled' => collect($rows)->filter(fn ($r) => $r['manual'] || $r['decision'])->count(),
        ];
        $this->momentMemoKey = $key;
        return $this->momentMemo;
    }

    private function detail(): ?array
    {
        if (! $this->selKey) return null;
        $when = Carbon::parse($this->selKey);
        [$dt, $px, $vf] = $this->series();
        $i = null;
        foreach ($dt as $idx => $d) { if ($d->eq($when)) { $i = $idx; break; } }
        if ($i === null) return null;
        $m = $this->metricsFrom($dt, $px, $i);
        $buy = $px[$i];

        $start = Carbon::parse($this->date)->startOfDay();
        $end = (clone $start)->endOfDay();
        $fire = CoinFire::query()->where('trading_symbol_id', $this->coin)->where('datetime', $when)
            ->orderByDesc('is_executed')->first();

        $markers = ['buy' => $when->getTimestampMs()];
        if ($fire?->selling_datetime) $markers['sell'] = $fire->selling_datetime->getTimestampMs();
        if ($fire?->best_sell_datetime) $markers['bestsell'] = $fire->best_sell_datetime->getTimestampMs();
        foreach (self::HORIZONS as $h) {
            $markers["h{$h}"] = $m['hz'][$h]['peak_at']->getTimestampMs();
        }
        if ($fire && $fire->period_id && ($per = CoinPeriod::find($fire->period_id))) {
            $markers['pfrom'] = $per->period_from->getTimestampMs();
            $markers['pto'] = $per->period_to->getTimestampMs();
            $markers['pbest'] = $per->best_entry->getTimestampMs();
            if ($per->peak_datetime) $markers['peak'] = $per->peak_datetime->getTimestampMs();
        }
        [$from, $to] = $this->windowAround($markers);

        $legacyLabel = CoinMomentLabel::where('trading_symbol_id', $this->coin)->where('source', 'legacy')
            ->where('datetime', $when)->value('manual_klasse');

        return [
            'key' => $this->selKey,
            'title' => 'Moment · ' . $this->localFmt($when, 'd M H:i:s') . ($fire ? " · trade rule {$fire->rule}" : ' · geen trade'),
            'is_trade' => (bool) $fire,
            'vol' => ($vf[$i] ?? 0) === 1,
            'auto_klasse' => self::autoKlasse($m),
            'promising' => self::isPromising($m['hz'][5]['up'] ?? null, $m['hz'][15]['up'] ?? null, $m['low10']),
            'legacy_klasse' => $legacyLabel,
            'price' => $this->priceBetween($from, $to),
            'markers' => $markers,
            'horizons' => collect(self::HORIZONS)->map(fn ($h) => [
                'h' => $h, 'val' => $m['hz'][$h]['val'], 'peak_at' => $this->localFmt($m['hz'][$h]['peak_at']),
            ])->all(),
            'stats' => array_filter([
                'beste upside % (60m)' => $m['max'],
                'vroege dip %' => $m['low10'],
                'aankoopprijs' => $buy,
                'onze sell-winst %' => ($fire && $fire->is_executed) ? $fire->profit_loss : null,
                'legacy P&L' => $fire?->legacy_profit_loss,
            ], fn ($v) => $v !== null && $v !== ''),
        ];
    }

    public function render()
    {
        $start = Carbon::parse($this->date)->startOfDay();
        $end = (clone $start)->endOfDay();
        $data = $this->dayMoments();

        $fires = CoinFire::query()->where('trading_symbol_id', $this->coin)->where('is_executed', true)
            ->whereBetween('datetime', [$start, $end])->get();
        CoinMomentLabel::attachManual($fires, $this->coin, $start, $end);

        $chart = [
            'price' => $this->priceBetween($start, $end, 600),
            'fires' => $fires->map(fn ($f) => [
                'x' => $f->datetime->getTimestampMs(), 'rule' => $f->rule, 'klasse' => $f->klasseKey(),
            ])->values(),
        ];

        return view('livewire.trades.promising-labeler', [
            'rows' => $data['rows'],
            'total' => $data['total'],
            'truncated' => $data['truncated'],
            'labeledCount' => $data['labeled'],
            'chart' => $chart,
            'detail' => $this->detail(),
            'horizons' => self::HORIZONS,
            'categories' => CoinAnnotation::CATEGORIES,
            'dayCount' => $this->dayList()->count(),
            'rowCap' => self::ROW_CAP,
        ]);
    }
}
