<?php

namespace App\Livewire\Trades;

use App\Livewire\Trades\Concerns\InteractsWithCoinChart;
use App\Models\CoinAnnotation;
use App\Models\CoinFire;
use App\Models\CoinMomentLabel;
use App\Models\CoinMomentSell;
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

    // Unified promising-definitie (filter == auto). Promising =
    //   up5  >= PROM_UP5   (stijgt binnen +5min minstens een beetje), EN
    //   up15 >= PROM_REACH (bereikt >= 3% BINNEN +15min — niet pas daarna), EN
    //   vroege_dip >= PROM_DIP (geen diskwalificerende dip in de eerste ~10 ticks).
    // Dit is ook de default auto-klasse en straks de default-invulling.
    public const PROM_UP5 = 0.5;
    public const PROM_REACH = 3.0;
    public const PROM_DIP = -0.5;
    // spike-filter: als de piek een geisoleerde 1-tick spike is (beide buur-ticks >= dit % eronder),
    // is de winst in de praktijk niet te verhandelen (koop/verkoop nooit op tijd). Conservatief gezet:
    // ok-marks komen zelden boven ~2%, de spikes (zoals NOS 7%) er ruim boven.
    public const PROM_SPIKE_ISO = 3.0;

    // Groepering van ok-gemarkeerde momenten (decision='yes') = 1 stijging/trade. Een volgende
    // ok-moment hoort NIET bij de vorige als de gap > GROUP_GAP_MIN, OF als er tussen beide een
    // koersdaling van >= GROUP_DROP_PCT% zit (harde daling = aparte trade).
    public const GROUP_GAP_MIN = 5;
    public const GROUP_DROP_PCT = 1.0;
    // lookback vóór de dag zodat een stijging die over middernacht doorloopt niet kunstmatig splitst.
    public const GROUP_LOOKBACK_MIN = 12;

    #[Url] public string $coin = '2525';
    #[Url] public string $date = '';
    #[Url] public string $view = 'promising';   // promising | all | trades | executed
    #[Url] public string $sellMin = '';         // '' | '3' | '5' | '10' — filter: onze sell-winst >= x%

    // modal / label state
    public ?string $selKey = null;              // 'Y-m-d H:i:s' van het geselecteerde moment
    public string $decision = '';
    public string $klasse = '';
    public string $bestSell = '';            // override beste-sell — alleen TIJD (H:i:s); datum = moment-datum
    public string $hardSell = '';            // harde verkoop — alleen TIJD (H:i:s); datum = moment-datum
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
    public function updatedSellMin(): void { $this->resetMemo(); $this->closeDetail(); }

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

    /** Handmatige groep-grens op een ok-moment: 'break' (ontkoppel/split) | 'join' (koppel aan vorige). */
    public function setGroupBreak(string $key, string $mode): void
    {
        $l = CoinMomentLabel::where('trading_symbol_id', $this->coin)
            ->where('datetime', Carbon::parse($key))->where('source', 'manual')->first();
        if (! $l) return;   // alleen op een bestaand ok-label (markeer eerst ok)
        $l->group_break = ($l->group_break === $mode) ? null : $mode;   // klik op de actieve = auto
        $l->save();
        $this->momentMemo = $this->momentMemoKey = null;
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
        // Alleen het tijdgedeelte tonen — datum is altijd de moment-datum (hold max 60 min).
        $this->bestSell = $l?->best_sell_datetime ? Carbon::parse($l->best_sell_datetime)->format('H:i:s') : '';
        $this->hardSell = $l?->hard_sell_datetime ? Carbon::parse($l->hard_sell_datetime)->format('H:i:s') : '';
    }

    public function closeDetail(): void
    {
        $this->reset(['selKey', 'decision', 'klasse', 'category', 'comment', 'bestSell', 'hardSell']);
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
        // Combineer tijd (H:i:s) met de MOMENT-datum tot een volledige datetime — sneller invoeren
        // omdat de datum altijd dezelfde is. manual_set_at=now() markeert het als handmatig.
        $momentDate = Carbon::parse($this->selKey)->startOfDay();
        $combine = fn (string $t) => $t ? $momentDate->copy()->setTimeFromTimeString($t) : null;
        CoinMomentLabel::setManual($this->coin, null, Carbon::parse($this->selKey), [
            'decision' => $this->decision ?: null,
            'manual_klasse' => $this->klasse ?: null,
            'category' => $this->category ?: null,
            'comment' => $this->comment ?: null,
            'best_sell_datetime' => $combine($this->bestSell),
            'hard_sell_datetime' => $combine($this->hardSell),
            'manual_set_at' => now(),
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
        // lookback vóór de dag (voor cross-midnight groepering) + 60min tail (voor de horizons)
        $from = Carbon::parse($this->date)->startOfDay()->subMinutes(self::GROUP_LOOKBACK_MIN);
        $tail = Carbon::parse($this->date)->endOfDay()->addMinutes(max(self::HORIZONS));
        $rows = DB::table('indicators')->select('datetime', 'price', 'volume_found')
            ->where('trading_symbol_id', $this->coin)->where('indicator', 'volumeud')
            ->whereNotNull('price')->whereBetween('datetime', [$from, $tail])
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
        // piek-isolatie over het 60-min venster [$i, $j): hoe scherp ligt de piek geisoleerd (spike).
        // Beide buur-ticks ver onder de piek = 1-tick spike die je in de praktijk niet kunt verhandelen.
        $pk = $i;
        for ($k = $i; $k < $j; $k++) {
            if ($px[$k] > $px[$pk]) $pk = $k;
        }
        $peak = $px[$pk];
        $leftDrop = ($pk > $i && $peak > 0) ? ($peak - $px[$pk - 1]) / $peak * 100 : 0.0;
        $rightDrop = ($pk + 1 < $j && $peak > 0) ? ($peak - $px[$pk + 1]) / $peak * 100 : 0.0;
        return [
            'max' => $buy > 0 ? round(($maxAll - $buy) / $buy * 100, 3) : null,
            'low10' => $buy > 0 ? round(($low - $buy) / $buy * 100, 3) : null,
            'spike_iso' => round(min($leftDrop, $rightDrop), 3),
            'hz' => $hz,
        ];
    }

    /** Grootste koersdaling (%) van de prijs op index $i1 tot het laagste punt in ($i1, $i2]. */
    private function dropBetween(array $px, int $i1, int $i2): float
    {
        $base = $px[$i1];
        if ($base <= 0) return 0.0;
        $low = $base;
        for ($k = $i1 + 1; $k <= $i2; $k++) {
            if ($px[$k] < $low) $low = $px[$k];
        }
        return ($base - $low) / $base * 100;
    }

    /**
     * THE unified promising-definitie — gebruikt door de filter én de auto-klasse (ze zijn hetzelfde).
     * Promising = de winst bereikt >= REACH% binnen het venster (op +15 of een latere periode; de
     * horizons zijn cumulatief dus dit is max60 >= REACH) EN geen diskwalificerende vroege dip
     * (vroege_dip >= DIP_MIN). Een moment dat nooit 3% haalt is niet promising, ook al rijst het wat.
     */
    public static function isPromising(?float $up5, ?float $up15, ?float $dip, ?float $spikeIso = 0.0): bool
    {
        if ($up5 === null || $up15 === null) return false;
        return $up5 >= self::PROM_UP5 && $up15 >= self::PROM_REACH
            && (($dip ?? -99) >= self::PROM_DIP)
            && (($spikeIso ?? 0.0) < self::PROM_SPIKE_ISO);   // geen geisoleerde spike
    }

    /** up5/up15/dip/spike-iso uit een metrics-array halen voor isPromising. */
    private static function promInputs(array $m): array
    {
        return [$m['hz'][5]['up'] ?? null, $m['hz'][15]['up'] ?? null, $m['low10'], $m['spike_iso'] ?? 0.0];
    }

    /** Auto-klasse: goed == promising (zelfde definitie als de filter); anders middel/slecht op upside. */
    private static function autoKlasse(array $m): string
    {
        if (self::isPromising(...self::promInputs($m))) {
            return 'goed';
        }
        if (($m['max'] ?? 0) >= 0.5) return 'middel';
        return 'slecht';
    }

    /** The day's rows after the view filter + a row cap. Memoized per (coin,date,view). */
    private function dayMoments(): array
    {
        $key = "{$this->coin}|{$this->date}|{$this->view}|{$this->sellMin}";
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
        $sells = CoinMomentSell::byMoment($this->coin, $start, $end);           // per-moment sell-engine P&L (punt 4)

        // pass 1: metrics + promising-flag voor ELKE in-day tick (nodig voor stabiele groepen los van de view)
        $endTs = $end->getTimestamp();
        $startTs = $start->getTimestamp();
        $moments = [];   // key => ['when','i','m','prom']
        $seen = [];
        foreach ($dt as $i => $when) {
            if ($when->getTimestamp() > $endTs) break;     // tail (forward window) is geen eigen rij
            if ($when->getTimestamp() < $startTs) continue; // pre-dag lookback-tick: geen eigen rij
            $k = CoinMomentLabel::momentKey($when);
            if (isset($seen[$k])) continue;                // distinct datumtijd
            $seen[$k] = true;
            $m = $this->metricsFrom($dt, $px, $i);
            $moments[$k] = ['when' => $when, 'i' => $i, 'm' => $m,
                'prom' => self::isPromising(...self::promInputs($m))];
        }

        // seed: het laatste ok-moment VÓÓR vandaag (binnen de lookback) zodat een stijging die over
        // middernacht doorloopt niet kunstmatig als nieuwe groep start.
        $seedTs = $seedI = $seedTime = null;
        $seedLabel = CoinMomentLabel::where('trading_symbol_id', $this->coin)->where('source', 'manual')
            ->where('decision', 'yes')->where('datetime', '<', $start)
            ->where('datetime', '>=', (clone $start)->subMinutes(self::GROUP_LOOKBACK_MIN))
            ->orderByDesc('datetime')->first();
        if ($seedLabel) {
            $sk = CoinMomentLabel::momentKey($seedLabel->datetime);
            foreach ($dt as $idx => $d) {
                if (CoinMomentLabel::momentKey($d) === $sk) { $seedI = $idx; break; }
            }
            if ($seedI !== null) { $seedTs = $dt[$seedI]->getTimestamp(); $seedTime = $this->localFmt($dt[$seedI]); }
        }

        // pass 2: groepeer OK-gemarkeerde momenten (decision='yes') = 1 stijging/trade. Nieuw groep als
        // de gap > GROUP_GAP_MIN min, OF er tussen het vorige ok-moment en dit een drop >= GROUP_DROP_PCT zit.
        $groupOf = []; $groups = []; $gid = 0; $prevTs = $seedTs; $prevI = $seedI;
        foreach ($moments as $k => $mo) {
            $lab = $labels->get($k);
            if (($lab?->decision) !== 'yes') continue;   // alleen ok-momenten groeperen
            $ts = $mo['when']->getTimestamp();
            // handmatige override wint van de auto-regels (gap/drop)
            $split = match ($lab->group_break) {
                'join'  => $prevTs === null,             // koppel aan vorige (tenzij er geen vorige is)
                'break' => true,                         // forceer nieuwe groep
                default => $prevTs === null
                    || ($ts - $prevTs) > self::GROUP_GAP_MIN * 60
                    || $this->dropBetween($px, $prevI, $mo['i']) >= self::GROUP_DROP_PCT,
            };
            if ($split || $gid === 0) {
                $cont = ($gid === 0 && ! $split && $seedTs !== null);   // groep 1 zet de stijging van gisteren voort
                $groups[++$gid] = ['members' => [], 'cont_from' => $cont ? $seedTime : null];
            }
            $groupOf[$k] = $gid;
            $groups[$gid]['members'][] = [
                'key' => $k, 'time' => $this->localFmt($mo['when']),
                'decision' => 'yes', 'manual' => $labels->get($k)?->manual_klasse,
            ];
            $prevTs = $ts; $prevI = $mo['i'];
        }

        // pass 3: bouw de display-rijen per view-filter
        $rows = []; $total = 0;
        foreach ($moments as $k => $mo) {
            $fire = $fires->get($k);
            if ($this->view === 'trades' && ! $fire) continue;
            if ($this->view === 'executed' && ! ($fire && $fire->is_executed)) continue;
            if ($this->view === 'promising' && ! $mo['prom']) continue;

            // sell-engine P&L: per-moment store (punt 4) > executed-fire fallback > nog niet berekend
            $pl = $sells->get($k)?->profit_loss ?? (($fire && $fire->is_executed) ? $fire->profit_loss : null);
            // filter onze sell-winst >= x% (null = nog niet berekend telt niet mee)
            if ($this->sellMin !== '' && ($pl === null || $pl < (float) $this->sellMin)) continue;

            $total++;
            if (count($rows) >= self::ROW_CAP) continue;   // tel door, render begrensd
            $m = $mo['m']; $i = $mo['i'];
            $g = $groupOf[$k] ?? null;
            $label = $labels->get($k);
            $rows[] = [
                'key' => $k,
                'time' => $this->localFmt($mo['when']),
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
                'profit_loss' => $pl,
                'auto' => self::autoKlasse($m),
                'legacy' => $legacy->get($k)?->manual_klasse,   // snapped legacy label on this moment
                'manual' => $label?->manual_klasse,
                'decision' => $label?->decision,
                'group' => $g,
                'group_lead' => $g ? ($groups[$g]['cont_from'] ? '↩ '.$groups[$g]['cont_from'] : $groups[$g]['members'][0]['time']) : null,
                'group_size' => $g ? count($groups[$g]['members']) : null,
                'sell_gap' => ($fire && $fire->is_executed && ($m['max'] ?? 0) > 1
                               && $fire->profit_loss !== null && $fire->profit_loss < 0),
            ];
        }

        $this->momentMemo = [
            'rows' => $rows,
            'total' => $total,
            'truncated' => $total > count($rows),
            'labeled' => collect($rows)->filter(fn ($r) => $r['manual'] || $r['decision'])->count(),
            'groups' => $groups,
            'groupOf' => $groupOf,
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
        $sell = CoinMomentSell::where('trading_symbol_id', $this->coin)->where('datetime', $when)->first();
        $pl = $sell?->profit_loss ?? (($fire && $fire->is_executed) ? $fire->profit_loss : null);

        $markers = ['buy' => $when->getTimestampMs()];
        if ($fire?->selling_datetime) $markers['sell'] = $fire->selling_datetime->getTimestampMs();
        // marker zet de beste-sell-lijn op de met-voorrang gekozen datum (handmatig > legacy > berekend)
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
        // beste-sell voorrang: handmatig > berekend (binnen [koop, onze verkoop]). Legacy ligt vaak
        // na onze verkoop (oude bot sloot anders) en is NIET door jou gekozen — tonen als info-regel.
        $manualLabel = CoinMomentLabel::where('trading_symbol_id', $this->coin)->where('source', 'manual')
            ->where('datetime', $when)->first();
        $legacyRow = CoinMomentLabel::where('trading_symbol_id', $this->coin)->where('source', 'legacy')
            ->where('datetime', $when)->first();
        $bestSellDt = $manualLabel?->best_sell_datetime ?? $fire?->best_sell_datetime;
        $bestSellSrc = $manualLabel?->best_sell_datetime ? 'handmatig'
            : ($fire?->best_sell_datetime ? 'berekend' : null);
        $bestSellPct = $bestSellDt && $buy
            ? ($this->priceAt($bestSellDt) ? round(($this->priceAt($bestSellDt) - $buy) / $buy * 100, 2) : null) : null;
        // changelog (klasse-veranderingen door heranalyse)
        $changes = \DB::table('coin_fires_changelog')->where('trading_symbol_id', $this->coin)
            ->where('datetime', $when)->orderByDesc('id')->limit(5)->get()->all();

        // groep waar dit moment bij hoort (zelfde stijging) — toont de andere instapmomenten + hun labels
        $gd = $this->dayMoments();
        $gid = $gd['groupOf'][$this->selKey] ?? null;
        $group = $gid ? $gd['groups'][$gid]['members'] : [];
        $okKeys = array_keys($gd['groupOf']);                 // ok-momenten in tijdvolgorde
        $isOk = $gid !== null;
        $hasPrevOk = $isOk && ! empty($okKeys) && $okKeys[0] !== $this->selKey;
        $grpBreak = $isOk ? CoinMomentLabel::where('trading_symbol_id', $this->coin)
            ->where('datetime', $when)->where('source', 'manual')->value('group_break') : null;

        return [
            'key' => $this->selKey,
            'group' => $group,
            'group_cont_from' => $gid ? ($gd['groups'][$gid]['cont_from'] ?? null) : null,
            'is_ok' => $isOk,
            'has_prev_ok' => $hasPrevOk,
            'group_break' => $grpBreak,
            'title' => 'Moment · ' . $this->localFmt($when, 'd M H:i:s') . ($fire ? " · trade rule {$fire->rule}" : ' · geen trade'),
            'is_trade' => (bool) $fire,
            'vol' => ($vf[$i] ?? 0) === 1,
            'auto_klasse' => self::autoKlasse($m),
            'promising' => self::isPromising(...self::promInputs($m)),
            'legacy_klasse' => $legacyLabel,
            'price' => $this->priceBetween($from, $to),
            'markers' => array_merge($markers, array_filter([
                'bestsell' => $bestSellDt ? Carbon::parse($bestSellDt)->getTimestampMs() : null,
                'hardsell' => $manualLabel?->hard_sell_datetime?->getTimestampMs(),
            ])),
            'horizons' => collect(self::HORIZONS)->map(fn ($h) => [
                'h' => $h, 'val' => $m['hz'][$h]['val'], 'peak_at' => $this->localFmt($m['hz'][$h]['peak_at']),
            ])->all(),
            // sell-engine nog niet gedraaid voor dit (promising) moment? -> toon een nette melding
            'sell_pending' => $pl === null && self::isPromising(...self::promInputs($m)),
            'best_sell' => $bestSellDt ? [
                'datetime' => $this->localFmt($bestSellDt),
                'pct' => $bestSellPct,
                'source' => $bestSellSrc,
            ] : null,
            'legacy_best_sell' => $legacyRow?->best_sell_datetime
                ? $this->localFmt($legacyRow->best_sell_datetime) : null,
            'hard_sell' => $manualLabel?->hard_sell_datetime ? $this->localFmt($manualLabel->hard_sell_datetime) : null,
            'changes' => array_map(fn ($r) => [
                'when' => Carbon::parse($r->created_at)->format('Y-m-d H:i'),
                'field' => $r->field, 'from' => $r->old_value, 'to' => $r->new_value, 'reason' => $r->reason,
            ], $changes),
            'manual_klasse_set' => (bool) $manualLabel?->manual_klasse,    // handmatige klasse leidend?
            'stats' => array_filter([
                'aankoopprijs' => $buy,
                'onze sell-winst %' => $pl,
                'sell exit' => $sell ? $this->localFmt($sell->selling_datetime) : null,
                'sell hoogste %' => $sell?->hi_pl,
                'sell laagste %' => $sell?->lo_pl,
                'vroege dip %' => $m['low10'],
                'legacy P&L' => $fire?->legacy_profit_loss,
            ], fn ($v) => $v !== null && $v !== ''),
        ];
    }

    /** Prijs op exact één tick — voor het % bij de beste-sell-datum. */
    private function priceAt(\DateTimeInterface $dt): ?float
    {
        [$dts, $px] = $this->series();
        foreach ($dts as $idx => $d) {
            if ($d->eq(Carbon::parse($dt))) return (float) $px[$idx];
        }
        return null;
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
