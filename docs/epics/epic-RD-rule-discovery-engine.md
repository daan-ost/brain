# EPIC RD: Rule-Discovery Engine — bottom-up nieuwe koop-rules uit promising trades

**Phase:** 1 — Build now (handmatig/CLI; automatisering = Epic RDA)
**Status:** **GEBOUWD (juni 2026, handmatig/CLI)** — engine staat in `engine/src/discovery/`
(`data/segment/funnel/validate/report/run.py`). Draaien: `python -m discovery.run --coin both`.
Feature 2-5 af; Feature 1 (volledige Parquet feature-store) bewust UITGESTELD (in-memory lean-featureset
volstaat voor 2 munten); Feature 6 (`apply.py`) NIET gebouwd — er kwam geen KEEPER uit (conform plan:
stop bij het oordeel). Uitkomst + cijfers: [[docs/methodology/rule-discovery.md]] §12.
Verkennings-harness (`engine/src/parent_*.py`) blijft als read-only proof.
**Depends on:** Epic L (`coin_moment_labels` yes-marks = grondwaarheid), Epic A (`coin_fires`/`coin_periods`
+ `persist_to_brain.py`), Epic B (feature store), [[brain-sell-engine]] (de eindtoets), Epic R (bestaande
rule-structuur 20-23).
**Refines:** E06 (de handmatige variant van de autonome discovery-loop). Automatisering = Epic RDA.

## Epic Specification

Bouw een engine die **bottom-up nieuwe koop-rules ontdekt** uit de handmatig gemarkeerde promising trades
(`coin_moment_labels.decision='yes'`), buiten de huidige volume-poort (`brain_volume_found`) om. De engine
**segmenteert** de promising groepen in coherente subsets, **stapelt subregels** (funnel) tot een rule op
20-23-schaal, en **valideert** elke kandidaat hard (tijd-holdout + permutatie + gerealiseerde winst via de
sell-engine). Levert kandidaat-rules in het 20-23-formaat (platte AND van subregels), klaar om via de
bestaande `rules`/`coin_strategies`-weg toe te voegen. Volledige methodiek: [[docs/methodology/rule-discovery.md]].

## Rationale

Veel promising trades worden gemist door de huidige volume-poort en de bestaande rules 20-23 (lage recall).
De promising momenten zijn echte stijgingen (mediaan +12-16% binnen 60 min, geverifieerd), maar in
indicator-ruimte lijken ze op de achtergrond — een gemene deler over **álle** groepen is per definitie
kansloos. De oplossing (en exact wat het vakgebied **Subgroup Discovery + Rule Induction** voorschrijft):
segmenteer de groepen, en dik elk segment in met een funnel van subregels tot 20-23-precisie, met strikte
overfit-discipline (de **backtest-overfitting-statistiek** van López de Prado). Doel als geheel: **30-50%
van de promising trades vangen** met 20-23-kwaliteit. Zie [[rule-discovery-prior-art]].

## Bestaande Code (referentie)

- `engine/src/parent_segment.py` — natuurlijke-split-segmentatie van de groepen (géén achtergrond).
- `engine/src/parent_funnel.py` — de funnel: subregels stapelen, recall+selectiviteit per stap volgen.
- `engine/src/parent_perm.py` / `parent_perm_fixed.py` — permutatie-test (scan vs pre-registered).
- `engine/src/parent_eval.py` — trouwe sell-engine-dedup (`selling_date`) + `trade_stats` + artefact-guard.
- `engine/src/feature_store.py` / `feature_query.py` — feature store (Parquet) als substraat.
- `engine/src/persist_to_brain.py` — canonical refire + single-position dedup (de incrementele gate).

## Beslissingen

| # | Vraag | Beslissing |
|---|---|---|
| 1 | Grondwaarheid | **Alleen `decision='yes'`-marks**; ongelabeld ≠ slecht. Zie [[feedback-hard-numbers-only]]. |
| 2 | Beoordelings-maat | **Gerealiseerde `profit_loss`** via sell-engine (geen best_upside). goed≥3/middel0-3/slecht<0. |
| 3 | Succes-lat | **20-23-niveau** (selectiviteit ≤~0,1% ticks, gem ≥+0,7%/trade, slecht ≤~45%), NIET random. |
| 4 | Zoek-frame | **Segmenteer de groepen** (10-25% per segment), NIET één gemene deler over alle groepen. |
| 5 | Overfit-rem | tijd-holdout + **permutatie-test ALTIJD**; pre-register > scan. Meer scannen = hogere ruisvloer. |
| 6 | Tooling | Leun op bestaande libs: **pysubgroup** (subgroup discovery), **wittgenstein/RIPPER** (rule induction). |
| 7 | Holdout | Upgrade naar **CPCV + purging/embargo** (verdeling van OOS, López de Prado) i.p.v. één split. |
| 8 | Rapportage | **Compact per munt**: N/M promising groepen | goed/middel/slecht | Σprofit. [[feedback-compact-result-format]] |

