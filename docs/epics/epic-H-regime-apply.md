# EPIC H: Regime operationaliseren — actieve periode als bron-van-waarheid

> **Status:** Plan (nog niets gebouwd). Bouwt op [epic-G](epic-G-coin-regime-gate.md) (de gevalideerde
> aan/uit-gate) en de findings/benchmark daar. Skill: [[brain-regime-gate]]. Doc: [[docs/regime-gate.md]].

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

1. Feature 1 (opslag + routine) — eerst de bron-van-waarheid.
2. Feature 2 (helper) — de gedeelde lees-laag.
3. Feature 3 (engine-filter) — optimalisatie op actieve trades.
4. Feature 4 (schermen) — zichtbaar maken.
5. Feature 5 (validatie blijft mee-lopen).

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
