# Seed-and-tighten op NOS — no_candidate buckets (2026-06-16)

## TL;DR

- Seed-and-tighten op de NOS no_candidate set heeft **geen verhandelbare nieuwe regel** opgeleverd. Alle 2429 levensvatbare seeds halen `test_good = 0`.
- De holdout stort volledig in: 0 van de 2429 kandidaten haalt de drempel `test_good >= 3`. De vol-gate-fase verwerkt daardoor 0 entries. Survivors: **0**.
- Oorzaak is methodisch, niet code: de box-definitie (min/max over élke ind×lb×metric op 10 seed-ticks) AND-t ~1200 condities tegelijk. Die "hull" past op de 10 seeds zelf, en nergens anders.
- Snap rate 88/303 = 29% bevestigt het structurele NOS recall plafond (~20-30% van ok-momenten valt op vf=1 ticks; de rest is niet bereikbaar via candidate-ticks). De holdout-puzzel zit dus in de data, niet in de seed-keuze.
- TOP 5 op tie-break is mfi/phobos op `lb=4..6`, alle scale-safe en alle vertaalbaar naar engine-subrules — maar zonder enige test-validatie ongeschikt om toe te passen.
- Conclusie: **niets toepassen.** Vervolgstap is niet meer seeds, maar een andere box-definitie (kleinste discriminerende subset; quantile-band i.p.v. min/max-hull; of meer seed-ticks via ruimere snap-tolerantie).

## Methode

**Doel.** Voor één NOS-coin (id 244, 303 ok-momenten, mediaan-split midpoint 2023-12-13 20:19:39) candidate vf=1 ticks vinden die *lijken* op de coin's ok-moment ticks, om te zien of er een box bestaat die uniek de ok-ticks vangt en niet de overige.

**Seed-selectie.** Per (indicator × calc × lookback × richting) — high/low — de 10 hoogste/laagste waarden over de 8307 vf=1 candidate ticks. Eis: minstens 6 distincte ok-groepen onder die 10 (anders een te smalle groep). Indicatoren: mfi, phobos, momentum, dropvolume, volumeud. Lookbacks: 1..10. Metrics: 31 (`WINDOW_METRIC_KEYS` + a paar lokale). Richtingen: high, low. Totaal geëvalueerd: 2800; viable: 2429.

**Box.** Voor de 10 seed-ticks per kandidaat: voor élke (ind, lb, metric) cel zonder NaN over alle 10 → een condition `[min, max]`. Resultaat is een AND-conjunctie van mediaan 1207 condities (range 1138–1233).

**Re-fire.** Voor élke candidate vf=1 tick: check alle box-condities. Train (eerste helft tijdslijn) vs test (tweede helft). Telling: ok-tick die voldoet = good, niet-ok-tick die voldoet = bad. Single-position dedup: ok-momenten binnen ±180s als één telling. `best_upside` (buy-quality) wordt geregistreerd maar is **niet** onze sell-P&L.

**Vol-gate als laatste.** Pas na de holdout-ratio sortering wordt de live-volume_check toegepast (zelfde poort als de engine-gate). In deze run kwamen er 0 kandidaten door de holdout, dus de gate verwerkte 0.

**Scale-safety uitsluiting (vooraf).** Volumeud × 15 level-metrics (zie `LEVEL_CALCS` in `opt_lib.py`) → bit-identiek geblokkeerd in `recall_seed.py:58-64` en NaN-gemaskeerd op cel-niveau (regel 127-129). De volumeud cache is relatief, de engine leest raw — een drempel op volumeud-level wordt anders silent inert.

## Resultaat

**Sweep-totaal:** 2800 geëvalueerd → 2429 viable → 0 holdout-survivors → 0 met-vol survivors. Looptijd: 269s.

**TOP 10 op (test_ratio desc, test_good desc, n_seed_groups desc, train_good desc).** Alle test_ratio = 0 — leiders zijn dus "schoonste op train", niet "robuust op test".

| # | seed (ind / lb / calc / dir) | #cond | #grp | train g/b | test g/b | test_ratio | met-vol test g/b | met-vol ratio |
|---|---|---|---|---|---|---|---|---|
| 1 | phobos / 6 / reversal_count / low [0,0] | 1198 | 10 | 10/0 | 0/0 | 0 | 0/0 | n/a |
| 2 | phobos / 6 / average_reversal_size / low [0,0] | 1198 | 10 | 10/0 | 0/0 | 0 | 0/0 | n/a |
| 3 | mfi / 4 / max_diff_number / high [11.60, 26.40] | 1215 | 10 | 9/0 | 0/0 | 0 | 0/0 | n/a |
| 4 | mfi / 4 / diff_number_prev_min / low [-23.70, -6.30] | 1218 | 10 | 9/0 | 0/0 | 0 | 0/0 | n/a |
| 5 | mfi / 4 / diff_lowest_value_period / high [11.60, 26.40] | 1215 | 10 | 9/0 | 0/0 | 0 | 0/0 | n/a |
| 6..10 | (variaties op mfi/lb=4-6 en phobos/lb=6) | ~1200 | 9-10 | 8-9 / 0 | 0/0 | 0 | 0/0 | n/a |

**Overfit-markering:** alle 10 (en in feite alle 2429) zijn overfit op de seeds — test_good = 0 over de hele top.

**Per-box statistieken over alle 2429 viable boxes:**
- `n_box_conditions`: mediaan 1207, min 1138, max 1233 — telkens ~1200 AND-condities.
- `train_captures`: mediaan 7 (min 4, max 10) — de hull ligt zo strak dat hij niet eens alle 10 seeds vangt.
- `test_captures`: uniform 0.

