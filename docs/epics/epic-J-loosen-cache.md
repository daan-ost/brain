# EPIC J: Auto-loosen (rq2) caching + incrementeel

**Status:** Approved — BOUW-KLAAR gemaakt 2026-06-27 (zie **Build-ready details** A-D: exacte regels, cache +
T_safe-correctie t.o.v. Epic I, verplichte test). Te bouwen **ná Epic H** (H zet de regime-versie-in-cache-
discipline die J's rq2-cache moet erven) en ná Epic I (**nu gebouwd** → de incrementele machinerie staat klaar).
· **Datum opgesteld:** 2026-06-26 · Refines: schaalplan ([[../findings/optimize-scaling-plan-2026-06-25.md]]) ·
Volgorde: [[../findings/onboarding-batch-en-bouwvolgorde-2026-06-27.md]]

## Why this exists

`auto-loosen` (rq2_earlier) is de laatste niet-versnelde stap in de rule-precision routine. Hij draaide in
run #79 **>15 min** op 4 munten op 100% CPU en moest handmatig gekild worden. Het schaalplan versnelde
alleen **rq1 (aanscherpen)** — dat werkt op de voorgerekende long-tabel (alleen trade-momenten). **rq2
(versoepelen)** heeft de status van élke subregel op élk kandidaat-moment nodig (ook de net-niet-momenten
die geen trade werden), en die staan niet in die tabel → het valt terug op per-tick herberekenen via
`DiagEngine`, net zo duur als een koude refire. Deze epic geeft rq2 dezelfde behandeling als de refire:
memoïseren op (rule + data) + incrementeel het nieuwe staartje, achter een orakel-test.

## The known gap (huidige staat — gemeten)

De hete lus (`rq2_earlier.py:88-90`):
```python
cands = eng.candidates()
for T in cands:
    st = eng.subrule_status(RULE, T)     # voor ELKE tick: waarde van ELKE subregel + welke faalt
```
Voor elk kandidaat-moment wordt de volledige subregel-status berekend (welke subregel is de "enige
blokkade" en met welke waarde). Dat is de zware per-tick vorm-berekening (skewness/std/…), bij discovery-
rules over álle ticks. Daarna is de rest goedkoop: `bad_edge_loosen` (numpy over de verzamelde waarden) +
voorstel-opmaak. **De kost zit volledig in het bouwen van de `sole`-dict** (de net-niet-momenten per
subregel).

## Key insight

De auto-loosen-analyse hangt af van **(a) de rule-definitie, (b) de indicator-reeksen, en (c) de promising-
periodes** (voor de "eerder-in-een-beweging vs nieuwe-kans"-splitsing via `period_of`). **NIET van de
verkoop-instellingen.** Dus exact dezelfde memoïsatie-premisse als de fires-cache:
- Een sell-tuning-refire of een tweak aan een ándere rule → de `sole`-analyse van deze rule is identiek →
  uit cache.
- Dagelijkse nieuwe data is aangroei → de net-niet-momenten in de oude data zijn stabiel (subregels kijken
  alleen terug) → alleen de nieuwe kandidaat-ticks scannen (hergebruik `T_safe` uit Epic I).

Het cachebare artefact = de `sole`-dict: per subregel-index de goed/middel/slecht-waardenlijsten + de
`moments` (dt, value, best_upside, cls, period). Daaruit draait `bad_edge_loosen` + de voorstellen goedkoop.

## Existing code (reference)

| Bestand:regel | Wat |
|---|---|
| `engine/src/rq2_earlier.py:88-104` | de hete per-tick `subrule_status`-scan die de `sole`-dict bouwt (DE kost) |
| `engine/src/rq2_earlier.py:31-56` | `bad_edge_loosen` — goedkoop, draait op de verzamelde waarden |
| `engine/src/rq2_earlier.py:106-149` | voorstel-vorming uit `sole` (goedkoop) — earlier-in-move vs new opportunity |
| `engine/src/rq2_earlier.py:59-70` | `DiagEngine(sym)` + `promising_verdicts(sym)` + executed fires per periode |
| `engine/src/opt_diag.py` | `DiagEngine`, `subrule_status`, `candidates`, `best_upside`, `_cls`, `promising_verdicts` |
| `engine/src/fires_cache.py` | `rule_fires_fingerprint` + per-rule parquet-cache + `_win_hash` — patroon om te hergebruiken |
| `engine/src/routines.py` (auto-loosen step) | roept rq2 aan in de rule-precision set, alleen met `--apply` |
| Epic I `T_safe`/prefix-checksum-machinerie | hergebruiken voor het incrementele pad |

## Decided

| # | Vraag | Beslissing |
|---|---|---|
| 1 | Bouwvolgorde | **Ná Epic I.** Hergebruik diens prefix-checksum + `T_safe`-grens; niet twee keer bouwen. |
| 2 | Cache-sleutel | Per (coin, rule): fingerprint van rule-def + indicators + coin_periods. **NIET** sell-instellingen (die raken de loosen-analyse niet). Hergebruik de `rule_fires_fingerprint`-bouwstenen + een coin_periods-checksum. |
| 3 | Wat cachen | De `sole`-dict (per subregel: goed/middel/slecht-waarden + moments). De goedkope `bad_edge_loosen` + voorstel-stap draait altijd vers uit de cache — die is snel en houdt de output flexibel. |
| 4 | Bit-identiek | De gecachte/incrementele loosen-voorstellen MOETEN exact gelijk zijn aan een volledige verse scan. Orakel-test verplicht. |
| 5 | Coin-set | rq2 hardcodeert nu `[DOGEAI, NOS]` (`rq2_earlier.py:25`). Trek dit gelijk met `optimize_coin_ids()` (zoals de rest na het schaalplan) zodat het N-munt meegaat. Kleine, losse fix die hierin past. |
| 6 | Prioriteit | **Laag.** Draait alleen in de geplande `--apply`-run; versoepelen voegt trades toe → de toeval-toets wijst veel af op weinig munten. Waarde stijgt met meer munten. Bouw na Epic I, niet eerder. |

## Build-ready details (geverifieerd 2026-06-27 tegen de huidige code — leidend bij conflict met de regels hierboven)

> Epic I is intussen GEBOUWD en week op één punt af van het oorspronkelijke plan: **T_safe is daar vervallen**
> (fires zijn look-back-only → een prefix-tick is stabiel). Voor J ligt dat **anders** — zie sectie C.

### A. De hete lus afsplitsen (Feature 1) — exacte regels
`rq2_earlier.py`: de dure scan staat op **regel 86-104** (`cands = eng.candidates()` → per `T`:
`eng.subrule_status(RULE, T)` + `eng.best_upside(T)` → bouw de `sole`-dict). De goedkope rest (regel 106-149) =
`bad_edge_loosen` (regel 31-56) + de earlier/newopp-split. Splits `analyse_symbol` (regel 59-149) in:
- **`build_sole(sym, rule, eng, frm=None, to=None)`** — ALLEEN de scan (regel 86-104) → de `sole`-dict
  (`defaultdict`: per subrule-index `{goed:[], slecht:[], middel:[], moments:[{dt,value,best_upside,cls,period}]}`).
  Dit is het cachebare artefact.
- **`proposals_from_sole(sym, rule, sole, subs, fires_by_period)`** — de goedkope rest (regel 106-149). Leest de
  **huidige** executed fires (regel 67-69: `SELECT datetime, period_id, best_upside FROM coin_fires WHERE rule=..
  AND is_executed=1`) **vers** — die hangen wél van sell af (executed-vs-shadow), maar het is de goedkope stap →
  altijd vers, nooit cachen. Zo blijft de output bit-identiek terwijl alleen de scan gecachet wordt.
- **N-munt:** vervang `SYMS = [o.DOGEAI, o.NOS]` (regel 25) door `o.optimize_coin_ids()` (env `OPTIMIZE_COINS`),
  net als rq1/de rest na het schaalplan.

### B. De cache — nieuw `loosen_cache.py`, spiegelt `fires_cache.py`
Cache de `sole`-dict per (coin, rule) (JSON-serialiseerbaar → parquet/JSON), met **atomic write +
venster-/cross-coin-isolatie** (zelfde valkuilen als fires-cache, commit `5fbfb59`: `_win_hash`/`_rule_path`-patroon).
**Fingerprint (Beslissing 2), concreet:** hergebruik de bouwstenen van `fires_cache.rule_fires_fingerprint(sym,
rule, frm, to)` (rule-def + indicators + min_volume) PLUS een **coin_periods-checksum** (rq2 gebruikt `period_of`
→ de promising-periodes, die persist elke refire herbouwt):
`SELECT COUNT(*) n, COALESCE(SUM(CRC32(CONCAT(period_from,'|',period_to))),0) cx FROM coin_periods WHERE trading_symbol_id=%s`.
**NIET** sell-instellingen. → **Feature 2a (de grote winst):** sell-tuning-refire wijzigt alleen `sl_settings` →
fingerprint gelijk → cache-hit → `build_sole` overgeslagen. Dat maakt de herhaalde keten-runs snel.

**Regime-versie (J komt ná Epic H):** als H's actieve-periode-filter óók de rq2-kandidaatset gate't
(aanbevolen voor consistentie — anders versoepelt rq2 op dode-periode-momenten die we nooit traden), dan
filtert `build_sole` de kandidaten op actief (via `regime.active_filter`/`is_active`) en MOET de loosen-cache-
fingerprint de **regime-versie** bevatten (`regime.regime_ver(sym)`, zie epic-H Build-ready sectie B). Dit is
precies waarom H vóór J gebouwd wordt — J erft de regime-versie-in-cache-discipline. Beslis de exacte scope
(gate't het filter de kandidaatscan?) samen met H Feature 3.

### C. Incrementeel (Feature 2b) — T_safe is hier WÉL nodig (≠ Epic I)
`best_upside(T)` (via `DiagEngine.best_upside`, `opt_diag.py:54`) = max excursie over **[T, T+FORWARD_MINUTES]** =
**look-forward**. Een kandidaat binnen `FORWARD_MINUTES` (60) vóór `last_max` heeft een best_upside die nog niet
vol was → diens `cls` (goed/slecht via `_cls`) kan met nieuwe data kantelen. Dus de sole-prefix is alleen stabiel
t/m **`T_safe = last_max − FORWARD_MINUTES`**.
- **Incrementeel pad:** laad de prefix-sole (kandidaten ≤ `T_safe`), scan `eng.candidates(frm=T_safe, to=new_max)`
  (de `candidates`-methode neemt al een venster — `opt_diag.py:130`), merge de per-subrule value-lists + moments.
  De nieuwe prefix-cache dekt t/m `new_max − FORWARD_MINUTES`.
- **Hergebruik uit Epic I (nu gebouwd):** `fires_cache.prefix_indicators_checksum(sym, up_to)` (data-prefix-
  checksum), `fires_cache.series_max_datetime(sym, to)` (de `new_max`-grens), en de
  `coin_refire_state.last_max_datetime`/`prefix_checksum`-machinerie — `persist_to_brain.py:181-194` is het
  referentie-patroon voor de incrementeel-vs-volledig-keuze. Prefix-mismatch / rule-wijziging / `--full` →
  verse volledige scan van die rule.

### D. Orakel-test (verplicht) — `test_loosen_cache.py`
- cold-scan == warm-cache == verse `analyse_symbol`, **bit-identieke voorstellen** (zelfde `subrule_index`,
  `loosen_bound`, `new_threshold`, `admitted_good`, `new_bad`).
- sell-change (coin_strategies) → fingerprint **ongewijzigd**; rule-def / min_volume / **coin_periods**-change →
  **veranderd**.
- split-reeks: incrementeel == volledig, **met een kandidaat binnen 60 min van de split** (bewijst dat de
  `T_safe`-grens de best_upside-kanteling correct opvangt). Spiegelt `test_incremental_refire.py` +
  `test_fires_cache.py` (cross-venster-isolatie, commit `5fbfb59`).

## Features (3)

### 1. N-munt + sole-analyse isoleren
**Status:** Approved
Trek rq2 op `optimize_coin_ids()` i.p.v. de hardcoded `[DOGEAI, NOS]`. Splits `analyse_symbol` zo dat het
bouwen van de `sole`-dict (de dure scan) een aparte, cachebare functie wordt, los van de goedkope
voorstel-vorming. Nog geen cache — alleen de scheiding + N-munt.
**Acceptance criteria**
- [ ] rq2 draait over alle `optimize_coin_ids()`-munten (env `OPTIMIZE_COINS` werkt).
- [ ] `build_sole(sym, rule)` (dure scan) en `proposals_from_sole(...)` (goedkoop) zijn gescheiden; output
      van de huidige `analyse_symbol` byte-identiek.

### 2. Per-rule cache + incrementeel
**Status:** Approved
Memoïseer de `sole`-dict per (coin, rule) op de fingerprint uit Beslissing 2. Cache-hit → laad `sole` van
schijf, sla de scan over. Bij aangroei (Epic I-prefix match) → laad de prefix-`sole`, scan alleen de
kandidaat-ticks ≥ `T_safe`, merge. Prefix-mismatch of rule-wijziging → verse scan van die rule.
**Acceptance criteria**
- [ ] Ongewijzigde rule + data → cache-hit, geen per-tick scan (meet: seconden i.p.v. minuten).
- [ ] Sell-tuning-wijziging invalideert de loosen-cache NIET.
- [ ] Rule-def-wijziging invalideert alleen DIE rule's loosen-cache.
- [ ] Incrementeel (aangroei) scant alleen ticks ≥ `T_safe`; merge = bit-identiek aan verse volledige scan.
- [ ] Atomic write + venster-/cross-coin-isolatie (zelfde valkuilen als fires-cache, zie commit `5fbfb59`).

### 3. Orakel-test (verplicht)
**Status:** Approved
`test_loosen_cache.py`: bewijs dat (a) cold-scan == warm-cache == verse scan bit-identiek (zelfde
voorstellen: subrule_index, loosen_bound, new_threshold, admitted_good, new_bad), (b) een sell-wijziging
de fingerprint NIET verandert, een rule/data-wijziging WEL, (c) incrementeel (split-reeks) == volledig.
**Acceptance criteria**
- [ ] cold == warm == direct op ≥1 munt/rule, bit-identieke voorstellen.
- [ ] sell-change → fingerprint ongewijzigd; rule/min_volume/coin_periods-change → veranderd.
- [ ] split-reeks incrementeel == volledig.

## Aanbevolen implementatie-volgorde

Volg per stap de **Build-ready details** (sectie A-D) voor exacte regels + de T_safe-correctie.
1. **Feature 1** (sectie A) — N-munt (`optimize_coin_ids()`) + `build_sole`/`proposals_from_sole`-split.
   Bestaande `analyse_symbol`-output bit-identiek houden.
2. **Feature 3 — test eerst** (sectie D) — orakel tegen de huidige verse scan (cache-tak faket eerst = vers).
3. **Feature 2a memoïsatie** (sectie B) — per-rule `loosen_cache.py` op de fingerprint (rule-def + indicators +
   coin_periods, + regime-versie als H's filter de kandidaatscan gate't). Test cold==warm; sell-change → hit.
4. **Feature 2b incrementeel** (sectie C) — `T_safe = last_max − FORWARD_MINUTES` (best_upside is look-forward!),
   hergebruik Epic I's `prefix_indicators_checksum`/`series_max_datetime`. Test split==volledig met een
   kandidaat binnen 60 min van de split.
5. **Meet** op NOS: een ongewijzigde-data run = seconden; log "loosen uit cache / N tail-ticks gescand".

## Nieuwe bestanden aan te maken

| Bestand | Type | Feature |
|---|---|---|
| `engine/src/test_loosen_cache.py` | orakel-vangnet (plain assert) | 3 |
| (uitbreiding) `engine/src/rq2_earlier.py` | `build_sole`/`proposals_from_sole` split + N-munt + cache-pad | 1/2 |
| (nieuw of uitbreiding) `engine/src/loosen_cache.py` | per-rule `sole`-cache (spiegelt `fires_cache`) | 2 |

## Out of scope

- **Vectorisatie van `subrule_status`** — incrementeel + memoïsatie maken de per-tick-kost irrelevant voor
  de dagelijkse run; vectorisatie blijft geparkeerd (zelfde keuze als Epic I).
- **De loosen-voorstellen daadwerkelijk toepassen/verfijnen** — dit gaat over SNELHEID van de analyse, niet
  over de apply-poort of de toeval-toets (die staan en blijven leidend).
- **Auto-apply / rq1** — al versneld door het schaalplan.

## Open questions (for Daan)

1. Moet auto-loosen N-munt draaien in de dagelijkse routine, of blijft het bij de "actieve" munten
   (`coin_age_class`)? (Zelfde cadans-vraag als bij de rest van de routine.)
2. Is rq2 nog nuttig op het huidige 2-4-munt-universe, of pas vanaf ~10 munten? (Bepaalt of deze epic nu
   of pas later gebouwd wordt — de bouw-volgorde-beslissing zegt: ná Epic I.)
