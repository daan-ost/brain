# EPIC L: Promising labeler — per-moment koopkwaliteit labelen + classificatie tunen

**Phase:** 0 — Foundation (build now)
**Status:** GEBOUWD (stap 0–5) + reviewed. Afwijkingen t.o.v. het oorspronkelijke plan, na overleg met Daan:
het scherm is **moment-niveau** op de **volumeud-ticks** (de geldige koopmomenten — elke buy-rule heeft
een volumeud currentvalue/time_ago=5 freshness-gate, dus niet-volumeud-momenten zijn geen koopmoment;
de andere indicatoren zijn er wel, as-of beschikbaar), met on-the-fly horizon-berekening, een **filter** (`promising`/`all`/`trades`/`executed`,
promising = max +5..+60 ≥ instelbare drempel, default 3%), en **inline ok/niet-ok** (direct opslaan,
geen modal). Handmatige labels zijn moment-niveau (`coin_moment_labels`, `rule=MOMENT_RULE=0`); legacy
blijft per-rule als referentie. Stap 6–7 (feedback-loop) nog niet gebouwd. Zie [[brain-promising-labeler]].
**Depends on:** Epic A (coin_periods + coin_fires + persist_to_brain.py), Epic R (rule-succes-telling in `daily_optimization.py`), `promising.py` (de promising-verdict + `_validate`).
**Refines:** E02 (labeling) — dit is de hand-labeler die de auto-classificatie corrigeert en tunet.

## Goal

Een apart scherm (`/promising-labeler`) dat de legacy `simulate_buy.php`-reviewtabel nabouwt: per koopmoment zie je of de **upside** er was (koopmoment-kwaliteit, sell-onafhankelijk) versus wat **onze sell-engine** ervan maakte (`profit_loss`). Daarmee kun je per moment snel **yes/no + kwaliteit + reden** labelen, de **legacy yes/no-labels importeren**, en die labels de auto-classificatie + (later) de rule-succes-telling laten voeden — **zonder** dat een re-fire ze wist.

## Rationale

De "promising perioden" zijn nog niet goed geclassificeerd. De auto-classificatie (`best_upside`-drempels + de promising-gates) zit er soms naast op een enkel datetime — en "het komt soms precies op één datetime aan om goed te classificeren". Daan deed dit handwerk al in de legacy site: **4.161 gelabelde momenten** (948 goed / 710 middel / 2.503 slecht) in `wp_trading_simulation.result`. Voor de getrackte coins op scope-rules 20–23: DOGEAI 74/36/301, NOS 11/8/169. Dat werk willen we niet weggooien — we importeren het en zetten het naast de auto-verdict, zodat afwijkingen direct zichtbaar zijn en de drempels meetbaar getuned kunnen worden.

Centraal inzicht: een rij met **+10% upside maar negatieve `profit_loss`** is een **sell-engine-defect**, géén slecht koopmoment. Het scherm scheidt die twee metrics in aparte kolommen zodat het sell-probleem de promising-tuning nooit vervuilt.

## Beslissingen (genomen met Daan)

| # | Vraag | Beslissing |
|---|---|---|
| 1 | Opslag handmatige labels | **Aparte tabel `coin_moment_labels`** (natural key coin+datetime+rule). Overleeft de `DELETE FROM coin_fires` in `persist_to_brain.py:45`. |
| 2 | Tijd-horizons per moment | **+5 / +10 / +15 / +30 / +45 / +60 min** (binnen de 60-min hold; `FORWARD_MINUTES=60`). |
| 3 | Labelvelden | **Beslissing (yes/no/geen-volume) + kwaliteit (goed/middel/slecht) + reden** (pulldown `CoinAnnotation::CATEGORIES` + opmerking). |
| 4 | Wanneer labels meetellen | **Eerst zichtbaar-only:** `daily_optimization` toont beide ratio's (ruw `best_upside` vs met-labels) náást elkaar; labels sturen nog géén tightening. |
| 5 | Legacy-import scope | result IN (1,2,3) voor de getrackte coins (2525, 244), middel(2) gaat mee als referentie. Uitbreidbaar naar meer coins. |
| 6 | Sell-engine | **Niet in scope.** De "upside hoog, profit_loss negatief"-rijen worden alleen zichtbaar gemaakt (signaal voor Epic S). |

## Twee metrics — nooit door elkaar