## Features (6)

### 1. Feature store uitbreiden naar de volle calc-set
**Status:** Approved. Per in-scope datetime (group-ticks + achtergrond-sample + alle ticks voor projectie):
volledige `WINDOW_METRIC_KEYS` × indicator (obv/vzo/mfi/phobos/volumeud) × lookback 1-20, **plus
prijs-features** (% stijging/dip/range over lookback). Incrementeel, leak-free as-of.
**Acceptance Criteria**
- [ ] Feature store dekt de volle ~30 calcs × lookback 1-20 × 5 indicatoren + prijs-features.
- [ ] As-of correct (geen look-ahead); incrementeel bij te werken met nieuwe ticks.

### 2. Segmentatie-module (Subgroup Discovery)
**Status:** Approved. Vind segmentaties die 10-25% van de groepen binden (natuurlijke splits / pysubgroup),
per munt én gepoold (scale-invariante features). Catalogus: feature, band/threshold, %groepen.
**Acceptance Criteria**
- [ ] Levert een ranked catalogus van segmentaties (geen degeneratie: count-metrics op 0 uitgefilterd).
- [ ] Markeert segmentaties die op ≥2 munten terugkomen (coin-agnostisch kenmerk).

### 3. Funnel / Rule-Induction (Sequential Covering)
**Status:** Approved. Greedy subregels stapelen op een startsegment; per stap **recall (train+holdout) +
selectiviteit (tick-fire%)** loggen. Tijdens indikken NIET op goed/slecht sturen — pas op het eind. Stop
bij ~20-23-selectiviteit of recall-vloer. Volgorde zoals 20-23: volume → indicator-value → prijs.
**Acceptance Criteria**
- [ ] Toont de trechter per subregel (recall train/holdout + selectiviteit + shrink-factor).
- [ ] Detecteert train/holdout-divergentie (overfit-signaal) en stopt dan.

### 4. Validatie-harness (de overfit-remmen)
**Status:** Approved. Per kandidaat-rule: (a) tijd-holdout (CPCV + purging/embargo); (b) permutatie-test
(p<0,05) + PBO/Deflated-Sharpe; (c) schone random sell-baseline (geen `AVG(coin_moment_sells)`);
(d) **incrementele refire-gate** (`persist_to_brain`: base 20-23 vs base+nieuw, één-positie — de
schaduw-vraag); (e) artefact-guard `|pl|>200%`.
**Acceptance Criteria**
- [ ] Een kandidaat telt pas als KEEPER bij p<0,05 holdout + positief op een 2e munt (of expliciet coin-specifiek).
- [ ] De refire-gate meet de **incrementele** bijdrage bovenop 20-23, niet standalone.

### 5. Rapportage (compact)
**Status:** Approved. Per munt: `{N}/{M} promising groepen | goed/middel/slecht | Σprofit` (+ gem/trade, p).
**Acceptance Criteria**
- [ ] Elke kandidaat-rule wordt in dit vaste format gerapporteerd.

### 6. Kandidaat → rule datamodel
**Status:** Approved. Een KEEPER wordt geschreven als nieuw `rule_number` (subregels in `brain.rules` +
`coin_rule_settings`/`coin_strategies` per munt), met `rules_history`-audit. Propose-only (handmatig groen licht).
**Acceptance Criteria**
- [ ] Een KEEPER is reproduceerbaar als platte AND van subregels en draait door `persist_to_brain` zonder regressie op 20-23.

## Aanbevolen Implementatie Volgorde

1. Feature store uitbreiden (Feature 1) — het substraat.
2. Segmentatie-module (Feature 2) — pysubgroup over de store.
3. Funnel/rule-induction (Feature 3) — wittgenstein/RIPPER of de eigen greedy + trechter-log.
4. Validatie-harness (Feature 4) — CPCV + permutatie/PBO + refire-gate.
5. Rapportage (Feature 5) + datamodel (Feature 6).

## Nieuwe bestanden aan te maken

| Bestand | Type | Feature |
|---|---|---|
| `engine/src/discovery/segment.py` | segmentatie (pysubgroup) | 2 |
| `engine/src/discovery/funnel.py` | rule-induction + trechter | 3 |
| `engine/src/discovery/validate.py` | CPCV + permutatie/PBO + refire-gate | 4 |
| `engine/src/discovery/report.py` | compacte rapportage | 5 |
| `engine/src/discovery/apply.py` | KEEPER → nieuw rule_number (propose-only) | 6 |

## Niet in scope

- **Automatisering / periodiek draaien** → Epic RDA.
- **Meer munten onboarden** → Epic 07 (MEXC-intake). Deze engine wordt pas écht productief met meer munten
  (op 2 munten geen 20-23-grade rule gevonden — het 2-munten-plafond, bevestigd door López de Prado's
  "modelleer de hele universe").
- **Auto-labelen** van promising momenten → Epic RDA (linchpin voor schaal).