**Phobos bands [0, 0]** (#1 en #2) zijn een geldig signaal: alle 10 seeds hadden geen reversal in een 6-tick venster. Maar als knife-edge equality in combinatie met 1196 andere condities: niet generaliseerbaar.

## Adversariële verificatie

Vier verifiers parallel; allen geen blockers gevonden:

**1. Leak-hunt.** Geen futureprice / sell-realiserende velden in de seed-features. Train/test split is strict tijdsbasis (mediaan snapped dt = 2023-12-13 20:19:39). Single-position dedup binnen ±180s voorkomt dubbeltelling van clusters. Geen leak.

**2. Group-diversity.** Top 5 heeft elk 10 distincte ok-groepen onder de seed-ticks (sweep_meta eis: ≥6 groepen). Geen "10× dezelfde event"-trap.

**3. Scale-safety.** `SCALE_UNSAFE_VOLUMEUD` frozenset in `recall_seed.py:58-64` bit-identiek aan `opt_lib.LEVEL_CALCS` (15/15 match). M-grid wordt op die cellen NaN-gemaakt vóór box-bouw. `build_box` slaat NaN-cellen over. TOP 5 gebruikt mfi/phobos — sowieso exempt (`SCALE_NORMALIZED_INDICATORS = {"volumeud"}`). Geen overtredingen.

**4. Engine-translatability.** Alle 5 TOP metrics (`reversal_count`, `average_reversal_size`, `max_diff_number`, `diff_number_prev_min`, `diff_lowest_value_period`) staan in `WINDOW_METRIC_KEYS` (`calc.py:85-94`). Dispatch via `subrule_value` (`calc.py:241-243`) → directe naam-match, `value_condition = {}`, `def1_value = lookback`. Structureel vertaalbaar — maar test_ratio=0 maakt translatie moot.

## Conclusie

**Niets toepassen. Geen overlever.**

Een strakke (min, max)-hull-box op 10 seed-ticks in 1 coin is per definitie een overfit-hull: hij past op die 10 ticks en nergens anders. Dat is precies wat het lab laat zien — train 9-10/0 → test 0/0 voor élke kandidaat, ongeacht seed-keuze.

De seed-keuze is dus niet het probleem; de box-definitie is het probleem. Een box met ~1200 AND-condities op 10 punten heeft 0 vrijheidsgraden om te generaliseren. Twee mogelijke vervolgrichtingen (niet in deze ronde):

- **Pruned box.** Per seed-set: kies de K meest discriminerende condities (bv. K = 3–8) op basis van separatie tegen een random non-ok controlegroep. Verlaagt #AND drastisch, vergroot test-coverage.
- **Relaxed hull.** Vervang `(min, max)` door `(mediaan ± k·MAD)` of `(p10, p90)`. Geeft de box "lucht" rond de seed-cluster.
- **Meer seeds.** 10 is hard te smal. Met ruimere snap-tolerantie (bv. ±300s) en/of meer coins zou de seed-basis 30-50 ticks kunnen worden.

**TOP 5 candidates voor archief** (niet toepassen — voor latere referentie als de box-definitie verandert):

| seed | subrulename | value_condition | def1_value | band |
|---|---|---|---|---|
| phobos / 6 / reversal_count / low | `reversal_count` | `{}` | 6 | [0.0, 0.0] |
| phobos / 6 / average_reversal_size / low | `average_reversal_size` | `{}` | 6 | [0.0, 0.0] |
| mfi / 4 / max_diff_number / high | `max_diff_number` | `{}` | 4 | [11.60, 26.40] |
| mfi / 4 / diff_number_prev_min / low | `diff_number_prev_min` | `{}` | 4 | [-23.70, -6.30] |
| mfi / 4 / diff_lowest_value_period / high | `diff_lowest_value_period` | `{}` | 4 | [11.60, 26.40] |

Alle 5 zijn scale-safe, alle 5 structureel vertaalbaar naar engine-subrules. Geen van de 5 generaliseert.

## Bekende beperkingen

- **Alleen NOS, één coin.** Coin 244 (geselecteerd als grootste NOS no_candidate bucket). Geen DOGEAI sanity-check in deze ronde. Cross-coin generalisatie onbekend.
- **Holdout binnen één coin.** Mediaan-tijdsplit op één coin's seizoenen-verloop is ruwe approximatie van out-of-sample. Echte holdout = nieuwe coin.
- **Box >> principe 1.** ~1200 AND-condities is een orde te veel. Een "geminimaliseerde box" stap (kleinste discriminerende subset) is een natuurlijke vervolgstap, niet in scope hier.
- **`best_upside` is buy-quality.** Upside vanaf de candidate-tick, niet onze actuele sell-P&L. Een box die `best_upside` raakt is nog geen winstgevende trade — sell-model bepaalt dat.
- **Snap rate 29% is plafond, geen tekortkoming.** 88 van 303 ok-momenten landden op een vf=1 candidate-tick binnen ±180s. De andere 215 zijn fysiek niet in de candidate-set → buiten bereik voor élke seed-strategie die alleen op vf=1 ticks zoekt.

## Bronnen

- Harness: `/Users/daanvantongeren/Documents/Sites/brain/engine/src/recall_seed.py` (NEW)
- Sweep JSON: `/Users/daanvantongeren/Documents/Sites/brain/engine/out/opt/recall_seed_nos.json`
- Stdout: `/tmp/seed_run.out`
- Engine ref: `/Users/daanvantongeren/Documents/Sites/brain/engine/src/calc.py` L85-94 (WINDOW_METRIC_KEYS), L241-243 (dispatch)
- Scale-safety ref: `/Users/daanvantongeren/Documents/Sites/brain/engine/src/opt_lib.py` L57-61 (`scale_unsafe`), `LEVEL_CALCS` (15 metrics)
- Context: memory `nos-recall-no-candidate-ceiling.md` (~20% recall plafond)
