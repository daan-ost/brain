# Recall no_candidate — volume-vraag of verhandelbaarheids-vraag?

**Datum:** 2026-06-16
**Scope:** NOS (244), 199 promising-groepen, 143 in `blocker='no_candidate'`. DOGEAI (2525) cross-check: 6/107.
Read-only op `brain` — er is **niets toegepast**; alle detectoren/gates zijn PROPOSALS. In-sample, 2-coins,
geen echte out-of-sample data (NOS en DOGEAI overlappen niet in tijd).
**Artefacten:** `engine/src/recall_nocand_diag.py` (bucket-split), `engine/src/recall_altgate.py` (live-gate
full re-fire + holdout-venster), `engine/out/opt/recall_nocand_244.json`. Verificatie via een 9-agent
adversariële workflow (`engine/out/recall_nocand_workflow.js`) — alle cijfers langs ≥2 onafhankelijke
paden gereproduceerd.

---

## TL;DR

1. **De 143 NOS no_candidate-groepen splitsen in (a) 19 alignment / (b) 50 sub-threshold / (c) 74 echt-afwezig**
   (kwaliteit goed=18/45/62). Drie onafhankelijke agents reproduceren dit exact; `slecht` zit bijna
   uitsluitend in bucket c (2 van de 143).
2. **`volume_found` is geen aparte ingestion-bron** maar de *legacy* uitkomst van exact dezelfde
   `check_volumeud_3` die de brain-engine óók als `volume_check`-subrule draait (`functions_br.php:2353-2358`,
   geschreven op signal_tick = check_date−5s). De brain-eigen volume_check is een bijna-perfecte **superset**
   van de legacy-vlag (dekt 99,2% van de vf=1 ticks; gate↔subrule divergeren 0,8%). De legacy vf=1-vlag =
   brain-volume_check AND iets strikters dat in de import zit.
3. **De goedkoopste "alternatieve volume-trend" is de candidate-gate loskoppelen van de geïmporteerde vlag
   en de live `volume_check` de gate laten zijn (D1).** Dat ontsluit **25 van de 143** (22 goed) — NIET de 52
   "volume_check-slaagt"-groepen, want de rule moet óók vuren (feature-subrules) en single-position dedup dekt
   af. Pooled: **+56 goed / +45 slecht, ratio 1,438 → 1,403** (verwatering). Bevestigd door 3 onafhankelijke
   re-fires (zelfde cand-sets, zelfde exec, na ronden op 3 decimalen identiek aan `coin_fires`).
4. **Dit is overwegend GEEN volume-vraag.** Van de 50 sub-threshold-groepen vuren er maar **7 echt volledig**
   op de vf=0 tick; 43 blijven geblokkeerd op niet-volume feature-subrules. Bucket c (74, de grootste, 57 goede
   momenten) heeft geen verhandelbaar volume — onder de live-gate 6/74 gevangen. Dat is een
   **verhandelbaarheids-/data-armoede-vraag**, niet op te lossen met een betere volume-detector.
5. **Holdout: de recall-winst is eenmalig in-sample, niet herhaalbaar.** Alle 25 NOS-catches zitten in het
   vroege NOS-venster (nov '23–feb '24); out-of-sample 0 catches en de extra-trade-ratio stort in (train
   1,58 → test 0,75; train 1,46 → test 1,00). **Aanbeveling: de vf=1-gate NIET vervangen.**

---

## STAP 0 — Waar komt `volume_found` vandaan (de kern-vraag)

De candidate-gate in `rule_engine.fires` evalueert alleen `volume_found=1` ticks. `volume_found` wordt door
de legacy geschreven (`functions_br.php:2353-2358`) zodra `check_volumeud_3` destijds vuurde, op de tick
check_date−5s. De brain-engine herberekent diezelfde `check_volumeud_3` als de `volume_check`-subrule met de
huidige per-rule settings (`_VOLUME_OVERRIDES` in `volume.py`). Dus de "alternatieve volume-trend" is in de
kern: **de gate loskoppelen van de geïmporteerde vlag en op de live volume_check vertrouwen.**

Agreement-baseline (over alle 144.589 NOS volumeud-ticks): op de 8.307 vf=1 ticks slaagt de brain-volume_check
voor minstens 1 rule in **99,2%** (8.241); divergentie vf=1∧brain-faalt = 66 (0,8%). De brain-detector is dus
een bijna-superset. Op de 136.282 vf=0 ticks slaagt hij voor **6.556 (4,81%)** → de candidate-populatie zou
bijna verdubbelen (8.307 → 14.797). De legacy-vlag is dus structureel STRIKTER, geen jitter rond bestaande
candidates (slechts 20,5% van de passerende vf=0 ticks ligt binnen 120s van een vf=1; 59,9% ligt ≥300s weg).

---

## STAP 1 — De bucket-verdeling van de 143 (NOS)

`recall_nocand_diag.py 244`. Een groep is `no_candidate` als geen vf=1 tick binnen het groep-venster ±SNAP_TOL
(180s) ligt en de groep niet direct/covered is.

| Bucket | n | Kwaliteit (group-max best_upside) | Betekenis | Fix |
|---|---|---|---|---|
| **a — alignment** | **19** | 18 goed, 1 middel | vf=1 tick net buiten ±180s, binnen 300s | snap-bereik, geen nieuw volume |
| **b — sub-threshold** | **50** | 45 goed, 5 middel | geen nabije vf=1, maar live volume_check slaagt | live-volume-gate |
| **c — absent** | **74** | 62 goed, 10 middel, 2 slecht | geen vf=1, volume_check faalt overal | niet via volume |

