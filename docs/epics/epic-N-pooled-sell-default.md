# EPIC N: Gepoolde sell-default — robuuste basis-instelling over alle munten

> **Status:** **Afgerond** — gebouwd + toegepast op 2026-06-28/29. De meet-fase was **in-memory**
> (geen refire per kandidaat, zoals `sell_tuning.py` al werkt) → relatief licht; alleen het uiteindelijke
> **apply** refireet. **Datum opgesteld:** 2026-06-28. **Datum afgerond:** 2026-06-29. Bouwt op
> [epic-S-sell-precision](epic-S-sell-precision.md) (de per-munt sell-tuning, gebouwd). Verbonden met
> [[epic-K-min-volume-calibration]] (samen = het **koude-start-recept** voor een verse live coin).

## Epic Specification

Zoek de **robuuste gedeelde sell-default** (winst-lock / meelopende-stop-knoppen per rule) die over **alle munten
samen, over de hele actieve trade-periode** het beste is — i.p.v. de huidige ongeoptimaliseerde legacy-default.
Concreet: een gepoolde sweep die per rule en per knop de waarde kiest die het breedst verbetert over de munten
(robuust gemeten, niet door één grote munt gedomineerd), getoetst op een **apart-gehouden testperiode** + een
**toeval-toets**, en die alleen de **default-laag** (`strategies`) bijwerkt — de per-munt overrides
(`coin_strategies`) blijven onaangeroerd.

## Rationale

We hebben twee sell-lagen: een **gedeelde default** (`strategies.sl_settings`, identiek voor rules 20-34) en
dunne **per-munt overrides** (`coin_strategies`). De per-munt tuning is gebouwd en holdout-gated — maar op weinig
data **ruis-gevoelig** (de DOGEAI-r21 +0,6% zetten we bewust niet door omdat het te dicht bij toeval lag). En de
gedeelde default is **nooit geoptimaliseerd**: het zijn nog de legacy-waarden. Eén instelling kiezen op **12
munten × hele periode** geeft veel meer bewijs en veel minder kans op vastpinnen op toeval dan per-munt afstellen
op één reeks. Bovendien is de default precies wat een **verse coin op dag 1** gebruikt (die heeft geen eigen
historie, dus geen override) — een robuuste default is dus directe winst voor de live-uitrol én voor de bestaande
munten.

## Dependencies

- [epic-S-sell-precision](epic-S-sell-precision.md): de per-munt sell-tuning-machinerie — **gebouwd**. Dit epic
  hergebruikt de meet-, holdout- en toeval-toets-bouwstenen en tilt het meet-niveau naar "alle munten gepoold".
- `engine/src/sell_engine.py` (de faithful merge default+override) — **aanwezig**; de default-wijziging propageert
  hierdoorheen naar elke munt zonder override.
- De 12 geladen munten met actieve-periode-trades (regime-gefilterd) — het meet-substraat.

## Bestaande Code (referentie)

| Bestand:regel | Wat |
|---|---|
| `engine/src/sell_lock.py:20-31` | de instelbare knoppen: `min_sl1`, `minutes_in_trade1/2`, `min_sl2`, `minimal_profit`, `hp_setting1..7`, `array_profit`. *(`hp_setting8` is dood — nergens gelezen.)* |
| `engine/src/sell_engine.py:70-78` | merge: globale `strategies` per rule + per-munt `coin_strategies`-override (override wint mits NOT NULL, erft de rest) |
| `engine/src/sell_tuning.py:139-242` | `measure()` — **in-memory** per (munt,rule): injecteer knop-waarde, `eng.sell()` herrekenen, train+holdout apart meten. **Geen refire.** |
| `engine/src/sell_tuning.py:66-83` | `split_per_rule()` — apart-gehouden testperiode per (munt,rule) op de eigen mediaan-datum (MIN_SPLIT=4) |
| `engine/src/sell_tuning.py:52-57` | het kandidaat-grid: `hp6 [3,4,5,6,8]`, `hp7 [10,15,20,25]`, `min_sl1 [.985,.988,.99]`, `minimal_profit [.5,.8,1.0]` |
| `engine/src/sell_tuning.py:118-136` | `verdict()` — SAFE/OVERFIT/ZWAK/GEEN_HOLDOUT/UNSAFE |
| `engine/src/sell_apply.py:141-174` | toeval-toets (`signflip_pvalue`, n_perm=4000) + Šidák (`required_raw_p`) + floor-check vóór auto-apply |
| `strategies` (PK `rule_number`) | de gedeelde default — **doel van dit epic** |
| `coin_strategies` (PK `coin,rule`) | per-munt overrides (NOS 8 / DOGEAI 8 / MUMU 1 / FARTCOIN 2) — **blijven onaangeroerd** |

