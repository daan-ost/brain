# EPIC K: min_volume-kalibratie & live-herijking

> **Status:** Plan — onderzoek (te draaien ná de lopende rule-optimalisatie). Read-only meet-fase eerst;
> een schrijvend pad (seeder + herijking-routine) pas bouwen als de meting het recept oplevert.
> **Datum opgesteld:** 2026-06-28. Verbonden met [[epic-N-pooled-sell-default]] (samen = het **koude-start-recept**
> voor een verse live coin) en met [epic-10-mexc-execution](epic-10-mexc-execution.md) (de live-databron die dit voedt).

## Epic Specification

Maak van `min_volume` — de per-munt volume-schaal (`relvol = volumeud / min_volume`, `engine/src/volume.py:72`) —
een **eenduidig, reproduceerbaar én live-herijkbaar** gegeven. Concreet: (1) één script dat de start-`min_volume`
van een munt bepaalt op het percentiel dat de gewenste kandidaat-ratio (~9%) geeft, i.p.v. de huidige
hand-getunede p90; (2) een onderzoek dat meet of een betrouwbare `min_volume` al na een **halve dag** live-data
te schatten is, hoe sterk de **listing-piek** dat vertekent, en hoe **gevoelig** de uiteindelijke trades zijn voor
een afwijkende `min_volume`; (3) een **dagelijkse herijking** die `min_volume` bijstelt zolang de schatting nog
beweegt en **bevriest** zodra hij stabiel is.

## Rationale

`min_volume` wordt nu **met de hand** gezet bij onboarding (p90 van de volumeud-reeks, of de legacy-waarde),
**één keer**, en blijft daarna **permanent** — er is geen script, geen herijking, en geen toets op hoeveel een
afwijking uitmaakt. Dat werkt voor een munt met volledige historie, maar **niet** voor een verse live coin: die
heeft op dag 1 maar een fractie van de data, en juist de eerste uren (de listing-piek) zijn het minst
representatief. Zonder een onderbouwd koude-start-recept traden we een nieuwe munt blind op een gokwaarde — en
`min_volume = 0` laat de engine zelfs crashen (`volume.py:72`, geen nul-guard). Dit epic maakt de bepaling hard
en live-bruikbaar.

## Dependencies

- `brain.indicators` (de volumeud-reeks per munt) — **aanwezig**, de enige bron voor de schatting.
- `engine/src/compute_volume_found.py` — vertaalt `min_volume` → `brain_volume_found` (kandidaat-ticks); de
  verificatie-stap van de kandidaat-ratio. **Aanwezig.**
- De 12 geladen munten met volledige historie — het **meet-substraat** voor het convergentie-/gevoeligheidsonderzoek.
- [epic-10-mexc-execution](epic-10-mexc-execution.md) (live-databron) — de herijking-routine wordt pas echt live
  zodra er een stroom verse data binnenkomt; tot die tijd is dit epic backtest-only te valideren. **Niet af.**

## Bestaande Code (referentie)

