# EPIC H: Regime operationaliseren — actieve periode als bron-van-waarheid

> **Status:** Plan — BOUW-KLAAR gemaakt 2026-06-27 (zie **Build-ready details** hieronder: exacte file:regels,
> gate-algoritme, cache-versie-injectie + verplichte tests, geverifieerd tegen de huidige code). **Start hier**
> (Daans keuze: H vóór J vóór munten inladen — zie [[../findings/onboarding-batch-en-bouwvolgorde-2026-06-27.md]]).
> Bouwt op [epic-G](epic-G-coin-regime-gate.md) (de gevalideerde aan/uit-gate) + de benchmark daar.
> Skill: [[brain-regime-gate]]. Doc: [[docs/regime-gate.md]].

## Epic Specification

Maak de in epic-G gevalideerde regime-gate tot de **automatische bron-van-waarheid** voor wanneer elke munt
actief (traden) of inactief (pauze) is, en laat de **rest van het systeem daar standaard rekening mee houden**.
Concreet: (1) een script/routine berekent zelf — leak-vrij — de aan/uit-perioden per munt en vervangt zo Daans
handmatige oordeel; (2) die perioden worden per munt opgeslagen (tabel `coin_regime` + JSON-export); (3) overal waar
trades meetellen — de koop/verkoop-rule-optimalisatie én de schermen — geldt **standaard** dat trades uit een
inactieve periode **niet meetellen**. Het "weten wanneer te stoppen" is een van de successen van de bot; dat moet
zichtbaar en doorgerekend worden, niet weggemiddeld door verlies-trades uit periodes die we nooit gehandeld zouden
hebben.

## Rationale

Nu telt élke trade mee in de cijfers en in de rule-optimalisatie — ook de honderden verliezers uit de doodlopende
staarten (MUMU jun–nov '25, FARTCOIN apr–sep '25: samen 1.769 trades, 1.226 verliezers, −212% Σ). Die periodes
hadden we niet gehandeld. Ze meetellen (a) vertekent de prestatie naar beneden en (b) laat de rule-tuner leren van
trades die in de praktijk nooit hadden plaatsgevonden. Door de actieve periode als filter te hanteren, meten en
optimaliseren we op de trades die we **echt** zouden hebben gemaakt — en wordt het stoppen-op-tijd zichtbaar als
winst i.p.v. verstopt.

## Dependencies

- [epic-G](epic-G-coin-regime-gate.md): de gate-logica (`www/app/Livewire/Coins/Weekly.php`, gate v2) +
  validatie (`engine/src/regime_validate.py`, alle toetsen doorstaan) + cadans-keuze (wekelijks). **Klaar.**
- `coin_daily_metrics` (kansrijk/beweeglijk) + `coin_moment_sells` (schaduw-trades) — voor het leak-vrije
  herstart-signaal op inactieve munten. **Aanwezig.**
- `coin_fires` (trades) — het filter-doel. **Aanwezig.**

## Bestaande Code (referentie)

> **Bijgewerkt 2026-06-26 na de engine-snelheidswijzigingen** (commits `b0bfa3e`/`4f3370f`/`9c4c7bc`/`39182ac`):
> regelnummers verschoven en er is een **cache-/fingerprint-laag** bijgekomen die het filter raakt — zie de
> nieuwe beslissing #8 hieronder. Dit is de belangrijkste correctie t.o.v. de oorspronkelijke epic.

**Trade-loaders die het filter moeten erven (engine):**
- `engine/src/opt_lib.py:97` `load_trades()` — centrale loader voor de buy/sell-optimalisatie. `SELECT ... FROM
  coin_fires WHERE is_executed=1 AND profit_loss IS NOT NULL`. Pure DB-read (consumptie-laag); voedt ook
  `load_long()`. Hier het filter toevoegen propageert naar ~20 modules (`daily_optimization`, `auto_apply`,
  `rq1_tighten`, `rq2_*`, `sell_tuning`, `feature_quality`, …).
- `engine/src/opt_lib.py:494` `load_all_fires()` — idem (gebruikt door `split_2b*`). *(Was :379; verschoven door
  de snelheidswijziging.)*
- Eigen loaders die apart langs moeten: `sell_tuning.py:85`, `subrule_power.py:52`, `gate_window.py:56` *(alle drie
  ongewijzigd).*

