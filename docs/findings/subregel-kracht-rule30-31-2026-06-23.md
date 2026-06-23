# Geïsoleerde subregel-kracht op rule 30/31 — methode + bevindingen (2026-06-23)

**Opdracht (Daan):** zoek subregels die slechte trades wegsnijden bij rule 30/31 (firehose-rules),
met behoud van de goede. "Elke 10 slechte eruit met behoud van de goede is winst." Twee nevendoelen:
(1) de *voorraad* vindbare subregels vergroten (ook als een subregel nu niet meteen nut heeft — later
mogelijk wel), en (3) de meet-methode vastleggen: houd de basis aan, meet hoeveel één losse subregel
*separaat* aan slecht tegenhoudt.

## De methode — `engine/src/subrule_power.py` (READ-ONLY)

Per rule R nemen we zijn **executed trades** (met gerealiseerd sell-resultaat `profit_loss`) als basis-set.
goed = pl≥3, slecht = pl<0, middel ertussen (`opt_lib`). Voor elke kandidaat
**(indicator × window-metric × lookback × kant)**:

1. Plaats de drempel op de **bad-edge**: net voorbij de meest extreme *goede* trade in de leerperiode
   → good_keep = 100% in de leerperiode (Daans "met behoud van de goede").
2. Tel hoeveel *slechte* trades daarbuiten vallen = de **kracht** van die ene subregel.

Features worden op de **rauwe engine-reeks** berekend via `RuleEngine._vals` (leak-vrij, as-of T) —
exact het pad dat de engine zelf gebruikt, dus een gevonden drempel is direct als subregel inzetbaar
(geen cache/scale-mismatch).

### Eerlijkheids-lagen (na een critical-eye review — anders meet je toeval)

- **Walk-forward (5 splits 0.60–0.80):** drempel uit de vroege leerperiode, gemeten op de late
  testperiode; rapporteer de mediane slecht-drop + hoe vaak good_keep 100% bleef (stabiliteit). Eén
  vaste 70/30-grens is te wiebelig want de drempel is een min/max (één uitschieter-goede verzet 'm).
- **Min. goede in test (≥4):** anders is "0 goede verloren" betekenisloos (je toetst op ~3 punten).
- **Toeval-toets op de testperiode:** schud goed/slecht-labels, leid de drempel opnieuw af, meet de
  test-drop. Bonferroni-correctie over alle geteste hypothesen (×1206).
- **Dedup relvol/volumeud:** relvol = volumeud ÷ constante → voor schaal-invariante metrics identiek;
  niet dubbel tellen. relvol blijft alleen voor LEVEL-metrics (daar is het de schaal-vrije variant).

## Bevinding 1 — de bindende rem is WINNAARS-schaarste, niet de feature

Rule 30/31 zijn verliezer-zwaar, dus er zijn te weinig *goede* trades om te valideren dat een filter
generaliseert:

| rule | munt | trades | goed | slecht | goede in test-30% |
|---|---|---|---|---|---|
| 30 | DOGEAI | 228 | 16 (7%) | 134 (59%) | ~5 |
| 30 | NOS | 158 | 11 (7%) | 77 (49%) | **~0 → niet toetsbaar** |
| 31 | DOGEAI | 194 | 10 (5%) | 110 (57%) | **~1 → niet toetsbaar** |
| 31 | NOS | 195 | 22 (11%) | 102 (52%) | ~3 (< 4) |

Gevolg: **geen enkele kandidaat haalt het STABIEL-label** (good_keep 100% op ~alle splits + ≥2 slecht-weg
op *beide* munten), omdat minstens één munt te weinig winnaars heeft om de holdout te draaien. Dit is het
2-munten/data-plafond, maar scherper: het probleem is te weinig **winnaars per rule**, juist bij de rules
waar je het meeste wilt filteren.

## Bevinding 2 — binnen DOGEAI rule 30 zijn er wél robuuste kandidaten (de voorraad)

Op DOGEAI rule 30 (genoeg winnaars) snijden meerdere losse subregels op **alle 5 walk-forward splits**
~10–12 verliezers weg met **0 winnaars verloren**, rauwe toeval-p ≈ 0.01–0.05:

| indicator | metric | lb | kant | mediaan slecht-weg (schoon/geldig) | rauwe p |
|---|---|---|---|---|---|
| price | skewness | 5 | b_max | 12 (5/5) | 0.015 |
| vzo | skewness | 10 | b_min | 11 (5/5) | 0.012 |
| price | diff_highest_value_period | 20 | b_max | 11 (5/5) | 0.027 |
| volumeud | diff_percentage_prev_min | 20 | b_min | 12 (5/5) | 0.032 |
| volumeud | consecutive_increases | 10 | b_max | 10 (5/5) | 0.040 |
| phobos | average_reversal_size | 5 | b_max | 9 (5/5) | 0.045 |

**Caveat:** na Bonferroni (×1206 doorzochte hypothesen) is geen enkele individueel significant, en NOS
kan ze niet bevestigen. Dit zijn dus **voorraad-kandidaten** (bewaren, hertoetsen bij meer data/munten),
geen direct te activeren cross-coin keepers. Voor een *DOGEAI-specifieke* subregel (zoals 20-23 ook
per-munt-instellingen hebben via `coin_rule_settings`) zijn price/vzo-skewness en
volume-`diff_percentage_prev_min` de sterkste kandidaten.

## Vervolg (geadviseerd, niet uitgevoerd)

1. **Meer winnaars per validatie**: meet de subregel-kracht op `coin_moment_sells` (sell-engine over álle
   promising momenten) i.p.v. alleen de executed rule-fires → veel grotere goed/slecht-grondwaarheid.
   Dit is Daans "minimale basis"-variant (volume-poort aan, dan elke subregel los meten).
2. **Meer munten** (Epic 07) — het echte plafond blijft.
3. De voorraad-kandidaten bewaren in een propose-only set en automatisch hertoetsen in de routine.

Artefact: `engine/src/subrule_power.py`. Verifieer altijd een toepassing via een echte engine-refire
(`add_tuned_subrules.py` → `persist_to_brain.py` → ratio) — een walk-forward-cijfer is nog geen keeper.