| Bestand:regel | Wat |
|---|---|
| `engine/src/seed_rules.py:36-44` | kopieert `min_volume` uit legacy `bot_signals.wp_trading_symbols_rule` (JSON-veld) bij onboarding — de enige schrijver |
| `engine/src/seed_rules.py:23` | `DELETE FROM coin_rule_settings` — **wist alles**; dit script mag NOOIT opnieuw draaien na onboarding |
| `engine/src/volume.py:72` | `rel0 = round(value_0 / min_volume, 2)` — **geen nul-guard** (regel 80 heeft 'm wél) → `min_volume=0` crasht |
| `engine/src/compute_volume_found.py:26-36` | zonder `min_volume` per rule → `brain_volume_found=0` → géén kandidaat-ticks → géén trades |
| `engine/src/discovery/data.py:57-66` | `min_volume(symbol)` = laagste `min_volume` per munt (gebruikt door discovery) |
| `engine/src/fires_cache.py:55,82` · `loosen_cache.py:40` | `min_volume` zit in de cache-fingerprint (CRC32) → een wijziging **invalideert de cache** = volledige refire van die munt |
| `engine/src/integrity.py:349-362` | valideert dat elke munt × buy-rule (20-23) een `min_volume` heeft (niet NULL) |

**Huidige stand per munt (2026-06-28):** munten mét legacy 20-23 (DOGEAI 15.169 / NOS 510 / FARTCOIN 19.582 /
MUMU 33,3 mln / CATDOG) dragen de legacy-waarde; munten zónder (TURBO 3,95 mln=p90 / PONKE 8.705=p90 / PEPE2
32 mld=**p94** / ATR 27.088=p90 / POPCAT 19.207=p90 / 1DOLLAR 97.322=**p97** / JELLYJELLY 41.228=p90) een
hand-getuned percentiel. **p90 was 2× niet raak** (PEPE2 14,4% en 1DOLLAR 19,8% kandidaat-ratio → omhoog getuned).

## Beslissingen

| # | Vraag | Beslissing |
|---|---|---|
| 1 | Wat is het echte doel-getal? | **Niet "p90" maar de kandidaat-ratio** (~9%, empirisch de schaal van de 4 oorspronkelijke munten). De seeder zoekt het percentiel dat die ratio geeft — dat lost het "p90 niet altijd raak"-probleem structureel op (PEPE2/1DOLLAR hadden dan meteen geklopt). |
| 2 | Eenmalig of herijkbaar? | **Herijkbaar.** Eenmalig-permanent faalt voor verse coins. Dagelijks bijstellen zolang de schatting beweegt; **bevriezen** zodra stabiel (criterium uit het onderzoek). |
| 3 | Refire-kost van bijstellen | Geaccepteerd, want **inherent**: `min_volume` is een schaal → een wijziging raakt relvol van álle ticks → **volledige refire** van die munt (Epic I-incrementeel helpt niet; `min_volume` zit in de prefix-checksum). Dat is precies waarom bevriezen waardevol is — het stopt de dagelijkse refire-kost. |
| 4 | nul-guard | **Verplicht fixen** (`volume.py:72`), los van de onderzoeksuitkomst. Plus een integrity-check die `min_volume>0` afdwingt. |
| 5 | Read-only eerst | De meet-fase (O1-O3) muteert niets. Het schrijvende pad (seeder + routine) pas bouwen als het recept staat. |
| 6 | Scope-grens | Dit epic levert het **getal en de herijking**, niet de live-databron zelf (dat is epic-10) noch de aan/uit-beslissing van een munt (dat is de regime-gate, epic-H). |

## Onderzoeksvragen (de meet-fase — bepaalt het recept)

> Te draaien op de 12 geladen munten: we kennen hun volledige historie, dus we simuleren "alleen de eerste X uur
> bekend" en vergelijken met de eind-waarde. Resultaat = het koude-start-recept (na hoeveel uur eerste schatting,
> hoe vaak bijstellen, wanneer bevriezen).

- **O1 — Convergentie (licht, pure data-query).** Hoe snel nadert de percentiel-schatting uit de eerste
  ½ dag / 1 dag / 3 dagen / 1 week de eind-schatting? Per munt het verloop; conclusie = de minimale data vóór de
  eerste betrouwbare schatting. *(5-min candles: ½ dag ≈ 144 ticks, 1 week ≈ 2016.)*
- **O2 — Listing-piek-bias (licht).** Is het volume in de eerste dag(en) systematisch hoger dan in het stabiele
  regime (launch-hype)? Zo ja, hoeveel — en overschat de vroege percentiel-schatting daardoor `min_volume`
  (→ te weinig kandidaten)? Bepaalt of de eerste schatting een **correctie** of een **wachttijd** nodig heeft.
- **O3 — Gevoeligheid (zwaar — refires).** Hoeveel verandert de **trade-uitkomst** (Σprofit, aantal trades,
  kandidaat-ratio) als `min_volume` ±20% / ±30% / ±50% afwijkt? **Dit is de kernvraag:** is `min_volume` ruw-goed
  voldoende (→ halve-dag-start veilig, weinig bijstellen) of scherp-gevoelig (→ voorzichtig traden tot stabiel)?

## Features (4)

### 1. nul-guard + integrity (verplicht, los van het onderzoek)
**Status:** Approved
Nul-guard in `volume.py:72` (val terug op de `0.0001`-sentinel zoals regel 80, of expliciete skip) zodat
`min_volume=0/NULL` nooit meer crasht. Breid `integrity.py` uit met een check `min_volume>0` per munt×buy-rule.
**Acceptance Criteria**
- [ ] `min_volume=0` levert geen ZeroDivisionError meer op; de munt wordt overgeslagen met een duidelijke melding.
- [ ] `integrity.py` faalt expliciet als een munt een ontbrekende of niet-positieve `min_volume` heeft.

### 2. Onderzoek O1-O3 → het koude-start-recept
**Status:** Approved
Eén read-only analyse-script dat O1 (convergentie) + O2 (listing-piek) over alle 12 munten meet (pure
`indicators`-query), en O3 (gevoeligheid) op een steekproef van munten via refire-sweep van `min_volume`.
Output = een findings-doc met het recept: minimale data vóór eerste schatting, of een listing-piek-correctie
nodig is, hoe vaak bijstellen, en het bevries-criterium (bv. "N dagen binnen ±X% → bevriezen").
**Acceptance Criteria**
- [ ] Per munt het convergentie-verloop (vroege schatting vs eind, als % van eind) voor ½ dag/1 dag/3 dag/1 week.
- [ ] Listing-piek gekwantificeerd (eerste-dag-volume vs stabiel regime) + of de vroege schatting structureel te hoog is.
- [ ] Gevoeligheidstabel: Δ Σprofit / Δ trades bij ±20/30/50% `min_volume` op ≥3 munten.
- [ ] Een concreet, onderbouwd recept (geen vermoeden) in `docs/findings/`.