**Nieuwe cache-/fingerprint-laag (door de snelheidswijziging) — het filter MOET hierin doorwerken:**
- `engine/src/opt_lib.py:198` `load_long_cached()` + `:159` `_long_fingerprint(sym)` — per-munt long-parquet-cache.
  De fingerprint hasht nú de **volledige** executed-trade-set (count/max-datetime/cls-checksum). Filtert
  `load_trades()` straks default op actieve periode, dan blijft de fingerprint gelijk bij een regime-wijziging →
  **de cache serveert een verouderde actieve-set.** De **regime-versie** moet in `_long_fingerprint`.
- `engine/src/routines.py:49` `input_fingerprint()` — de "data-veranderd"-gate die beslist of de optimalisatie-keten
  überhaupt draait. Verandert alléén het regime (niet de trades/rules), dan verandert deze fingerprint niet → de
  keten **slaat over** → het nieuwe actieve-venster wordt nooit doorgerekend. De **regime-versie** moet hierin mee.
- `engine/src/fires_cache.py` (`cached_all_fires`/`cached_fires_per_rule`) — dit is de **re-fire**-laag (berekent +
  schrijft welke trades vuren), **upstream** van het filter. Re-fire blijft ALLE trades berekenen/opslaan (de gate
  heeft de volledige historie nodig; `/coins/weekly` toont 'm). Het filter is puur consumptie → **NIET** in
  `fires_cache` inbouwen. (Expliciet vermeld zodat een latere bouwer dit niet per ongeluk koppelt.)

**Schermen die trades tonen (PHP):**

**Schermen die trades tonen (PHP):**
- `www/app/Livewire/Trades/Index.php` — Samenvatting (per-maand-per-coin Σ + goed/middel/slecht) + lijst.
  `baseQuery()`, `summaryRows()`, `groupedPromising()` queryen `coin_fires`/`coin_moment_sells`.
- `www/app/Livewire/Trades/CoinExplorer.php` — dag-navigator met fires per dag.

## Beslissingen

| # | Vraag | Beslissing |
|---|---|---|
| 1 | Gate vs handmatige benchmark | **Gate = bron-van-waarheid** (live/automatisch). De benchmark (`regime_benchmark.json`) blijft het **ijkpunt** waartegen `regime_validate.py` de gate blijft scoren naarmate er munten/data bijkomen. |
| 2 | Opslagvorm | Canoniek = **tabel `coin_regime`** (per munt de intervallen + signaalwaarden + reden + `computed_at`); de routine schrijft 'm. Plus **JSON-export** `engine/data/coin_regime.json` (Daans voorkeur) als draagbare spiegel. Engine + PHP lezen de tabel. |
| 3 | Cadans van de routine | **Wekelijks** (getest als beste in epic-G). Leak-vrij: beslissing op week T gebruikt alleen data t/m T. |
| 4 | Herstart op inactieve munt | Geen echte trades → **schaduw-trade** (`coin_moment_sells`, sell-engine zonder geld) voedt het herstart-oordeel. Stoppen op echt resultaat, herstarten op schaduw-/indicatorsignaal. |
| 5 | Filter standaard aan? | **Ja, overal default AAN** (inactieve-periode-trades tellen niet). Schermen krijgen een **toggle** om inactieve trades alsnog te tonen (gedimd/gemarkeerd). |
| 6 | Geldt het filter ook voor de stats? | Ja — goed/middel/slecht + Σ worden default op de **actieve** trades berekend. Toon ernaast de winst-van-stoppen (Σ actief vs Σ alles). |
| 7 | Volgorde-afhankelijkheid (feedback-lus) | Het regime hangt af van trades; rules hangen (na filter) af van het regime. Discipline: **(1) trades → (2) regime berekenen → (3) rules tunen op actieve trades → (4) re-fire → (5) regime herberekenen.** De routine-keten serialiseert dit; documenteer in [[brain-routines]]. |
| 8 | **Cache-/fingerprint-interactie (NIEUW na de snelheidswijziging)** | Het filter zit op de **consumptie-laag** (`load_trades`/`load_all_fires`), los van de re-fire-cache (`fires_cache.py`) die ALLE trades blijft berekenen. **Maar** elke cache/fingerprint **downstream** van `load_trades` moet de **regime-versie** kennen, anders maskeert een oude cache de filter: (a) `_long_fingerprint(sym)` → anders serveert de long-cache een verouderde actieve-set; (b) `input_fingerprint()` → anders draait de keten niet opnieuw als alléén het regime wijzigt; (c) de groep-/validatie-cache uit het schaalplan idem. Regime-versie = bv. `MAX(computed_at)` of een checksum van `coin_regime` per munt. |

## Build-ready details (geverifieerd 2026-06-27 tegen de huidige code — leidend bij conflict met regelnummers hierboven)

### A. Het gate-algoritme dat `coin_regime.py` EXACT moet spiegelen
Bron-van-waarheid = `www/app/Livewire/Coins/Weekly.php::applyGate()` (regel 74-117) + de constants (regel 37-41):
`GATE_ROLL_WEEKS=4`, `GATE_STOP_FLOOR=20.0`, `GATE_STOP_CONFIRM=2`, `GATE_RESTART_FLOOR=30.0`, `GATE_RESTART_CONFIRM=3`.

Algoritme per munt, chronologisch over de weken (ISO-week, `YEARWEEK(date,3)`, maandag-start):
```
state='on'; below=0; above=0; hist=[]; started=false
voor elke week w (op volgorde):
  als niet started: als w heeft trades -> started=true; anders regime='pre', skip
  hist.append(w.week_pl); als len(hist)>4: hist.pop(0); roll=sum(hist)
  als state=='on':  below = (roll<20 ? below+1 : 0); above=0; als below>=2: state='off'; below=0
  anders:           above = (roll>=30 ? above+1 : 0); below=0; als above>=3: state='on'; above=0
  w.regime=state
```
- `week_pl` = `SUM(profit_loss)` van de executed trades in die week. Python moet dezelfde week-aggregatie op
  `coin_fires` doen als `Weekly.weeklyTradeResult()`: `YEARWEEK(datetime,3)`, `SUM(profit_loss)` per (coin, week),
  `is_executed=1 AND profit_loss IS NOT NULL`.
- **Leak-vrij by construction:** de stand van week T gebruikt alleen `hist` (weken ≤ T). Niet vooruitkijken.
- **Inactieve munten (beslissing #4):** als een munt op `off` staat zijn er live geen echte trades → voed `week_pl`
  met het **schaduw-resultaat** (`coin_moment_sells`, sell-engine zonder geld). Stop op echt resultaat, herstart op
  schaduw. In de backtest/eerste-vulling bestaan er trades over de hele historie, dus daar = echte `coin_fires`.
- **Opslag:** groepeer aaneengesloten gelijke-`state`-weken tot intervallen → rijen `coin_regime`
  (`period_from`=maandag eerste week, `period_to`=zondag laatste week, `state`, `reason`, `rolling_result`,
  `computed_at`). `pre`-weken vóór de eerste actieve periode: geen rij (munt bestond/handelde nog niet).
- **Bit-gelijk aan de UI-streep:** een test moet bewijzen dat `coin_regime` per week dezelfde `on/off` geeft als
  `Weekly.applyGate` op de 4 huidige munten (zelfde constants, zelfde week-pl-bron).

### B. De cache-versie — EXACT waar (kern van beslissing #8; deze laag is 2026-06-26 gebouwd, dus regels kloppen nu)
Het filter zit op de **consumptie-laag**, maar drie caches downstream maskeren 'm als de **regime-versie** er niet in zit.
Definieer in `regime.py`: `regime_ver(sym)` = `SELECT COALESCE(SUM(CRC32(CONCAT(period_from,'|',period_to,'|',state))),0)
FROM coin_regime WHERE trading_symbol_id=%s` (per munt) en `regime_ver_global()` = `SELECT COUNT(*) n, COALESCE(MAX(computed_at),'') mx FROM coin_regime`. Injecteer:

1. **`opt_lib._long_fingerprint(sym)`** — `engine/src/opt_lib.py:159`. Huidige return (≈ regel 172):
   `hashlib.md5(f"{mfp}|long:{_LONG_CODE_VER}:{','.join(CALC_COLS)}|fires:{t['n']}:{t['mx']}:{t['cx']}".encode())`
   → voeg `|regime:{regime_ver(sym)}` toe. Zónder: de per-munt long-cache (`load_long_cached`) serveert een
   verouderde actieve-set na een regime-wijziging.
2. **`routines.input_fingerprint(...)`** — `engine/src/routines.py:49`, sig-assemblage regel 102-108. Voeg toe vóór de
   `return`: `sig += f"#regime:{n}:{mx}"` (uit `regime_ver_global()`). Zónder: de keten draait NIET opnieuw als alléén
   het regime wijzigt → het nieuwe actieve-venster wordt nooit doorgerekend (de "data-veranderd"-gate skipt).
3. **Schaalplan long-/groep-cache** — leunt volledig op `_long_fingerprint`, dus gedekt zodra punt 1 klopt.
   **`fires_cache.py`** (`cached_fires_per_rule` / `cached_fires_incremental`) krijgt het **NIET**: dat is de re-fire
   (upstream, berekent ALLE trades; de gate heeft de volle historie nodig). Expliciet niet aankoppelen.
4. **Epic J's rq2-cache** (volgt ná H): ook regime-versie in de sleutel — dáárom H vóór J.
5. **Eenmalige invalidatie:** bump `_LONG_CODE_VER` (`opt_lib.py:156`, "long-v1"→"v2") en `_FIRES_CODE_VER`
   (`fires_cache.py`, nu "fires-v3") bij de eerste H-deploy zodat caches van vóór de regime-component verdwijnen.

### C. De trade-loaders + het filter — EXACT waar
- `opt_lib.load_trades()` — `engine/src/opt_lib.py:97`; de SELECT op regel 105-106 (`FROM coin_fires WHERE is_executed=1
  AND profit_loss IS NOT NULL`). Voeg default de actieve-periode-filter toe (`regime.active_sql_clause()`), param
  `include_inactive=False`.
- `opt_lib.load_all_fires()` — `engine/src/opt_lib.py:494`; idem.
- Eigen loaders die apart langs moeten (elk hun eigen coin_fires-SELECT): `sell_tuning.py:85`, `subrule_power.py:52`,
  `gate_window.py:56`.
- Helper `engine/src/regime.py`: `is_active(sym, dt)`, `active_filter(df, sym_col, dt_col)`, en
  `active_sql_clause(alias)` (de herbruikbare WHERE-snippet) — één bron voor alle loaders.

### D. Verplichte cache-coherentie-test (de borging tegen "verouderde cache maskeert het filter")
`engine/src/test_regime_filter.py` (plain assert): (1) verzet een `coin_regime`-interval (munt X, een week → inactive);
(2) assert `_long_fingerprint(X)` verandert; (3) assert `input_fingerprint(with_fires=True)` verandert; (4) assert
`load_trades()` levert minder trades en `load_trades(include_inactive=True)` weer alle; (5) herstel in `finally`.
Plus `test_coin_regime.py`: `coin_regime` == `Weekly.applyGate` per week op de 4 huidige munten (bit-gelijk).

## Features (5)

### 1. Opslag `coin_regime` + de gate-routine
**Status:** Approved
Migratie `coin_regime` (kolommen: `trading_symbol_id`, `period_from`, `period_to`, `state` ENUM('active','inactive'),
`reason`, `rolling_result`, `computed_at`). Module `engine/src/coin_regime.py` berekent per munt — wekelijks,
leak-vrij, met de gate v2-logica en het schaduw-trade-herstartsignaal — de intervallen en schrijft ze idempotent weg
(+ JSON-export). Routine-set `coin-regime` (ongegate, wekelijks) in `routines.py`.
**Acceptance Criteria**
- [ ] `coin_regime` bevat per munt aaneengesloten intervallen die samen de actieve periode dekken.
- [ ] Herberekenen is idempotent (zelfde data → zelfde intervallen, geen dubbele rijen).
- [ ] De berekening is leak-vrij: een grensdatum gebruikt alleen data van vóór die datum.
- [ ] Reproduceert de epic-G-gate-uitkomst op de 4 munten (MUMU uit ~half dec '24, FARTCOIN apr, DOGEAI jul, NOS ~mei).

### 2. Gedeelde "is actief?"-helper (PHP + Python)
**Status:** Approved
PHP: `App\Services\CoinRegime` met `isActive(int $coinId, Carbon $dt): bool` + Eloquent query-scope
`scopeActiveOnly($q)` (join/where op `coin_regime`). Python: `engine/src/regime.py` met `is_active(sym, dt)` en
`active_filter(df, sym_col, dt_col)`. Beide lezen `coin_regime`.
**Acceptance Criteria**
- [ ] `isActive` / `is_active` geeft correct true/false voor randdatums (begin/eind interval inclusief).
- [ ] Eén bron: beide implementaties lezen dezelfde tabel, geen losgezongen kopie.

### 3. Engine — actieve-periode-filter in de optimalisatie
**Status:** Approved
`opt_lib.load_trades()` + `load_all_fires()` filteren default de inactieve-periode-trades weg (via `regime.py`).
De eigen loaders `sell_tuning.py`/`subrule_power.py`/`gate_window.py` idem. Een parameter `include_inactive=False`
laat analyse alsnog alles laden. De buy/sell-rule-routines tunen daardoor alleen op actieve trades.
**Let op de cache-laag (zie beslissing #8):** de regime-versie moet in `_long_fingerprint` (per-munt long-cache)
én in `input_fingerprint` (de data-veranderd-gate), anders maskeert een verouderde cache het filter. `fires_cache`
(re-fire) blijft alle trades berekenen — daar NIET ingrijpen.
**Acceptance Criteria**
- [ ] `load_trades()` levert default geen trades uit inactieve perioden; `include_inactive=True` wel.
- [ ] Een optimalisatie-run (bijv. `daily_optimization`) telt aantoonbaar minder trades (alleen actief).
- [ ] Geen module omzeilt het filter ongemerkt (de 3 eigen loaders zijn meegenomen).
- [ ] **Een regime-wijziging invalideert de long-cache** (`_long_fingerprint` verandert) **én triggert de keten**
      (`input_fingerprint` verandert) — een test met handmatig verzet regime bewijst dit.

### 4. Schermen — default filter + winst-van-stoppen zichtbaar
**Status:** Approved
`Trades/Index.php` + `CoinExplorer.php`: default **"alleen actieve periode"**; toggle toont inactieve trades
(gedimd/gemarkeerd "buiten actieve periode"). De Samenvatting toont per munt **Σ actief** naast **Σ alles** zodat de
winst van het stoppen direct leesbaar is.
**Acceptance Criteria**
- [ ] Trades-scherm verbergt default inactieve-periode-trades; toggle maakt ze zichtbaar.
- [ ] Samenvatting toont Σ-actief en het verschil met Σ-alles (de bespaarde verliezers).
- [ ] `/coins/weekly` blijft de volledige historie tonen (dat is juist de kalibratie-bril) — de streep markeert er de inactieve periodes.

### 5. Benchmark blijft ijkpunt
**Status:** Approved
`regime_validate.py` blijft de gate tegen `regime_benchmark.json` scoren; bij nieuwe munten vul je de benchmark
(handmatig oordeel) aan en hertoets je. De gate stuurt het systeem; de benchmark bewaakt de gate.
**Acceptance Criteria**
- [ ] Validatie-script draait op het actuele muntuniverse en rapporteert nullijn/toeval/holdout/munt-eruit.

## Aanbevolen Implementatie Volgorde

Volg per stap de **Build-ready details** (sectie A-D) voor exacte file:regels + snippets.
1. **Migratie + `coin_regime.py`** (Feature 1) met het gate-algoritme uit **sectie A** (spiegelt `Weekly.applyGate`
   bit-gelijk). Test `test_coin_regime.py` (== UI-streep op 4 munten) vóór je verder gaat.
2. **`regime.py` helper** (Feature 2) — `is_active`/`active_filter`/`active_sql_clause` + `regime_ver`/`regime_ver_global`
   (sectie B+C). De helper is de enige bron voor zowel het filter als de cache-versie.
3. **Engine-filter + cache-versie** (Feature 3) — het filter in de loaders (**sectie C**) ÉN de regime-versie in
   `_long_fingerprint` + `input_fingerprint` + de code-versie-bumps (**sectie B**). Sluit af met de verplichte
   `test_regime_filter.py` (**sectie D**) — dít is de borging; niet mergen zonder groen.
4. **Routine `coin-regime`** (Feature 1, ongegate wekelijks) — pas aanzetten als 1-3 groen zijn.
5. **Schermen** (Feature 4) + **validatie** (Feature 5) blijft mee-lopen.

## Nieuwe bestanden aan te maken

| Bestand | Type | Feature |
|---|---|---|
| migratie `*_create_coin_regime_table.php` | DB-schema | 1 |
| `engine/src/coin_regime.py` | regime berekenen + wegschrijven + JSON-export | 1 |
| routine `coin-regime` in `engine/src/routines.py` | wekelijkse set | 1 |
| `engine/data/coin_regime.json` | export (spiegel van de tabel) | 1 |
| `engine/src/regime.py` | Python helper `is_active` / `active_filter` | 2,3 |
| `www/app/Services/CoinRegime.php` + `App\Models\CoinRegime` | PHP helper + scope | 2,4 |
| tests: `engine/tests/test_coin_regime.py`, `test_regime_filter.py` | leak-vrij, randdatums, filter | 1–3 |

## Niet in scope

- De **live-uitvoering** zelf (echt traden aan/uit zetten) — dat is de nog te bouwen live-laag (epic-G Fase 4).
- Nieuwe koop/verkoop-**rules** — dit gaat over wélke trades meetellen, niet over welke rules vuren.
- De gate-**logica** zelf wijzigen — die is in epic-G gevalideerd; hier alleen toepassen.
- Dagelijkse cadans / smoothing — pas overwegen bij meer munten (epic-G Fase 2-bijvangst).