`nearest-vf1`-histogram (s): 0 binnen 180s (per definitie), 19 in 180–300, 15 in 300–600, 29 in 600–1800,
11 in 1800–3600, **69 boven 3600** (echt geïsoleerd).

**Robuustheid:** b=50 en c=74 zijn **invariant** onder SNAP_TOL 120→180 (alleen de a/c-grens schuift, a 27→19);
alleen `a_alignment` is gevoelig voor de ALIGN-tolerantie (ALIGN 60→0, 300→19, 600→34). De volume-sub-thresh-kern
is stabiel. DOGEAI: 6 no_candidate (a:2/b:1/c:3) — minuscuul, want ~84% van DOGEAI zit al op vf=1. **Het
no_candidate-plafond is NOS-structureel, niet coin-generiek.**

---

## STAP 2 — Drie alternatieve detectoren, gemeten

| Detector | Ontsluit (van 143) | ≥ 1/3 (48)? | Flood (extra vf=0 candidate-ticks) | Echte verhandelbare winst |
|---|---|---|---|---|
| **D1 live volume_check-gate** (drop legacy vf=1) | **25** caught (22 goed) | nee | +6.490 (8.307→14.797) | full re-fire bevestigd: 25 |
| **D3 relatief-volume spike** (ratio = value/mediaan(‖value‖,30) ≥ 5) | 59 candidate (52 goed) | ja (op papier) | +9.744 vf=0 ticks (>verdubbeling) | **NIET met full re-fire gemeten** ⚠ |
| **D2 snap-fix** (SNAP_TOL 180→300, géén +5s) | 19 (18 goed) | nee | **nul flood** | 0 vuren onder huidige rules |

**Kritiek:** D3's "59" is een candidate-gate-bovengrens — dezelfde soort overschatting als het diag-"unlock"-
getal 52. De D1-bevinding toont dat 52 volume-signaal-passes → slechts 18 echte fires (34 vallen weg op
feature-subrules). D3's échte winst ligt dus vermoedelijk in dezelfde orde als D1 (~25), niet 59. **De
feature-subrules, niet de volume-gate, zijn de bindende constraint.** D2 voegt geen trades toe; het verschuift
19 groepen van het structurele no_candidate-plafond naar de loosenbare feature-bucket (waar `recall_shadow` ze
kan oppakken) — een meet-/classificatie-fix, geen engine-fire-wijziging. De +5s align (`align.py`) NIET
toevoegen: verlaagt 19→18.

---

## STAP 3 — Kwaliteit-gate (D1, de enige met full re-fire + holdout)

`recall_altgate.py` (lean streaming; reproduceert `coin_fires` exact op de vf=1-gate: DOGEAI 177/120/475,
NOS 122/88/384).

| | vf=1 gate | live-gate | delta |
|---|---|---|---|
| DOGEAI | 177g/120b (1,475) | 200g/137b (1,460) | +23g/+17b, **0/6** no_candidate, −18 goed verloren |
| NOS | 122g/88b (1,386) | 155g/116b (1,336) | +33g/+28b, **25/143** (22 goed), −11 goed verloren |
| **Pooled** | 299g/208b (**1,438**) | 355g/253b (**1,403**) | **+56g/+45b**, +191 exec |

- **Marginale kwaliteit nieuwe trades = 56/45 = 1,24**, onder de staande 1,438 → netto precisie-verwatering.
  Haalt het succescriterium #goed≥2×#slecht **niet** (1,24 < 2,0).
- **Cross-coin asymmetrisch:** DOGEAI 0/6 gevangen, +79 exec puur ruis; NOS 25/143 (22 goed, 0 slecht onder de
  catches zelf). De live-gate is een NOS-recall-instrument, geen DOGEAI-verbetering — coin-breed toepassen
  sleept DOGEAI's bad-flood mee.
- **Holdout (walk-forward, coins temporeel disjunct):** de extra-trade-ratio stort out-of-sample in
  (split A train 1,58 → test 0,75; split B train 1,46 → test 1,00). Alle NOS-catches in het vroege venster;
  out-of-sample 0. **Eenmalig in-sample, niet herhaalbaar.**
- **~26 verloren-goede entries** (DOGEAI 18 + NOS 8) door dedup-verschuiving: je wint trades maar verliest ook
  bestaande goede entries.

---

## STAP 4 — Eindoordeel

**Niet de vf=1-gate vervangen.** De live-gate is een netto bad-flood (1,24 < 2,0 in-sample, stort in
out-of-sample, alleen ruis op DOGEAI). D3 is veelbelovender op recall maar **niet door de full re-fire
gevalideerd** — de "59" wordt door de feature-subrules grotendeels weggefilterd.

**De kern is geen volume-vraag.** Voor bucket b (50) is volume in 34/50 NIET de bindende constraint
(feature-subrules blokkeren). Voor bucket c (74, 57 goede momenten) is er geen verhandelbaar volume — een
verhandelbaarheids-/data-armoede-vraag, consistent met het bekende ~20% NOS-recall-plafond en de
2-coin-/r21-wall-constraint.

**Aanbeveling:**
1. **Geen globale candidate-gate-swap.** Niets toepassen.
2. Als er ooit iets gebeurt: **chirurgisch** — een gerichte NOS-no_candidate-catch op de ~22 schone goede
   groepen met een quality-tightening erop, en **eerst een D3 full re-fire** (`AltGateEval` met cand =
   D3-passerende ticks) om de échte precisie-kost te meten vóór één regel verandert.
3. Het grootste deel valt in **bucket c (echt-afwezig)** → meer-data / verhandelbaarheids-probleem, niet
   volume. De live-gate raakt dit niet (6/74).