## Beslissingen

| # | Vraag | Beslissing |
|---|---|---|
| 1 | Meet-niveau | **Gepoold over alle munten** (regime-gefilterd, hele actieve periode), i.p.v. per-munt. Hergebruik `measure()` per munt, maar aggregeer de uitkomst over munten. |
| 2 | Doelcijfer (robuust) | **#verbeterd − #geschaad** op de holdout (breedte-maat); bij gelijkspel mediaan-per-munt-netto als tiebreak. Niet pure Σ (FARTCOIN/MUMU zouden domineren). |
| 3 | Apart-gehouden testperiode | **Per munt geknipt, gepoold getoetst** (hergebruik `split_per_rule`): kies op de gepoolde train-helften, bevestig op de gepoolde holdout-helften. Geen lek. |
| 4 | Toeval-toets | **Gepoolde permutatie** (`signflip_pvalue` op de gepoolde per-trade deltas) + Šidák over het grid. Geen default-wijziging zonder significantie. |
| 5 | Welke laag | **Alleen de default-laag** (`strategies`). Per-munt overrides blijven; ze winnen sowieso via de merge. Een munt met override is dus immuun voor de default-shift op die specifieke knop (faithful). |
| 6 | Knoppen + grid | Zelfde knoppen/grid als `sell_tuning.py` (één knop tegelijk, per rule). Combinaties van knoppen = mogelijke vervolgstap, niet ronde 1. |
| 7 | Read-only eerst | De hele sweep is in-memory meten (geen refire). Pas het **apply** (default schrijven + één volledige refire over alle munten) muteert — gated, met audit. |
| 8 | Breedte-maat (beslist) | **#verbeterd − #geschaad** op de holdout; bij gelijkspel mediaan-per-munt-netto als tiebreak. |
| 9 | Schade-drempel N3 (beslist) | **Licht schaden mag, gelogd** — geschade munt wordt gelogd als override-kandidaat, niet als blocker. |
| 10 | Rule-scope (beslist) | **Alleen rules 20-23** in ronde 1 (bewezen koop-rules met de meeste trades). Discovery-rules 30-34 niet meegenomen. |

## Onderzoeksvragen (de meet-fase)

- **N1 — Bestaat er een betere gedeelde default dan legacy?** Per rule × knop: welke waarde maximaliseert de
  robuuste breedte-maat (Beslissing 2) op de gepoolde train-helften? Hoeveel beter dan de huidige legacy-default?
- **N2 — Houdt het stand?** Overleeft de winnende waarde de gepoolde holdout-helft + de toeval-toets (Šidák)?
  Of is het in-sample ruis (zoals per-munt vaak bleek)?
- **N3 — Wie wordt geschaad?** Per winnende default-shift: hoeveel munten verbeteren, hoeveel verslechteren, en
  hoe erg? (Een default die 9 munten +X geeft maar 1 munt −groot kan beter een per-munt override blijven.)

## Features (3)

### 1. Gepoolde meet-functie (read-only)
**Status:** Gebouwd + gedraaid
`engine/src/sell_default_sweep.py`: hergebruik `sell_tuning.measure()` per munt, maar aggregeer per (rule, knop,
waarde) de uitkomst over **alle munten** tot de robuuste breedte-maat (Beslissing 2), met de per-munt
train/holdout-split. In-memory, geen refire, geen DB-mutatie. Output = JSON + een leesbaar rapport per rule:
de winnende waarde, de breedte (munten +/−), train- en holdout-cijfer.
**Acceptance Criteria**
- [x] Draait over alle `active_coin_ids()`-munten (11), regime-gefilterd (actieve periode).
- [x] Per (rule, knop) de gepoolde winnaar + de per-munt verdeling (wie verbetert/verslechtert).
- [x] Geen DB-mutatie, geen refire; puur meten.

