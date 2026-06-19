# EPIC V: Coin-beweeglijkheid — thermometer + rotatie-fundament

> **GEBOUWD 2026-06-17.** Alle 4 features af + getest. Op de eigenaarsvraag "op welke indicator sorteer
> je 10 coins op kansrijk — volume of prijs?" is een correlatie-test beslissend gebleken: de KANSRIJK-score
> is de **upside-kans** (`up_pct` = % momenten met ≥3% stijging binnen 60 min), die cross-coin het sterkst
> met winst/trade correleert (DOGEAI **0,94** / NOS **0,50**) — sterker dan std-log-returns (0,71 / 0,31)
> en veel sterker dan volume (0,71 / **−0,04**). Volume is dus een **liquiditeit-veld**, geen sorteersleutel.
> Daarom wijkt de implementatie bewust af van de tekst hieronder:
> - tabel heet **`coin_daily_metrics`** (niet `_volatility`): `up_pct` (kansrijk), `vol_pct` (beweeglijkheid),
>   `n_ticks` (liquiditeit), `up_7d`/`vol_7d` (7-daags); `up_7d` is de sorteersleutel.
> - module **`engine/src/coin_metrics.py`**, routine-set **`coin-metrics`** (niet gegated).
> - UI = een **kansrijkheid-ranking-blok** bovenaan de Trades-Samenvatting (munten gesorteerd op `up_7d`,
>   met balk), i.p.v. een per-maand-kolom. Schaalt 1-op-1 naar 10+ coins.
> - Tests: `engine/src/test_coin_metrics.py` (5, plat assert) + `www/tests/Feature/TradesCoinRankingTest.php` (2, Pest).
> De rest van dit doc (de std-log-returns "vlag") is de superseded ontwerp-historie; bewaard voor context.

> **Herzien 2026-06-17.** De eerste versie ("vlag wanneer 7d/60d-volatiliteit < 0,70") is nagerekend
> op de echte data en bleek **de verkeerde maanden te markeren**: DOGEAI maart 2025 (+85%, topmaand)
> krijgt ten onrechte een vlag (ratio 0,62), en mei 2025 (de dode instortmaand, +0,6%) krijgt **géén**
> vlag (ratio 0,99). Reden: een 7d/60d-ratio meet het *moment* van afkoeling (de scherpe val
> feb→maart), niet het *aanhoudend lage niveau* (mei) — tegen mei is het 60d-gemiddelde al meegezakt.
> Bredere test (zie onder): **geen** volatiliteits-drempel scheidt de dode van de goede maanden schoon
> op beide coins. Daarom is het doel verlegd: niet "voorspel de slechte maand per coin" (lukt niet),
> maar **"meet de beweeglijkheid eerlijk per coin en leg het fundament om straks tussen veel coins de
> beweeglijkste te kiezen"** (rotatie). Dat is wat de eigenaar wil: volatile trades, en kunnen
> switchen tussen (straks) ~1000 coins. De huidige 2 coins zijn startdata.

## Epic Specification

Per dag per coin de **beweeglijkheid** van de prijs meten (`vol_pct` = standaarddeviatie van 1-min
log-returns × 100), opslaan in een nieuwe tabel `coin_daily_volatility`, en in de Trades-Samenvatting
per (maand × coin) de beweeglijkheid tonen als een **thermometer** (kleurschaal hoog→laag) met de
**richting** t.o.v. de eigen recente piek. Geen harde "slecht"-vlag met vaste drempel — die werkt
aantoonbaar niet. Read-only: er verandert niets aan rules of trades. Een dagelijkse routine vult de
tabel. De opgeslagen maten zijn **absoluut en cross-coin vergelijkbaar**, zodat een vervolgepic ze
kan rangschikken voor coin-rotatie zodra er meerdere coins gelijktijdig leven.

## Rationale

We zoeken **volatile trades**. Een proef op DOGEAI (feb–jul 2025) en NOS (nov 2023–jan 2025) bevestigt:

1. **Beweeglijkheid is een echte, vergelijkbare coin-eigenschap.** DOGEAI's `vol_pct` ligt structureel
   ~2× boven NOS (DOGEAI maand-mediaan ~0,42–0,89; NOS ~0,18–0,52). Voor "welke coin is nu het meest
   beweeglijk" is dit precies de juiste maat — en hij is goedkoop te berekenen.