| | metric | bron | vraag |
|---|---|---|---|
| **Koopmoment-kwaliteit** | `best_upside` + horizon-upsides + vroege dip (`lowest_10`) | engine, sell-onafhankelijk | "viel er te winnen?" |
| **Sell-resultaat** | `coin_fires.profit_loss` | sell-engine | "won ik het ook echt?" |

`manual_klasse`-labels gaan over **koopmoment-kwaliteit** (tegen upside), nooit tegen `profit_loss`.

## Scope

1. **Opslagmodel — `coin_moment_labels` (nieuwe brain-tabel).** Natural key `(trading_symbol_id, datetime, rule, source)`. Kolommen:
   - `decision` enum('yes','no','no_volume') null — legacy `ok_trade`
   - `manual_klasse` enum('goed','middel','slecht') null — override op `klasseKey()`
   - `category` varchar null + `comment` text null — reden (`CoinAnnotation::CATEGORIES`)
   - `source` enum('manual','legacy') default 'manual'
   - `legacy_result` tinyint null (1/2/3 bij import)
   - `set_by` varchar, `set_at` timestamp, timestamps
   - `UNIQUE(trading_symbol_id, datetime, rule, source)`
   - Wordt **nooit** door `persist_to_brain.py` aangeraakt. Model `App\Models\CoinMomentLabel` met enum-casts.
2. **Override-precedence.** `CoinFire::klasseKey()` aanpassen: manual-label > legacy-label > berekende `best_upside`-klasse. Label-relatie eager-loaden (N+1 vermijden). De oude `coin_fires.manual_klasse`-kolom wordt afgeschaft als bron (mag als afgeleide cache blijven, optioneel re-fill na rebuild).
3. **Horizon-upsides in de engine.** Functie die per fire de upside op +5/+10/+15/+30/+45/+60 min berekent op de `volumeud`-prijsserie (echte tijdvensters, max-favorable-excursion binnen elk venster, met piekprijs+tijd) plus `lowest_10` (eerste ~10 ticks). Opslaan op `coin_fires` (losse kolommen `up_5m`…`up_60m` + `lowest10`, of JSON-kolom `horizons`). Wordt in `persist_to_brain.py` gevuld tijdens de re-fire.
4. **Het scherm — `/promising-labeler` (`trades.labeler`), Livewire `PromisingLabeler`, admin-only.** Coin- + dag-navigatie (patroon van `CoinExplorer`). Tabel per koopmoment:
   `tijd · rule · gekocht(is_executed) · +5/+10/+15/+30/+45/+60m upside (tooltip: piekprijs+tijd) · beste upside % · beste upside om · vroege dip % · onze sell-winst % (profit_loss) · auto-verdict · legacy-label · mijn label`.
   Kleur: rij rood als `profit_loss < 0` terwijl upside positief (sell-engine liet geld liggen). Klik op rij/stip → chart-popup (hergebruik `zoomChart()`/`coinChart()` 1-op-1) met extra horizon-piek-markers.
5. **Labelen.** `saveLabel()` schrijft `decision` + `manual_klasse` + `category` + `comment` naar `coin_moment_labels` via `updateOrCreate` (source='manual'). Flash + vorige/volgende-navigatie tussen momenten (patroon `navDetail`).
6. **Legacy-import.** Idempotent command dat `wp_trading_simulation` (read-only `bot_signals`) → `coin_moment_labels` (source='legacy') mapt: `{1:goed,2:middel,3:slecht}` → `manual_klasse`, `{1:yes,3:no}` → `decision`, `result` → `legacy_result`. In het scherm staan legacy-label en mijn-label náást elkaar; divergentie = leersignaal.
7. **Feedback A — drempel-tuning (advies-only).** `promising._validate` uitbreiden tot grid-search over `MIN_UPSIDE_PCT` / `MAX_EARLY_DIP_PCT` / `upside_minutes(symbol)` per coin, getoetst tegen `coin_moment_labels` (manual+legacy). Output: voorgestelde per-coin drempels voor `config.py`. Een mens past `config.py` aan.
8. **Feedback B — rule-succes zichtbaar-only.** `daily_optimization.current_ratios()` (telt nu `SUM(best_upside>=3)` / `SUM(best_upside<0.5)` op `coin_fires`, negeert labels) krijgt een **tweede** ratio die LEFT JOINt op `coin_moment_labels` (source='manual') en de effectieve klasse gebruikt. Beide ratio's in de routine-journaal-output. Labels sturen nog geen tightening (beslissing #4).

