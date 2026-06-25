<?php

namespace App\Livewire\Coins;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Munten — kansrijkheid PER KALENDERWEEK, per munt, over de volledige periode.
 *
 * Anders dan het ranking-scherm (dat alleen de laatste 7-daagse waarde per munt toont) groepeert dit
 * scherm de dagelijkse up_pct uit coin_daily_metrics per ISO-week (maandag-zondag) en toont per munt een
 * heatmap: per week een blokje, gekleurd naar kansrijkheid. Zo zie je of een munt over de tijd kansrijker
 * of magerder wordt en waar de dode periodes zitten. Puur read-only aggregatie; geen engine-wijziging.
 *
 *   kansrijk = AVG(up_pct) over de dagen in die ISO-week  (% momenten met >=3% stijging binnen 60 min)
 */
#[Layout('layouts.trading')]
class Weekly extends Component
{
    /**
     * Kandidaat-gate v2 (Epic G) — RESULTAAT-gedreven met hysterese, op rollende weekbasis zodat hij
     * vroeg reageert (niet pas op maand-einde). Signaal = gerealiseerd trade-resultaat; de lat is Daans
     * "minstens ~20% winst per maand, anders is het na slippage geen echte winst".
     *
     *   rollend = Σ profit_loss over de laatste ROLL_WEEKS weken (~1 maand)
     *   UIT  na STOP_CONFIRM weken aaneen met rollend < STOP_FLOOR     (snel, maar niet op één dip → geen geflikker)
     *   AAN  na RESTART_CONFIRM weken aaneen met rollend >= RESTART_FLOOR  (traag + hogere lat → niet op een blip)
     *
     * De band tussen STOP_FLOOR (20) en RESTART_FLOOR (30) is "plakkerig": daarbinnen blijft de status staan.
     * Zo stopt 'ie op tijd én herstart 'ie niet op een losse goede maand (MUMU mei +21 < 30 → blijft uit).
     * De gate start pas bij de eerste week mét trades (anders telt de opstart als 'uit'; FARTCOIN nov-gat).
     * Kansrijk + beweeglijkheid sturen de gate NIET (alleen context). Drempels zijn knoppen voor Fase 2,
     * waar we ze tegen de benchmark (engine/data/regime_benchmark.json) scoren — dag vs week.
     */
    private const GATE_ROLL_WEEKS     = 4;    // rollend venster (~1 maand)
    private const GATE_STOP_FLOOR     = 20.0; // onder deze rollende % → kandidaat-uit
    private const GATE_STOP_CONFIRM   = 2;    // zwakke weken aaneen vóór we stoppen
    private const GATE_RESTART_FLOOR  = 30.0; // pas boven deze rollende % overwegen we herstart (hoger = demping)
    private const GATE_RESTART_CONFIRM = 3;   // sterke weken aaneen vóór we herstarten

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
    }

    /** Som van de gerealiseerde trade-winst per (munt, ISO-week): [coinId][yearweek] => Σprofit_loss. */
    private function weeklyTradeResult(): array
    {
        // LET OP: NIET aliassen naar `id` — dat botst met de bestaande kolom coin_fires.id, waardoor
        // GROUP BY op de primaire sleutel valt (één trade per groep i.p.v. de hele week).
        $rows = DB::connection(config('database.default'))
            ->table('coin_fires')
            ->where('is_executed', true)
            ->whereNotNull('profit_loss')
            ->selectRaw('trading_symbol_id, YEARWEEK(datetime, 3) AS yw,
                         SUM(profit_loss) AS pl, COUNT(*) AS n, SUM(profit_loss < 0) AS slecht')
            ->groupBy('trading_symbol_id', 'yw')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[$r->trading_symbol_id][$r->yw] = ['pl' => (float) $r->pl, 'n' => (int) $r->n, 'slecht' => (int) $r->slecht];
        }
        return $map;
    }

    /**
     * Loop chronologisch door de weken van één munt en zet per week regime 'pre'/'on'/'off' + de reden.
     * 'pre' = vóór de eerste trade-week (geen status). Daarna start AAN met hysterese: stoppen na
     * STOP_CONFIRM zwakke weken; herstarten pas na RESTART_CONFIRM weken boven de (hogere) herstart-lat.
     */
    private function applyGate(array &$weeks): void
    {
        $started = false;
        $state = 'on';
        $below = 0;   // weken aaneen onder de stop-lat
        $above = 0;   // weken aaneen boven de herstart-lat
        $hist = [];   // rollend venster van weekresultaten
        foreach ($weeks as &$w) {
            if (! $started) {
                if ($w['week_n'] > 0) {
                    $started = true;
                } else {
                    $w['regime'] = 'pre'; $w['result_roll'] = 0.0; $w['reason'] = 'nog geen trades';
                    continue;
                }
            }
            $hist[] = $w['week_pl'];
            if (count($hist) > self::GATE_ROLL_WEEKS) array_shift($hist);
            $roll = array_sum($hist);
            $w['result_roll'] = $roll;

            if ($state === 'on') {
                $below = $roll < self::GATE_STOP_FLOOR ? $below + 1 : 0;
                $above = 0;
                if ($below >= self::GATE_STOP_CONFIRM) { $state = 'off'; $below = 0; }
            } else {
                $above = $roll >= self::GATE_RESTART_FLOOR ? $above + 1 : 0;
                $below = 0;
                if ($above >= self::GATE_RESTART_CONFIRM) { $state = 'on'; $above = 0; }
            }

            $w['regime'] = $state;
            if ($state === 'on') {
                $w['reason'] = $below > 0
                    ? 'let op: ' . $below . '/' . self::GATE_STOP_CONFIRM . ' zwakke wkn (rollend ' . round($roll) . '%)'
                    : 'aan · rollend ' . round($roll) . '%';
            } else {
                $w['reason'] = $above > 0
                    ? 'herstelt (' . $above . '/' . self::GATE_RESTART_CONFIRM . ' wkn ≥ ' . (int) self::GATE_RESTART_FLOOR . '%)'
                    : 'maandtempo < ' . (int) self::GATE_STOP_FLOOR . '% (rollend ' . round($roll) . '%)';
            }
        }
        unset($w);
    }

    /**
     * Per munt een chronologische reeks weken met de wekelijkse kansrijkheid-maten.
     * Retour: [ ['symbol' => 'NOS', 'id' => 244, 'weeks' => [ ['label','start','up','vol','ticks','days'], ... ],
     *            'avg' => x, 'min' => x, 'max' => x ], ... ] gesorteerd op gem. kansrijkheid (hoog eerst).
     */
    private function perCoin(): array
    {
        $rows = DB::connection(config('database.default'))
            ->table('coin_daily_metrics as m')
            ->leftJoin('coins as c', 'c.id', '=', 'm.trading_symbol_id')
            ->whereNotNull('m.up_pct')
            ->selectRaw('m.trading_symbol_id AS id, c.symbol,
                         YEARWEEK(m.date, 3) AS yw,
                         MIN(m.date)    AS week_date,
                         AVG(m.up_pct)  AS up,
                         AVG(m.vol_pct) AS vol,
                         SUM(m.n_ticks) AS ticks,
                         COUNT(*)       AS days')
            ->groupBy('m.trading_symbol_id', 'c.symbol', 'yw')
            ->orderBy('m.trading_symbol_id')
            ->orderBy('yw')
            ->get();

        $tradeRes = $this->weeklyTradeResult();

        $byCoin = [];
        foreach ($rows as $r) {
            $start = \Carbon\Carbon::parse($r->week_date)->startOfWeek(); // ISO-maandag van die week
            $tr = $tradeRes[$r->id][$r->yw] ?? ['pl' => 0.0, 'n' => 0, 'slecht' => 0];
            $byCoin[$r->id]['symbol'] ??= $r->symbol;
            $byCoin[$r->id]['id'] = $r->id;
            $byCoin[$r->id]['weeks'][] = [
                'label'    => $start->isoFormat('GGGG[-W]WW'),
                'start'    => $start->format('d-m-Y'),
                'startShort' => $start->locale('nl')->isoFormat('D MMM'),   // bv. "6 mei" (flip-label)
                'up'       => (float) $r->up,
                'vol'      => $r->vol !== null ? (float) $r->vol : null,
                'ticks'    => (int) $r->ticks,
                'days'     => (int) $r->days,
                'week_pl'  => $tr['pl'],          // Σwinst van de trades díé week
                'week_n'   => $tr['n'],           // aantal trades die week
                'week_bad' => $tr['slecht'],      // verliezers die week
                'mon'      => $start->month,                            // voor de maand/jaar-as eronder
                'year'     => $start->year,
                'mshort'   => $start->locale('nl')->isoFormat('MMM'),    // bv. "feb."
                'mfull'    => $start->locale('nl')->isoFormat('MMMM'),   // bv. "februari" (tooltip)
            ];
        }

        $coins = [];
        foreach ($byCoin as $c) {
            $this->applyGate($c['weeks']);   // zet per week regime 'on'/'off' + reden + rollend resultaat
            $ups = array_column($c['weeks'], 'up');
            $offWeeks = array_filter($c['weeks'], fn ($w) => $w['regime'] === 'off');
            $coins[] = [
                'symbol'   => $c['symbol'] ?? (string) $c['id'],
                'id'       => $c['id'],
                'weeks'    => $c['weeks'],
                'avg'      => array_sum($ups) / count($ups),
                'min'      => min($ups),
                'max'      => max($ups),
                // samenvatting van de gate: hoeveel zou-uit-weken en wat zat daarin
                'off_weeks'    => count($offWeeks),
                'off_bad'      => (int) array_sum(array_column($offWeeks, 'week_bad')),
                'off_pl'       => (float) array_sum(array_column($offWeeks, 'week_pl')),
            ];
        }
        usort($coins, fn ($a, $b) => $b['avg'] <=> $a['avg']);

        return $coins;
    }

    public function render()
    {
        $coins = $this->perCoin();
        // Globale schaal-bovengrenzen zodat de kleuren tussen munten vergelijkbaar zijn.
        $scaleMax = 0.01;     // kansrijkheid
        $scaleMaxVol = 0.01;  // beweeglijkheid
        foreach ($coins as $c) {
            $scaleMax = max($scaleMax, $c['max']);
            foreach ($c['weeks'] as $w) {
                if ($w['vol'] !== null) {
                    $scaleMaxVol = max($scaleMaxVol, $w['vol']);
                }
            }
        }

        return view('livewire.coins.weekly', [
            'coins' => $coins,
            'scaleMax' => $scaleMax,
            'scaleMaxVol' => $scaleMaxVol,
            'gate' => [
                'roll' => self::GATE_ROLL_WEEKS,
                'stopFloor' => self::GATE_STOP_FLOOR,
                'stopConfirm' => self::GATE_STOP_CONFIRM,
                'restartFloor' => self::GATE_RESTART_FLOOR,
                'restartConfirm' => self::GATE_RESTART_CONFIRM,
            ],
        ]);
    }
}