### 3. Eenduidige seeder (vervangt de handmatige p90-stap)
**Status:** Approved
`engine/src/seed_min_volume.py <coin>`: bepaalt `min_volume` = het percentiel dat de doel-kandidaat-ratio (~9%,
Beslissing 1) geeft over de **beschikbare** volumeud-reeks, schrijft het naar `coin_rule_settings` voor de
buy-rules (idempotent, géén `DELETE`, alleen die munt), en verifieert via `compute_volume_found.py` dat de ratio
in de band valt. Past het onderzoeks-recept toe (incl. eventuele listing-piek-correctie / minimale-data-drempel).
**Acceptance Criteria**
- [ ] Reproduceert de huidige goede waarden (munten met ~9% ratio blijven ~gelijk; PEPE2/1DOLLAR komen meteen in de band).
- [ ] Schrijft alleen de opgegeven munt; raakt geen andere rijen (geen `DELETE`).
- [ ] Print de gekozen percentiel + de geverifieerde kandidaat-ratio.

### 4. Dagelijkse herijking-routine met bevriezing
**Status:** Approved (bouwen ná het recept)
Routine die per **nog-niet-bevroren** munt dagelijks `min_volume` herschat op alle bekende data, bijstelt als de
afwijking de drempel uit het recept overschrijdt (→ markeert de munt voor een volledige refire), en de munt
**bevriest** zodra de schatting stabiel is. Een bevroren munt wordt niet meer herschat (geen refire-kost meer).
Een nieuwe staat-kolom (bv. `coin_rule_settings.min_volume_frozen_at` of een aparte tabel) houdt de status bij.
**Acceptance Criteria**
- [ ] Een verse munt krijgt dagelijks een bijgewerkte `min_volume` zolang de afwijking > drempel; de wijziging triggert een refire van die munt.
- [ ] Zodra het bevries-criterium is bereikt, stopt de herschatting (idempotent, geen refires meer).
- [ ] Een bevroren munt blijft bevroren tot handmatige reset; gelogd in de routine-journal.
- [ ] De herijking raakt nooit een andere munt en nooit andere kolommen dan `min_volume` (+ de status).

## Aanbevolen Implementatie Volgorde

1. **Feature 1** (nul-guard + integrity) — klein, los, direct veilig. Geen afhankelijkheid van het onderzoek.
2. **Feature 2** (onderzoek O1-O3) — eerst O1+O2 (licht, pure query), dan O3 (zwaar, refire-sweep). Levert het recept.
3. **Feature 3** (seeder) — codificeer het recept; valideer tegen de bestaande 12 munten.
4. **Feature 4** (herijking-routine) — pas bouwen als 2+3 staan; live pas relevant zodra epic-10 verse data levert.

## Nieuwe bestanden aan te maken

| Bestand | Type | Feature |
|---|---|---|
| `engine/src/min_volume_study.py` | read-only onderzoek O1-O3 | 2 |
| `docs/findings/min-volume-koude-start-YYYY-MM-DD.md` | het recept | 2 |
| `engine/src/seed_min_volume.py` | eenduidige seeder (percentiel→ratio) | 3 |
| routine `min-volume-recalib` in `engine/src/routines.py` | dagelijkse herijking + bevriezing | 4 |
| migratie (status-kolom/tabel voor bevriezing) | DB-schema | 4 |
| `engine/src/test_min_volume.py` | nul-guard + seeder-reproductie + bevries-idempotentie | 1,3,4 |

## Niet in scope

- De **live-databron** zelf (stroom verse ticks van een nieuwe munt) — dat is epic-10 (mexc-execution).
- De **aan/uit-beslissing** van een munt (regime) — epic-G/H.
- De **volume-rule-drempels** in de rule-condities (relvol-trigger per subregel) — dat is de settings-sweep
  (`volume_sweep.py`), een andere knop dan de `min_volume`-schaal.
- Het **tunen** van `min_volume` als optimizer-output — het blijft een ijk-constante (de schaal van de munt), geen knop.

## Open vragen (voor Daan)

1. Bevries-criterium: vast (bv. "3 dagen binnen ±10%") of afgeleid uit O1 per munt-type? (Beslis na het onderzoek.)
2. Doel-kandidaat-ratio: hard op ~9% of een band (7-11%)? (Bepaalt hoe streng de seeder het percentiel zoekt.)