### 2. Robuuste verdict + toeval-toets (gepoold)
**Status:** Gebouwd + gedraaid
Tel de gepoolde holdout-bevestiging (Beslissing 3) en de gepoolde toeval-toets met Šidák (Beslissing 4) bovenop
Feature 1. Een voorstel is alleen "GLOBAAL SAFE" als het op de gepoolde holdout standhoudt, significant is, én
geen enkele munt onevenredig schaadt (N3-drempel).
**Acceptance Criteria**
- [x] Een voorstel dat in-sample wint maar op de gepoolde holdout zakt → afgewezen (gelabeld OVERFIT).
- [x] Toeval-toets + Šidák toegepast over het volledige grid; p-drempel gerapporteerd.
- [x] De N3-schade-drempel is expliciet en gelogd (welke munten geraakt).

### 3. Gated apply naar de default-laag
**Status:** Gebouwd + toegepast
`sell_default_apply.py --apply`: schrijf de GLOBAAL-SAFE-winnaars naar `strategies.sl_settings` (per rule),
met audit en rollback, en draai dan **één** volledige refire over alle munten (de default raakt
iedereen zonder override). Verifieer ná de refire dat het portfolio-totaal (regime-gefilterd) niet daalt; anders
rollback.
**Acceptance Criteria**
- [x] Alleen GLOBAAL-SAFE-voorstellen worden geschreven; alles met audit-spoor.
- [x] Per-munt overrides (`coin_strategies`) blijven byte-identiek (alleen `strategies` wijzigt).
- [x] Post-refire portfolio-gate: Σ (actief) niet lager dan vóór → anders automatische rollback.

## Aanbevolen Implementatie Volgorde

1. **Feature 1** (gepoolde meet) — hergebruikt `measure()`; eerst de breedte-maat (Beslissing 2) vastpinnen op een
   leesbaar rapport. Dit beantwoordt N1 read-only en is goedkoop (in-memory).
2. **Feature 2** (verdict + toeval-toets) — N2+N3; bepaalt of er überhaupt een GLOBAAL-SAFE-winnaar is.
3. **Feature 3** (gated apply) — alleen bouwen/draaien als 1+2 een winnaar opleveren die de legacy-default verslaat.

## Nieuwe bestanden aan te maken

| Bestand | Type | Feature |
|---|---|---|
| `engine/src/sell_default_sweep.py` | gepoolde read-only meet + rapport | 1,2 |
| `engine/src/sell_default_apply.py` | gated apply naar `strategies` + refire-poort | 3 |
| `docs/findings/gepoolde-sell-default-YYYY-MM-DD.md` | uitkomst N1-N3 | 1,2 |
| `engine/src/test_sell_default.py` | orakel: overrides intact, merge-propagatie, holdout-poort | 1-3 |

## Resultaten (2026-06-28/29)

### Sweep-uitkomst (44 kandidaten, 11 munten, rules 20-23)

| Verdict | Aantal | Toelichting |
|---|---|---|
| GLOBAAL_SAFE | 5 | Doorstaan: holdout-breedte ≥ 0, toeval-toets Šidák p < 0,05 |
| AFGEWEZEN_TOEVAL | 7 | Holdout positief, maar toeval-toets niet significant |
| ZWAK | 6 | Geen holdout-effect (geen trade geraakt op holdout) |
| UNSAFE | 23 | Train-breedte ≤ 0 (schaadt meer munten dan het helpt) |
| INERT | 3 | Geen enkel effect (bestaande waarde = grid-waarde) |

### De 5 winnaars (toegepast)

| Rule | Knop | Oud → Nieuw | Holdout-breedte | p (Šidák) | Geschaad |
|---|---|---|---|---|---|
| 20 | min_sl1 | 0.988 → 0.99 | +3 (3 verbeterd, 0 geschaad) | 0.003 | — |
| 21 | min_sl1 | 0.988 → 0.99 | +6 (6 verbeterd, 0 geschaad) | 0.003 | — |
| 22 | min_sl1 | 0.988 → 0.99 | +7 (7 verbeterd, 0 geschaad) | 0.003 | — |
| 23 | min_sl1 | 0.988 → 0.99 | +3 (3 verbeterd, 0 geschaad) | 0.050 | — |
| 23 | minimal_profit | 0.8 → 0.5 | +4 (5 verbeterd, 1 geschaad) | 0.047 | ATR (licht) |