2. **Maar de winst-omslag binnen één coin is er niet betrouwbaar uit te voorspellen.** De
   beweeglijkheid daalt al vanaf februari (launch-hype), terwijl de winst pas in mei instort — het
   signaal loopt **synchroon met de afkoeling, niet vooruit op de winst**. En op NOS hebben dode én
   goede maanden dezelfde lage beweeglijkheid door elkaar (dec '24: laagste vol 0,18, tóch +14%). Geen
   enkele geteste drempel (absoluut, 7d/60d-gemiddelde, 7d/60d-piek) scheidt dood van goed op beide coins.
3. **AutoARIMA op de prijs zelf is nutteloos** (skill ≈ 0 — prijs is bijna random walk). De std van
   log-returns is wél een betekenisvol, stabiel signaal.

Conclusie: we bouwen een **eerlijke thermometer** (meet + toon de beweeglijkheid, geen vals oordeel) die
tegelijk het **fundament voor rotatie** legt (absolute, cross-coin vergelijkbare maten in een tabel +
dagelijkse routine). De stap "kies/pauzeer coins op basis van rang" volgt zodra er meer coins zijn.

### Proef-onderbouwing (al gedraaid)

| | DOGEAI Σwinst | vol_pct (mediaan) | 7d/60d-gem [v1-vlag] | 7d/60d-piek |
|---|---|---|---|---|
| feb '25 | +388 | 0,89 | — | — |
| mrt '25 | +85 (top) | 0,52 | **0,62 → vlag (FOUT)** | 0,28 |
| apr '25 | +50 | 0,55 | 0,82 | 0,32 |
| mei '25 | **+3 (DOOD)** | 0,42 | **0,99 → géén vlag (MIST)** | 0,42 |
| jun '25 | +22 | 0,44 | 1,15 | 0,15 |
| jul '25 | +0 (dood) | 0,52 | 0,39 → vlag | 0,01 |

De v1-vlag (7d/60d-gem < 0,70) markeert maart (top) en mist mei (dood). Geen kolom scheidt dood/goed
schoon. Reproduceerbaar met `engine/src/coin_activity*.py` + de narekening in de finding-doc.

## Dependencies

- Epic 01 (data-foundation): `indicators.price` beschikbaar.
- Routine-framework (`engine/src/routines.py`) — nieuwe set toevoegen.
- Trades-Samenvatting (`www/app/Livewire/Trades/Index.php` tab `summary`) + blade.
- `coins` tabel — bron voor de coin-lijst.

## Bestaande Code (referentie)

### Proef (read-only, al gedraaid, untracked):
- `engine/src/forecast_skill.py` — AutoARIMA-skill ≈ 0; `vol_pct` is de bruikbare kolom.
- `engine/src/coin_activity.py` + `coin_activity_daily.py` + `coin_stop_backtest.py` + `coin_stoplicht.py`
  — per-maand/dag verkenningen. Finding-doc: `docs/findings/coin-volatiliteit-stoplicht-2026-06-17.md`.

### Bestaande tabellen die we lezen:
```sql
indicators (trading_symbol_id, indicator='volumeud', datetime, price)
coins      (trading_symbol_id, symbol)
coin_fires (trading_symbol_id, symbol, datetime, profit_loss, is_executed)
```

### Brain DB-verbinding (`engine/src/db.py:8`):
```python
def brain(dict_cursor=True):
    return pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                           database="brain", ...)
```

## Beslissingen

| # | Vraag | Beslissing |
|---|---|---|
| 1 | Hoe wordt beweeglijkheid berekend? | `vol_pct` = std van log-returns op 1-min resampled prijzen (`np.std(np.diff(np.log(prices)))`) × 100. Bron: `indicators.price` waar `indicator='volumeud'`. |
| 2 | Welke resample? | 1-min mediaan over alle ticks in die minuut, forward-fill max 5 min gaten. |
| 3 | Is er een harde "slecht"-drempel? | **Nee.** Bewust geschrapt — narekening toont dat geen vaste drempel dood van goed scheidt op beide coins. We tonen de beweeglijkheid als kleurschaal + richting, geen oordeel. |
| 4 | Welke maten opslaan? | Absoluut: `vol_pct` (dag), `vol_7d`, `vol_60d` (voortschrijdend gem). Relatief: `pct_of_peak` = `vol_7d` / (60d trailing **max** van `vol_pct`) — "hoeveel % van de eigen recente piek", als richting-hint (geen vlag). |
| 5 | Per coin of per rule? | Per coin. Beweeglijkheid is een coin-eigenschap. |
| 6 | Cross-coin vergelijkbaar? | Ja — `vol_pct`/`vol_7d` zijn absolute %-maten, direct tussen coins te rangschikken. Dat is het rotatie-fundament. De rangschikking zelf is een vervolgepic (komt zodra er meerdere coins gelijktijdig zijn). |
| 7 | Automatische actie? | **Nee.** Alleen meten + tonen. Observeren in de praktijk voordat er ooit gating aan gekoppeld wordt. |
| 8 | Tabel-schema? | `coin_daily_volatility (trading_symbol_id, date, vol_pct, vol_7d, vol_60d, pct_of_peak, n_ticks)`, uniek op `(trading_symbol_id, date)`. |
| 9 | Wanneer draait de routine? | Dagelijks, eigen set `coin-volatility`. Niet gegated — moet ook draaien zonder nieuwe trades. |
| 10 | Backfill? | Eerste run vult alle ontbrekende dagen sinds eerste prijsdata; daarna alleen nieuwe dagen. Idempotent, `--force` overschrijft. |
| 11 | Wat tonen in de UI? | Per (maand × coin) de **mediaan `vol_7d`** als getal + kleurschaal (donker=beweeglijk, licht=rustig). Plus een klein pijltje voor `pct_of_peak` (↓ als < 0,5 = "koelt af t.o.v. eigen piek", neutraal anders). Geen oranje "slecht"-rij. |
| 12 | Dagen zonder/te weinig data? | <120 1-min punten op een dag → geen rij. Maand met <5 dagen data → "—" in de UI. |
| 13 | Periode per dag? | Alle 1-min punten van die kalenderdag (00:00–23:59). |
| 14 | Min dagen voor 7d/60d? | 7d ≥5 dagen, 60d ≥20 dagen (max), anders die kolom NULL. |

## Features (4)

### 1. Brain-tabel `coin_daily_volatility`

**Status:** Approved

```php
// www/database/migrations/2026_06_19_010000_create_coin_daily_volatility_table.php
return new class extends Migration {
    public function up(): void {
        Schema::create('coin_daily_volatility', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('trading_symbol_id');
            $t->date('date');
            $t->decimal('vol_pct', 8, 5)->nullable();      // std log-returns × 100, die dag (absoluut)
            $t->decimal('vol_7d', 8, 5)->nullable();       // 7-daags voortschrijdend gem (de rotatie-maat)
            $t->decimal('vol_60d', 8, 5)->nullable();      // 60-daags voortschrijdend gem (context)
            $t->decimal('pct_of_peak', 6, 4)->nullable();  // vol_7d / 60d-trailing-max, richting-hint
            $t->unsignedInteger('n_ticks');
            $t->timestamps();
            $t->unique(['trading_symbol_id', 'date'], 'cdv_coin_date');
            $t->index(['trading_symbol_id', 'date'], 'cdv_lookup');
        });
    }
    public function down(): void { Schema::dropIfExists('coin_daily_volatility'); }
};
```

### Acceptance Criteria
- [ ] Migratie draait via `/Applications/MAMP/bin/php/php8.4.17/bin/php artisan migrate` en is reversibel.
- [ ] `SHOW CREATE TABLE coin_daily_volatility` toont kolommen `trading_symbol_id, date, vol_pct, vol_7d, vol_60d, pct_of_peak, n_ticks, created_at, updated_at` + unique `cdv_coin_date`.
- [ ] Tabel is leeg na migratie.

---

### 2. Python-module `coin_volatility.py` — berekening + opslag

**Status:** Approved

```python
# engine/src/coin_volatility.py
"""
Per (coin, datum) de beweeglijkheid vol% berekenen en opslaan.

vol_pct      = std(log-returns van 1-min mediaan-prijzen) * 100   (die dag, absoluut)
vol_7d       = 7-daags voortschrijdend gemiddelde van vol_pct     (de cross-coin rotatie-maat)
vol_60d      = 60-daags voortschrijdend gemiddelde van vol_pct    (context)
pct_of_peak  = vol_7d / 60d-trailing-MAX(vol_pct)                 (richting-hint, GEEN harde vlag)

Read uit indicators (volumeud + price). Schrijft naar coin_daily_volatility (brain).
Idempotent: bestaande dagen worden niet overschreven tenzij force=True. Nooit naar bot_signals.
"""
import sys
from datetime import timedelta
import numpy as np
import pandas as pd
from db import brain

MIN_TICKS_PER_DAY = 120
WIN_7D, WIN_60D = 7, 60
MIN_PERIODS_7D, MIN_PERIODS_60D = 5, 20
COOLING = 0.50   # pct_of_peak < 0.50 = "koelt af t.o.v. eigen piek" (richting-pijl, geen oordeel)


def _day_vol(prices):
    if len(prices) < MIN_TICKS_PER_DAY:
        return None
    logret = np.diff(np.log(np.asarray(prices, float) + 1e-12))
    return float(np.std(logret) * 100)


def _build_for_coin(conn, sym, from_date, to_date):
    with conn.cursor() as c:
        c.execute("SELECT datetime, price FROM indicators "
                  "WHERE trading_symbol_id=%s AND indicator='volumeud' AND price IS NOT NULL "
                  "  AND datetime >= %s AND datetime < %s ORDER BY datetime",
                  (sym, from_date, to_date + timedelta(days=1)))
        rows = c.fetchall()
    if not rows:
        return []
    df = pd.DataFrame(rows)
    df["price"] = df["price"].astype(float)
    df = df.set_index("datetime").resample("1min")["price"].median().ffill(limit=5).dropna().to_frame("price")
    df["date"] = df.index.date
    per_day = []
    for d, grp in df.groupby("date"):
        v = _day_vol(grp["price"].values)
        if v is not None:
            per_day.append({"date": d, "vol_pct": v, "n_ticks": len(grp)})
    if not per_day:
        return []
    out = pd.DataFrame(per_day).sort_values("date").reset_index(drop=True)
    out["vol_7d"] = out["vol_pct"].rolling(WIN_7D, min_periods=MIN_PERIODS_7D).mean()
    out["vol_60d"] = out["vol_pct"].rolling(WIN_60D, min_periods=MIN_PERIODS_60D).mean()
    peak = out["vol_pct"].rolling(WIN_60D, min_periods=MIN_PERIODS_60D).max()
    out["pct_of_peak"] = out["vol_7d"] / peak
    return out.to_dict(orient="records")


def _existing_dates(conn, sym):
    with conn.cursor() as c:
        c.execute("SELECT date FROM coin_daily_volatility WHERE trading_symbol_id=%s", (sym,))
        return {r["date"] for r in c.fetchall()}


def _write(conn, sym, rows, force=False):
    if not rows:
        return 0
    skip = set() if force else _existing_dates(conn, sym)
    new_rows = [r for r in rows if r["date"] not in skip]
    if not new_rows:
        return 0
    with conn.cursor() as c:
        for r in new_rows:
            c.execute(
                "INSERT INTO coin_daily_volatility "
                "(trading_symbol_id, date, vol_pct, vol_7d, vol_60d, pct_of_peak, n_ticks, created_at, updated_at) "
                "VALUES (%s,%s,%s,%s,%s,%s,%s,NOW(),NOW()) "
                "ON DUPLICATE KEY UPDATE vol_pct=VALUES(vol_pct), vol_7d=VALUES(vol_7d), "
                "  vol_60d=VALUES(vol_60d), pct_of_peak=VALUES(pct_of_peak), n_ticks=VALUES(n_ticks), updated_at=NOW()",
                (sym, r["date"], r["vol_pct"], _nan(r.get("vol_7d")), _nan(r.get("vol_60d")),
                 _nan(r.get("pct_of_peak")), r["n_ticks"]))
    conn.commit()
    return len(new_rows)


def _nan(x):
    return None if x is None or (isinstance(x, float) and np.isnan(x)) else x


def run(force=False, verbose=True):
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT trading_symbol_id sym, MIN(DATE(datetime)) frm, MAX(DATE(datetime)) too "
                  "FROM indicators WHERE indicator='volumeud' AND price IS NOT NULL GROUP BY trading_symbol_id")
        ranges = c.fetchall()
    total = 0
    cooling = []
    for r in ranges:
        sym, frm, too = r["sym"], r["frm"], r["too"]
        rows = _build_for_coin(conn, sym, frm, too)
        total += _write(conn, sym, rows, force=force)
        if rows:
            last = rows[-1]
            pp = _nan(last.get("pct_of_peak"))
            if pp is not None and pp < COOLING:
                cooling.append({"sym": sym, "date": str(last["date"]), "pct_of_peak": round(pp, 3),
                                "vol_7d": round(_nan(last.get("vol_7d")) or 0, 3)})
        if verbose:
            print(f"coin {sym}: vol_7d={rows[-1].get('vol_7d') if rows else None}")
    conn.close()
    return {"days_added": total, "coins_cooling": len(cooling), "cooling": cooling}


if __name__ == "__main__":
    print(run(force="--force" in sys.argv, verbose=True))
```

### Acceptance Criteria
- [ ] `engine/src/coin_volatility.py` bestaat met `_day_vol`, `_build_for_coin`, `_write`, `run`.
- [ ] `cd engine/src && ../.venv/bin/python coin_volatility.py` schrijft rijen voor DOGEAI (2525) én NOS (244).
- [ ] Tweede run zonder `--force`: `days_added == 0` (idempotent). Met `--force`: rijen bijgewerkt.
- [ ] Dag met <120 1-min punten → geen rij.
- [ ] Voor 2525 op `2025-04-15` geeft `vol_pct` een waarde rond 0,5 (±0,3).
- [ ] `vol_pct` voor DOGEAI ligt structureel hoger dan voor NOS (mediaan over alle dagen: 2525 > 244) — de cross-coin maat is plausibel.
- [ ] `vol_7d` NULL voor de eerste 4 dagen per coin; `vol_60d`/`pct_of_peak` NULL tot ≥20 dagen.
- [ ] `grep bot_signals coin_volatility.py` → leeg (alleen `db.brain()`).

---

### 3. Routine `coin-volatility` in `engine/src/routines.py`

**Status:** Approved

```python
# 1. Routine-functie (na de bestaande routines):
def routine_coin_volatility(j):
    """Werk coin_daily_volatility bij; meld coins die afkoelen t.o.v. hun eigen recente piek
    (richting-hint, GEEN trade-actie)."""
    import coin_volatility
    res = coin_volatility.run(verbose=False)
    j.add(f"Coin-beweeglijkheid bijgewerkt: {res['days_added']} dagen geschreven, "
          f"{res['coins_cooling']} coin(s) koelen af (vol_7d < 50% van eigen piek).",
          level="result", data=res)
    for c in res["cooling"]:
        j.add(f"  coin {c['sym']}: vol_7d={c['vol_7d']} = {int(c['pct_of_peak']*100)}% van eigen piek "
              f"op {c['date']} — minder beweeglijk dan recent.", level="finding")
    return f"coin-vol · {res['days_added']} dagen · {res['coins_cooling']} koelen af"

# 2. Set-constanten:
VOL_SET_KEY = "coin-volatility"
VOL_SET_NAME = "Coin-beweeglijkheid — dagelijkse meting"
REGISTRY_VOL = [("coin-volatility", routine_coin_volatility)]

# 3. In SETS (laatste boolean = niet gegated):
SETS = {
    ...,
    VOL_SET_KEY: (VOL_SET_NAME, REGISTRY_VOL, False),
}
```

### Acceptance Criteria
- [ ] `routines.py` bevat `routine_coin_volatility(j)` + `VOL_SET_KEY="coin-volatility"` + `VOL_SET_NAME`.
- [ ] `SETS` heeft `VOL_SET_KEY: (VOL_SET_NAME, REGISTRY_VOL, False)`.
- [ ] `cd engine/src && ../.venv/bin/python routines.py --set coin-volatility` draait en schrijft een `routine_runs`-rij met `set_key='coin-volatility'`.
- [ ] `routine_run_log` heeft ≥1 rij level=`result` met "Coin-beweeglijkheid bijgewerkt".
- [ ] Coins met `pct_of_peak < 0.50` op de laatste dag krijgen een `finding`-rij.
- [ ] Tweede run direct erna produceert weer een journal-entry (niet gegated).

---

### 4. UI-thermometer in Trades Samenvatting

**Status:** Approved

Per (maand × coin) een kolom **"Beweeglijkheid"**: de mediaan `vol_7d` van die maand als getal, met
een **kleurschaal** (donker = beweeglijk, licht = rustig — relatief binnen de getoonde set, zodat het
ook bij meer coins werkt) en een klein pijltje ↓ als de maand-mediaan `pct_of_peak < 0,50` ("koelt af
t.o.v. eigen recente piek"). **Geen** oranje "slecht"-rij en **geen** oordeel-tekst — het is een
thermometer, geen voorspelling.

```php
// summaryRows(): voeg per rij de beweeglijkheid toe (aparte query, in PHP mergen).
// ... bestaande coin_fires-aggregatie ongewijzigd ...
$volByKey = $this->volByMonth();
foreach ($rows as &$r) {
    $key = $r['ym'].'|'.$r['trading_symbol_id'];
    $r['vol_7d_med']     = $volByKey[$key]['vol_7d']     ?? null;
    $r['pct_of_peak_med'] = $volByKey[$key]['pct_of_peak'] ?? null;
    $r['cooling'] = $r['pct_of_peak_med'] !== null && $r['pct_of_peak_med'] < 0.50;
}
return $rows;

/** Mediaan vol_7d en pct_of_peak per (ym, sym) — alleen maanden met ≥5 dagen data. */
private function volByMonth(): array
{
    $rows = DB::connection(config('database.default'))
        ->table('coin_daily_volatility')
        ->selectRaw("DATE_FORMAT(date,'%Y-%m') AS ym, trading_symbol_id, vol_7d, pct_of_peak")
        ->whereNotNull('vol_7d')->get();
    $acc = [];
    foreach ($rows as $r) {
        $acc[$r->ym.'|'.$r->trading_symbol_id]['v'][] = (float) $r->vol_7d;
        if ($r->pct_of_peak !== null) $acc[$r->ym.'|'.$r->trading_symbol_id]['p'][] = (float) $r->pct_of_peak;
    }
    $median = function (array $a) { sort($a); $n = count($a); return $n % 2 ? $a[intdiv($n,2)] : ($a[$n/2-1]+$a[$n/2])/2; };
    $out = [];
    foreach ($acc as $key => $g) {
        if (count($g['v'] ?? []) < 5) continue;
        $out[$key] = ['vol_7d' => $median($g['v']),
                      'pct_of_peak' => isset($g['p']) && $g['p'] ? $median($g['p']) : null];
    }
    return $out;
}
```

```blade
{{-- kolomkop, vlak voor "Σ winst" --}}
<th class="px-3 py-2 text-right">Beweeglijkheid</th>

{{-- in elke rij --}}
<td class="px-3 py-2 text-right">
    @if ($row['vol_7d_med'] !== null)
        <span title="Mediaan 7-daagse beweeglijkheid deze maand (std van 1-min koersbewegingen). Hoger = beweeglijker.@if ($row['cooling']) Koelt af t.o.v. eigen recente piek.@endif">
            {{ number_format($row['vol_7d_med'], 2) }}
            @if ($row['cooling']) <span class="text-gray-500" title="vol_7d onder 50% van eigen 60-daagse piek">↓</span> @endif
        </span>
    @else
        <span class="text-gray-400">—</span>
    @endif
</td>
```

### Acceptance Criteria
- [ ] `summaryRows()` retourneert per rij `vol_7d_med` (float|null), `pct_of_peak_med` (float|null), `cooling` (bool).
- [ ] `volByMonth()` retourneert per key `"YYYY-MM|<sym>"` een array met `vol_7d` en `pct_of_peak`; maanden met <5 dagen data ontbreken.
- [ ] In de Blade toont een maand met data het `vol_7d`-getal (bijv. "0,52"); zonder data "—".
- [ ] DOGEAI toont over feb→jul een **dalende** beweeglijkheid (feb hoogste, mei/jun laagste) — de afkoeling is zichtbaar in de cijfers, zonder dat één maand als "slecht" wordt bestempeld.
- [ ] DOGEAI's beweeglijkheid is zichtbaar hoger dan NOS' (cross-coin vergelijkbaar).
- [ ] Géén `bg-orange-50` "slecht"-rij meer; het ↓-pijltje verschijnt alleen bij `cooling === true`.
- [ ] Trades-Lijst tab ongewijzigd.

## Aanbevolen Implementatie Volgorde

1. Feature 1 — migratie + tabel.
2. Feature 2 — `coin_volatility.py` + handmatig draaien om te vullen.
3. Feature 3 — routine registreren + 1× draaien.
4. Feature 4 — Livewire + blade, browser-check.

## Nieuwe bestanden

| Bestand | Type | Feature |
|---|---|---|
| `www/database/migrations/2026_06_19_010000_create_coin_daily_volatility_table.php` | Migratie | 1 |
| `engine/src/coin_volatility.py` | Python-module | 2 |

## Te wijzigen bestanden

| Bestand | Wat | Feature |
|---|---|---|
| `engine/src/routines.py` | Routine-functie + SET-constanten + SETS-entry | 3 |
| `www/app/Livewire/Trades/Index.php` | `summaryRows()` + nieuwe `volByMonth()` | 4 |
| `www/resources/views/livewire/trades/index.blade.php` | Kolom "Beweeglijkheid" + ↓-pijl | 4 |

## Tests

| Bestand | Type | Dekt |
|---|---|---|
| `engine/tests/test_coin_volatility.py` | pytest | `_day_vol`: constante prijs → ≈0; ruis → >0; <120 punten → None. `_build_for_coin`: rolling 7d/60d/pct_of_peak gevuld vanaf de juiste dag. |
| `www/tests/Feature/TradesSummaryVolatilityTest.php` | Pest | Samenvatting toont `vol_7d`-getal per (maand,coin); "—" bij <5 dagen; ↓-pijl alleen bij `pct_of_peak < 0,50`; geen `bg-orange-50`. |

```bash
cd /Users/daanvantongeren/Documents/Sites/brain/engine && .venv/bin/python -m pytest tests/test_coin_volatility.py -v
/Applications/MAMP/bin/php/php8.4.17/bin/php artisan test tests/Feature/TradesSummaryVolatilityTest.php
```

## In scope (nu) vs vervolg

**Nu (deze epic):** meten + opslaan + tonen van de absolute beweeglijkheid per coin, plus een
richting-hint. Read-only, geen oordeel, geen actie. Werkt met de huidige 2 coins en schaalt 1-op-1 naar
meer coins (de routine pakt elke coin in `indicators` automatisch mee).

**Vervolgepic (zodra er meerdere coins gelijktijdig leven):** cross-coin **rotatie** — rangschik de
levende coins op `vol_7d`, toon een "kies/pauzeer"-advies (handel de top-N beweeglijkste), met demping
(een coin wisselt pas van advies na een aantal dagen, om flikkeren te voorkomen — zie finding-doc).

## Niet in scope

- **Harde "slecht"-vlag met vaste drempel** (de v1-7d/60d<0,70-aanpak) — aantoonbaar onbetrouwbaar,
  vervangen door de neutrale thermometer.
- **Automatische trading-stop / rule-gating** op basis van beweeglijkheid. Eerst observeren.
- **Cross-coin rotatie-rangschikking** — vervolgepic (niet bewijsbaar met 2 niet-overlappende coins, wel
  voorbereid: de maten staan klaar).
- **Andere indicators** dan `volumeud.price`; **foundation-modellen** (Chronos/TimesFM); **HMM /
  Choppiness Index** (Epic 05) — eventueel later.
- **Upside-asymmetrische maat** (% momenten met ≥3% stijging binnen 1u uit `coin_activity.py`) — die
  correleerde iets sterker met winst dan symmetrische std, maar is duurder; kandidaat voor een latere
  verfijning, niet nu.
```