## Acceptance criteria

- [ ] `coin_moment_labels` bestaat, met enum-validatie en de UNIQUE natural key; `persist_to_brain.py` raakt de tabel niet aan (label blijft staan na een re-fire).
- [ ] `CoinFire::klasseKey()` gebruikt manual > legacy > best_upside, zonder N+1.
- [ ] Per fire zijn de +5/10/15/30/45/60m upsides + `lowest_10` berekend en opvraagbaar.
- [ ] `/promising-labeler` toont per dag de tabel met de horizon-kolommen, de aparte `profit_loss`-kolom, auto-verdict, legacy-label én mijn-label; rode markering voor upside-positief/`profit_loss`-negatief.
- [ ] Klik opent de chart-popup (hergebruikte zoom-chart) met buy/sell/piek-markers.
- [ ] `saveLabel()` slaat decision+klasse+reden op en overleeft een re-fire.
- [ ] De legacy-import vult `coin_moment_labels` (source='legacy') voor 2525 + 244 idempotent; tellingen matchen de DB (DOGEAI 74/36/301, NOS 11/8/169 op rules 20–23).
- [ ] `daily_optimization` toont beide ratio's (ruw vs met-labels) in de journaal-output.
- [ ] `promising._validate` levert per-coin drempel-advies tegen de labels (precision/recall per gridpunt).

## Nieuwe / te wijzigen bestanden

| Bestand | Type | Stap |
|---|---|---|
| `www/database/migrations/XXXX_create_coin_moment_labels_table.php` | nieuw | 1 |
| `www/app/Models/CoinMomentLabel.php` | nieuw | 1 |
| `www/app/Models/CoinFire.php` | wijzig (`klasseKey()` + relatie) | 1 |
| `www/database/migrations/XXXX_add_horizons_to_coin_fires.php` | nieuw | 2 |
| `engine/src/persist_to_brain.py` | wijzig (horizon-upsides vullen) | 2 |
| `www/app/Livewire/Trades/PromisingLabeler.php` | nieuw | 3–4 |
| `www/resources/views/livewire/trades/promising-labeler.blade.php` | nieuw | 3–4 |
| `www/routes/web.php` | wijzig (route `trades.labeler`) | 3 |
| `www/resources/views/layouts/trading.blade.php` | wijzig (sidebar-link na "Coin explorer", regel ~35) | 3 |
| `engine/src/import_legacy_labels.py` | nieuw (gebruikt `db.py` legacy()+brain()) | 5 |
| `engine/src/daily_optimization.py` | wijzig (`current_ratios()` tweede ratio) | 6 |
| `engine/src/promising.py` | wijzig (`_validate` → grid-search advies) | 7 |

## Aanbevolen implementatievolgorde

0. **Stap 0 — opruimen `CoinExplorer`-modal** (los van dit epic, maar eerst): dode `legacy_remark`-blok weg (`coin-explorer.blade.php:134-139` + null-keys in `CoinExplorer.php:219,245`); override-sectie alleen tonen voor executed fires + zuiver klasse-label i.p.v. de samengestelde `uitkomst`-string; `wire:model.live="manualKlasse"` (regel 146).
1. Opslagmodel (`coin_moment_labels` + model + `klasseKey()`).
2. Horizon-upsides in de engine.
3. Labeler-scherm read-only (tabel + chart-popup + route + sidebar).
4. Labelen schrijven (`saveLabel`).
5. Legacy-import.
6. Feedback B (rule-succes zichtbaar-only).
7. Feedback A (drempel-tuning advies).

## Out of scope

- Wijzigingen aan de sell-engine zelf (de "geld-laten-liggen"-analyse → Epic S).
- Automatisch toepassen van getunede drempels (advies-only; mens past `config.py` aan).
- Labels die meteen tightening sturen (beslissing #4: eerst zichtbaar-only).
- Period-niveau herontwerp; de bestaande `CoinExplorer`-annotaties blijven.

## Open questions (voor Daan)

- Horizon-opslag: losse kolommen `up_5m`…`up_60m` of één JSON-kolom `horizons` op `coin_fires`?
- Import als Python (`engine/src/import_legacy_labels.py`) of Laravel Artisan (`php artisan brain:import-legacy-labels`)?
- Houden we `coin_fires.manual_klasse` als afgeleide cache (re-fill na rebuild) of schaffen we de kolom helemaal af?