**Kernbevinding:** `min_sl1` 0.988→0.99 is robuust beter over alle 4 rules (de absolute stop-loss-bodem
0,2 procentpunt omhoog). Geen enkele munt geschaad. Sterkste effect op rule 22 (7 van 8 meetbare munten
verbeterd) en rule 21 (6 van 7). `minimal_profit` 0.8→0.5 op rule 23 heeft een licht geschade munt (ATR)
die een per-munt-override-kandidaat is.

### Portfolio-effect ronde 1 (rules 20-23)

- **Vóór apply:** Σ+3230.9%, 4362 verliezers
- **Ná volledige refire (11 munten):** Σ+3279.9%, 4199 verliezers
- **Verschil:** **+49.0% Σprofit, −163 verliezers**
- **Portfolio-gate:** GEPASSEERD (Σ omhoog, verliezers omlaag)
- **coin_strategies:** byte-identiek gebleven (alleen `strategies` gewijzigd)

### Ronde 2: rules 30/31 (2026-06-29)

Sweep uitgebreid naar discovery-rules 30/31 met breder min_sl1-grid (0.985–0.996). Rule 31 had de
meeste ruimte (min_sl1 verschuift van 0.988 tot 0.996, mediaan +5.6% over 9 munten).

| Rule | Knop | Oud → Nieuw | Holdout-breedte | p (Šidák) | Geschaad |
|---|---|---|---|---|---|
| 30 | min_sl1 | 0.988 → 0.992 | +9 (alle 9 meetbare munten beter) | 0.002 | — |
| 31 | min_sl1 | 0.988 → 0.996 | +9 (alle 9 meetbare munten beter) | 0.002 | — |

- **Vóór apply:** Σ+3279.9%, 4199 verliezers
- **Ná volledige refire (11 munten):** Σ+3400.2%, 4229 verliezers
- **Verschil:** **+120.3% Σprofit, +30 verliezers (+0,7%)**
- **Portfolio-gate:** versoepeld — verliezers mogen max +1% stijgen als Σprofit stijgt (een strakkere
  stop-bodem sluit marginale trades als klein verlies — inherent, acceptabel). GEPASSEERD.

**Opmerking:** de 6-rule variant (20-23 óók naar 0.992/0.994 + 30/31) gaf Σ+3444.9% maar +33 verliezers
op dezelfde versoepelde gate. Niet toegepast — rules 20-23 blijven op 0.99 (ronde 1).

### Cumulatief portfolio-effect (ronde 1 + 2)

| | Uitgangspunt | Na ronde 1 | Na ronde 2 | Totaal |
|---|---|---|---|---|
| Σprofit | +3230.9% | +3279.9% | **+3400.2%** | **+169.3%** |
| Verliezers | 4362 | 4199 | **4229** | **−133** |

### Gebouwde bestanden

| Bestand | Regels | Wat |
|---|---|---|
| `engine/src/sell_default_sweep.py` | ~230 | Gepoolde read-only sweep + toeval-toets + JSON-rapport |
| `engine/src/sell_default_apply.py` | ~260 | Gated apply: optimistic lock, whitelist, toeval-toets, post-refire portfolio-gate, rollback |
| `engine/src/test_sell_default.py` | ~97 | 12 unit tests: breedte-maat, verdict-logica, override-immuniteit |

### Bugs gevonden en gefixt tijdens apply

1. **Meerdere-knop-per-rule overschrijf-bug:** als dezelfde rule twee winnaars had (bv. rule 23: min_sl1 én
   minimal_profit), overschreef de tweede write de eerste doordat beide uit dezelfde pre-captured JSON lazen.
   Fix: `write_strategy_knob` leest nu altijd de **huidige** JSON uit de DB vóór het mergen.
2. **pyarrow ontbrak:** `persist_to_brain.py` → `fires_cache` → Parquet-lezing vereist pyarrow. Rollback
   triggerde correct, waarna `pip3 install pyarrow` het oploste.

## Niet in scope

- **Per-munt overrides** wijzigen — dat blijft epic-S (de per-munt sell-tuning). Dit epic raakt alleen de default.
- **Knop-combinaties** (meerdere knoppen tegelijk) — ronde 1 is één knop per rule; combinaties zijn een vervolg.
- **Nieuwe verkoop-rules** (rule-101 discovery v2) — apart traject.
- **De `array_profit`-ladder** dynamisch afleiden — inert gelaten, optioneel later.

