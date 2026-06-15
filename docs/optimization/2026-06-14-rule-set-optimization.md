# Rule-set optimalisatie — rapport (dry run, 14 jun 2026)

> **Read-only analyse.** Dit rapport stelt getallen voor. Er is **niets** gewijzigd in de
> rule-set, de database of de engine. Elke aanbeveling is onafhankelijk gereproduceerd door een
> tweede (adversariële) verify-stap; alleen kandidaten met verdict **SAFE** staan in de
> aanpas-kolom. Onbevestigde kandidaten staan apart als "nog niet bevestigd".

---

## Samenvatting — wat aanpassen

Alleen door VERIFY bevestigde **SAFE** wijzigingen. Eén subrule per rule (principe 1), drempel op de
slechte rand (principe 2). "OOS" = out-of-sample (time-split + cross-coin).

Alle cijfers hieronder zijn **engine-gevalideerd**: de hele rule mét de extra subrule is opnieuw over
de volledige kandidaat-historie van beide coins gedraaid (`rq2_refire_check.py <rule> rq1`), en het
aantal *executed* slechte/goede trades dat verandert is geteld — niet alleen de cache-telling.

| Rule | Wijziging (RQ1 — aanscherpen) | #slecht voorkomen (engine, beide coins) | #goed verloren | OOS good_keep | Zekerheid |
|------|-------------------------------|-----------------------------------------|----------------|---------------|-----------|
| 20 | ADD `vzo / range_percentage / lb17 / lower / -44.30233` | **5** (DOGEAI 4 + NOS 1) | 0 | 1.0 (time, 2525→244) | **SAFE** — 2 van 3 splits (244→2525 niet definieerbaar) |
| 21 | ADD `volumeud / diff_percentage_prev_max / lb9 / lower / 158.83697` | **8** (DOGEAI 6 + NOS 2) | 0 | 1.0 (alle 3 splits) | **SAFE** — alle 3 splits |
| 22 | ADD `volumeud / range_percentage / lb5 / lower / 15.17012` | **6** (DOGEAI 3 + NOS 3) | 0 | 1.0 (alle 3 splits) | **SAFE** — alle 3 splits *(herzien, zie kritieke correctie)* |
| 23 | ADD `vzo / diff_number_prev_min / lb20 / upper / -1.2` | **5** (DOGEAI 3 + NOS 2) | 0 | 1.0 (alle 3 splits) | **SAFE** — alle 3 splits (kleine samples) |

**Kern:** vier kleine aanscherpingen, elk precies één extra AND-subrule, samen **24 slechte executed
trades** voorkomen over de volledige periode van beide coins, met **0 goede executed trades verloren**.
Geen rule overbodig (RQ3): alle vier behouden. RQ2 (eerder kopen) levert kandidaten op, maar een
volledige re-fire over beide coins **wijst er 3 af** die nieuw slecht veroorzaken; 8 overige zijn
pilot-kandidaten. RQ4 (bijna-rakers): **niets veiligs**.

> ### ⚠️ Kritieke correctie — volumeud cache-vs-engine scale-mismatch
> De `indicator_metrics`-cache slaat **volumeud** op als *relatief* volume (`value / min_volume`),
> maar de rule-engine berekent subrule-metrics over de *ruwe* volumeud-waarden uit `brain.indicators`.
> Voor **level/absolute** volumeud-metrics (`median_value`, `current_value`, `*_value`, `diff_number_*`,
> `max_diff_number`, `standard_deviation`, …) verschilt de schaal met ~4 ordes (cache 3.05 vs engine
> 44144). Een uit de cache afgeleide drempel is daar in de engine **betekenisloos**.
> - De eerste RQ1-aanbeveling voor rule 22 (`volumeud/median_value/lb5 ≤ 0.15143`) bleek hierdoor
>   **inert**: de full-period re-fire verwijderde **0** trades. **Vervangen** door
>   `volumeud/range_percentage/lb5 ≥ 15.17012` (range_percentage is schaal-invariant → cache == engine;
>   engine-bevestigd 6 slecht weg).
> - **Schaal-veilig** zijn: alle niet-volumeud features (vzo/mfi/phobos/obv-x-value, zelfde schaal in
>   cache en engine) én volumeud **percentage/ratio**-metrics (`range_percentage`, `diff_percentage_*`,
>   `volatility`, `skewness`, `sum_average_positive_percentage`). De andere drie aanbevelingen (20/21/23)
>   vallen hieronder en zijn engine-bevestigd.
> - **Algemene les:** een cache-gedreven RQ1-kandidaat op een volumeud-level-metric mag nooit zonder
>   full-period re-fire de rule in. RQ2/RQ4 hebben dit probleem niet (die werken volledig op engine-schaal).

**Nuance bij rule 20:** de RQ1-single is bevestigd op 2 van 3 splits. De derde split (244→2525) is
**niet** door data weggevallen maar is structureel niet definieerbaar: coin 244 (NOS) heeft geen enkele
slechte trade onder zijn goede band, dus er bestaat geen bad-edge-drempel als 244 de train-coin is. Een
voorgesteld *paar* (vzo + phobos/skewness/lb11) claimde "SAFE op alle 3 splits", maar die claim
overleeft de strikte her-afleiding niet (de pair-validator lekt pooled-drempels in de test-coin). Het
paar koopt dus **geen** echte extra OOS-dekking en voegt wel een extra AND-conditie toe (tegen principe
1). **Daarom: de single, niet het paar.**

---

## Methode (kort)

**Definities (vast):**
- **GOED** = uitgevoerd, `best_upside ≥ 3%`.
- **SLECHT** = uitgevoerd, `best_upside < 0.5%`.
- **MIDDEL** = 0.5–3% (telt niet mee als goed of slecht).
- `best_upside` = beste haalbare exit binnen 1 uur (`coin_fires.best_upside`), **niet** onze eigen
  verkoop-P&L.
- Coins: **DOGEAI = 2525** (snel), **NOS = 244** (traag). Waar zinvol gepoold over beide.

**Twee tuning-principes (overrulen het naïeve "smalste band"):**
1. Zo **weinig** subrules per rule als mogelijk — elke subrule is een AND; meer condities verliezen
   goede trades en overfitten.
2. Drempel op de **slechte rand**, niet de goede rand — in het gat bij de meest extreme slechte trade
   net buiten de goede band, met buffer voor toekomstige goede trades.

**Out-of-sample-gate (hard):** een kandidaat is alleen SAFE als `good_keep ≈ 1.0` (≥ 0.98) op de
time-split **én** op elke cross-coin-split die data heeft, **én** er 0 nieuwe slecht bijkomen. Splits:
- **time** — train 70% / test 30% in de tijd.
- **2525→244** — train op DOGEAI, test op NOS.
- **244→2525** — train op NOS, test op DOGEAI.

Een tightening (extra AND-subrule) kan structureel alleen firings *verminderen*, dus 0 nieuwe slecht is
gegarandeerd; de gate test of er goede trades sneuvelen.

**Herhaalbare artefacten (6 scripts, draaibaar met de geverifieerde Python):**
```
PYTHON=/Users/daanvantongeren/Documents/Sites/brain/engine/.venv/bin/python
```
- `engine/src/opt_lib.py` — `load_long()`, `sweep_single()`, `bad_edge_conditions()`,
  `full_validation()`, `load_all_fires()`, `rule_overlap()`.
- `engine/src/opt_diag.py` — `DiagEngine` (subrule_status, near-miss diagnostiek).
- `engine/src/rq1_tighten.py [rule] [min_drop] [--pairs]` → `out/opt/rq1_tighten_<rule>.json`.
- `engine/src/rq2_earlier.py [rule] both` → `out/opt/rq2_earlier_rule<rule>.json`.
- `engine/src/rq2_refire_check.py [rule] [rq1|rq2]` → **verplichte full-period re-fire** over de volledige
  historie van beide coins (`DiagEngine.fires_override`). `rq2` = elke verruiming, telt NIEUWE fires (de
  échte "0 nieuw slecht"-toets). `rq1` = elke aanscherping, bevestigt 0 toegevoegd + 0 executed-goed verloren
  én legt de volumeud scale-mismatch bloot (een inerte subrule verwijdert 0 fires).
- `engine/src/rq4_nearmiss.py [rule] both 2` → `out/opt/rq4_nearmiss_rule<rule>.json`.
- gepoolde run: `out/opt/rq1_tighten_all.json` (alle rules, singles + pairs, OOS-gevalideerd).

Alle cijfers in dit rapport zijn door de verify-stap onafhankelijk gereproduceerd uit deze outputs.

---

## RQ1 — Aanscherpen

Per rule alleen de door VERIFY bevestigde **SAFE** kandidaat (de aanbevolen aanpassing). Andere SAFE
alternatieven worden kort genoemd als fallback.

> **Full-period re-fire bevestiging.** Een extra AND-subrule kan per definitie alleen fires
> *wegnemen*, nooit toevoegen — maar dat is ook empirisch nagelopen door de hele rule mét de extra
> subrule opnieuw over de **volledige kandidaat-historie van beide coins** te draaien (niet alleen de
> huidige trades). Resultaat voor alle vier aanscherpingen: **0 fires toegevoegd**, en **0 executed
> goede trades verloren** op DOGEAI én NOS (45/29/60/16/56/56/10/21 executed-goeds blijven 100%
> behouden). De wél verwijderde goede momenten (1–3 per coin) zijn uitsluitend **shadow-fires** —
> herhaalde fires binnen een al-open positie, geen aparte trade en dus geen gemiste kans. De
> in-sample/OOS-trade-check en de full-period re-fire geven hier dus hetzelfde antwoord.

### Rule 20 — `vzo / range_percentage / lb17 / lower / -44.30233`
- **Wijziging:** ADD subrule `indicator=vzo, calc=range_percentage, lookback=17, bound=lower, threshold=-44.30233`.
- **In-sample:** drop 5 slecht, `good_keep = 1.0`.
- **OOS:** SAFE op **time** (gk 14/14, bad_drop 0.214) en **2525→244** (gk 29/29, bad_drop 0.071).
  De **244→2525** split is structureel niet definieerbaar (coin 244 heeft 14 slecht, allen boven de
  goede band → geen bad-edge-conditie). Dat is geen falen, maar afwezigheid van een drempel.
- **Verify:** drempel exact her-afgeleid (pooled good.min = -36.71658, hoogste slecht eronder =
  -44.30233). `new_bad = 0` is structureel. Fewest-subrule optie (principe 1). **Bevestigd primair.**
- **Niet doen — het paar:** `vzo/range_percentage/lb17 lower -44.30233 AND phobos/skewness/lb11 lower
  -0.74211` (drop 9 in-sample). De claim "SAFE op alle 3 splits" houdt geen stand: de 244→2525-gk=1.0
  komt doordat de pair-validator pooled-drempels op een coin-subset toepast i.p.v. op de train-coin
  opnieuw af te leiden. Onder strikte semantiek heeft het paar **dezelfde** echte dekking als de single
  (time + 2525→244), maar met een extra AND-conditie. **Verdict UNCERTAIN — niet adopteren.**

### Rule 21 — `volumeud / diff_percentage_prev_max / lb9 / lower / 158.83697`
- **Wijziging:** ADD subrule `indicator=volumeud, calc=diff_percentage_prev_max, lookback=9, bound=lower, threshold=158.83697`.
- **In-sample:** drop 8 slecht (pooled descriptive count), `good_keep = 1.0`.
- **OOS:** SAFE op **alle 3 splits** — time (gk 18/18, bad_drop 0.0), 2525→244 (gk 16/16, bad_drop
  0.077), 244→2525 (gk 60/60, bad_drop 0.069). Alle test-sets ≥ 8 (kleinste 16g/26b) → **niet** in de
  overfit-zone.
- **Verify:** exact gereproduceerd via `full_validation`. Drempel ligt in een echt gat op beide coins
  (2525 laagste goed 163.72 vs rand 158.84; held-out 244 laagste goed 178.83 ligt ~20 boven de rand →
  geen knife-edge). Per-split bad-edge-drempels: time 138.48014, 2525→244 158.83697, 244→2525
  151.52195. **Sterkste, volledig geverifieerde aanscherping van de set.**
- **Fallbacks:** `mfi/sum_average_positive_percentage/lb14/lower/0.95` (alle 3 splits SAFE, maar minder
  bad gedropt — niet boven de hoofdkandidaat). **Let op:** `volumeud/diff_number_prev_min/lb9/upper/-0.38602`
  (drop 10 in-sample) is een volumeud *level*-metric en lijdt aan de **scale-mismatch** — niet bruikbaar
  als engine-subrule zonder herschaling. De hoofdkandidaat `diff_percentage_prev_max` is een percentage
  en daarom schaal-veilig.

### Rule 22 — `volumeud / range_percentage / lb5 / lower / 15.17012` *(herzien)*
- **Wijziging:** ADD subrule `indicator=volumeud, calc=range_percentage, lookback=5, bound=lower, threshold=15.17012`.
- **Waarom herzien:** de oorspronkelijke kandidaat `volumeud/median_value/lb5 ≤ 0.15143` is **verworpen** —
  `median_value` is een volumeud *level*-metric en lijdt aan de cache-vs-engine scale-mismatch
  (cache relatief volume ~0.15, engine ruw volume ~tienduizenden). De full-period re-fire bevestigde:
  **0 trades verwijderd** — de subrule doet niets in de engine. `range_percentage` is schaal-invariant
  (cache == engine) en is daarom een geldige vervanger.
- **OOS:** SAFE op **alle 3 splits** — `good_keep = 1.0` (time / 2525→244 / 244→2525), `bad_drop`
  0.115 / 0.058 / 0.065, drempel zeer stabiel: 15.17 / 15.17 / 15.04.
- **Engine-bevestigd (full re-fire, beide coins):** **6 executed slecht verwijderd** (DOGEAI 3 + NOS 3 —
  gebalanceerd, geen single-coin-artefact), **0 goede executed trades verloren**.
- **Alternatief (ook schaal-veilig + engine-bevestigd 6 slecht weg):** `obv-x-value/volatility/lb10 ≥ 0.02381`
  (andere indicator, gk 1.0 op alle 3 splits, DOGEAI 3 + NOS 3). Gelijkwaardig; kies één — niet stapelen.
- **Geen SAFE pair:** 0 van alle gegenereerde paren voor rule 22 haalde de OOS-gate. Correct geen paar
  toevoegen (principe 1).

### Rule 23 — `vzo / diff_number_prev_min / lb20 / upper / -1.2`
- **Wijziging:** ADD subrule `indicator=vzo, calc=diff_number_prev_min, lookback=20, bound=upper, threshold=-1.2`.
- **In-sample:** drop 5 slecht, behoudt alle 31 goed.
- **OOS:** SAFE op **alle 3 splits** — time (gk 7/7, bad_drop 0.182), 2525→244 (gk 21/21, bad_drop
  0.095), 244→2525 (gk 10/10, bad_drop 0.375). De enige kandidaat met een echte positieve bad-drop op
  de **grote** cross-coin-richting (n_te_bad = 21).
- **Verify:** exact her-afgeleid. `good_keep = 1.0` overal, slecht gedropt op elke split.
  Meest cross-coin-robuuste dropper voor rule 23. **Bevestigd primair.**
- **Alternatief (ook SAFE op alle 3):** `obv-x-value/max_diff_number/lb18/lower/3.9` (bad_drop
  0.273/0.048/0.25 — maar slechts 1/21 slecht gedropt op 2525→244, marginaal). Niet beide stapelen.
- **Caveat:** rule 23 heeft kleine samples (29 slecht / 31 goed pooled; OOS bad-drops zijn 1–3 trades).
  `good_keep = 1.0` rust op n_te_good zo laag als 7. De vzo-kandidaat is de veiligste keuze, maar
  verwacht een klein reëel effect.

---

## RQ2 — Eerder kopen (bestaande band verruimen)

Per rule: welke bestaande subrule-band verruimd kan worden om eerder/extra goede trades te vangen, met
0 nieuwe slecht.

### ⚠️ Verplichte toets: volledige re-fire over de hele periode (niet alleen huidige trades)

Een verruiming **voegt fires toe** op datetimes die nu geen trade zijn. De RQ2-driver
(`rq2_earlier.py`) controleerde "0 nieuwe slecht" alleen binnen de **sole-blocker-set per coin,
in-sample** — dat is *niet* genoeg. De definitieve toets is: de **hele rule met de ruimere band
opnieuw over de VOLLEDIGE kandidaat-historie van bóide coins draaien** (`rq2_refire_check.py`, via
`DiagEngine.fires_override`) en élke nieuwe fire op `best_upside` classificeren.

Die re-fire **diskwalificeert 3 kandidaten** die de per-coin-check als "0 nieuw slecht" had gemarkeerd:

| Rule | Verruiming | Re-fire (volledige periode, beide coins) | Verdict |
|------|-----------|------------------------------------------|---------|
| 20 | `vzo/previous_value/lb7` upper 47.5 → 55.0 | DOGEAI +7 (5 goed, 0 slecht); **NOS +2 (1 SLECHT)** | ❌ **AFGEWEZEN** |
| 22 | `obv-x-value/currentvalue/lb1` lower 36.2 → 33.3 | **DOGEAI +2 (1 SLECHT)**; NOS +3 goed | ❌ **AFGEWEZEN** |
| 23 | `volumeud/previous_value/lb7` lower 0.5 → -4.3 | DOGEAI +7 (0 slecht); **NOS +30 (6 SLECHT)** | ❌ **AFGEWEZEN** |
| 20 | `obv-x-value/previous_value/lb3` lower 0.1 → -1.0 | DOGEAI +3 goed; NOS +1 goed | ✅ 0 nieuw slecht (full period) |
| 20 | `phobos/skewness/lb11` lower -1.029 → -2.206 | DOGEAI +4 (3 goed); NOS +0 | ✅ 0 nieuw slecht |
| 21 | `vzo/skewness/lb5` upper 1.392 → 2.497 | DOGEAI +5 (4 goed); NOS +3 middel | ✅ 0 nieuw slecht |
| 21 | `volumeud/previous_value/lb10` lower -3.7 → -5.8 | DOGEAI +3 goed; NOS +0 | ✅ 0 nieuw slecht |
| 22 | `obv-x-value/range_percentage/lb14` lower 0.720 → -0.325 | DOGEAI +3 goed; NOS +4 (2 goed) | ✅ 0 nieuw slecht |
| 22 | `volumeud/previous_value/lb3` lower -0.7 → -3.1 | DOGEAI +8 (3 goed, 5 middel); NOS +2 goed | ✅ 0 nieuw slecht |
| 23 | `volumeud/previous_value/lb5` lower -2.7 → -5.3 | DOGEAI +1 goed; NOS +0 | ✅ 0 nieuw slecht |
| 23 | `phobos/volatility/lb5` lower 0.7 → -0.7 | DOGEAI +1 goed; NOS +9 (5 goed) | ✅ 0 nieuw slecht |

**Les:** voor verruimingen is "0 nieuw slecht op de huidige/per-coin trades" misleidend — pas een
volledige re-fire over beide coins legt de nieuwe slechte fires bloot. Drie van de elf kandidaten
vielen daardoor af (rule 20 vzo, rule 22 obv-currentvalue, rule 23 volumeud-lb7).

**Status van de overgebleven 8:** zij geven 0 nieuw slecht over de **volledige periode van beide coins**
— sterker dan "per-coin in-sample". Maar de verruimde drempel is nog steeds **op diezelfde periode
afgeleid** (geen train/test-split, geen toekomst). Dus: **kandidaat voor een monitored pilot**, nog
niet "shippen". De per-coin driver-details hieronder zijn de oorspronkelijke (pre-re-fire) bron;
gebruik de tabel hierboven als leidend.

### Rule 20 (sym 2525, alle in-sample only — UNCERTAIN)
| Bestaande subrule | Van → naar | Extra goed | best_upside-winst | Nieuwe slecht | Opmerking |
|-------------------|-----------|-----------|-------------------|---------------|-----------|
| `vzo/previous_value/lb7` | upper 47.5 → 55.0 | 5 | +26.2% | 0 (in-sample) | 0 executed trades dragen deze feature → niet cross-te-checken |
| `obv-x-value/previous_value/lb3` | lower 0.1 → -1.0 | 3 | +33.3% | 0 (in-sample) | grootste upside; incl. periode 13196 @ 15.6%/14.7% |
| `phobos/skewness/lb11` | lower -1.02922 → -2.20553 | 3 | +16.9% | 0 (in-sample) | best gecorroboreerd (admit 0 executed slecht op beide coins) |

Alle drie zijn nieuwe kansen (earlier_in_move = 0). Samples n=3–5 (< 8, overfit-risico). Verify:
`phobos/skewness/lb11` is het schoonste pilot-geval; niet blind shippen.

### Rule 21 (sym 2525, in-sample only — UNCERTAIN)
| Bestaande subrule | Van → naar | Extra goed | best_upside-winst | Nieuwe slecht | Opmerking |
|-------------------|-----------|-----------|-------------------|---------------|-----------|
| `vzo/skewness/lb5` | upper 1.39219 → 2.49739 | 4 | +27.5% | 0 (in-sample) | 1 van 4 is earlier-in-move (2025-03-04 03:29:36, bu 16.83 vs latere fire 11.806) |
| `volumeud/previous_value/lb10` | lower -3.7 → -5.8 | 3 | +29.4% | 0 (in-sample) | 2 van 3 earlier-in-move (2025-02-24 16:48/16:49, bu 11.79/10.90 vs latere fire 12.02) |

Samples n=3–4 (< 8). Verify downgrade't beide van de explore-`recommend=true` naar **UNCERTAIN**:
`new_bad=0` is in-sample, één-coin, niet held-out. Als gewenst: alleen coin-2525, monitoren.

### Rule 22 (in-sample per-coin only — UNCERTAIN)
| Bestaande subrule | Van → naar | Extra goed | best_upside-winst | Nieuwe slecht | Opmerking |
|-------------------|-----------|-----------|-------------------|---------------|-----------|
| `obv-x-value/range_percentage/lb14` (2525) | lower 0.72001 → -0.32496 | 3 | +23.3% | 0 (in-sample) | nieuwe kansen; geen bad-edge-anker (n_bad_sole_blocked=0) |
| `obv-x-value/currentvalue/lb1` (244) | lower 36.2 → 33.3 | 3 | +22.1% | 0 (in-sample) | enige met echte earlier-in-move (2024-02-23, bu 6.31 vs 4.08) |
| `volumeud/previous_value/lb3` (2525) | lower -0.7 → -3.1 | 3 | +17.2% | 0 (in-sample) | nieuwe kansen; geen bad-edge-anker |

**Kritiek (verify):** voor alle drie is `n_bad_sole_blocked = 0` — er was geen enkele slechte trade om
een bad-edge aan te ankeren, dus `new_bad = 0` is *triviaal* waar (lege regio), niet bewaakt door een
slechte rand. Samples n=3. **Niet adopteren zonder `full_validation` cross-coin-bevestiging.**

### Rule 23 (in-sample één-coin only — UNCERTAIN)
| Bestaande subrule | Van → naar | Extra goed | best_upside-winst | Nieuwe slecht | Opmerking |
|-------------------|-----------|-----------|-------------------|---------------|-----------|
| `volumeud/previous_value/lb5` (2525) | lower -2.7 → -5.3 [band -5.3, 3.4] | 5 | +54.8% | 0 (in-sample) | grootste upside; geen bad-edge-anker |
| `volumeud/previous_value/lb5` (2525) | upper 3.4 → 6.7 [band -2.7, 6.7] | 5 | +54.8% | 0 (in-sample) | zelfde 5 goeds via andere rand — **alternatief**, niet additief |
| `phobos/volatility/lb5` (244) | lower 0.7 → -0.7 [band -0.7, 28.9] | 5 | +43.6% | 0 (in-sample) | enige met echte earlier-in-move (2024-03-06 07:06, bu 8.259 vs 7.159) |
| `volumeud/previous_value/lb7` (2525) | lower 0.5 → -4.3 [band -4.3, null] | 3 | +22.6% | 0 (in-sample) | kleinste/zwakste; n=3 |

**Kritiek (verify):** `n_bad_sole_blocked = 0` voor **elke** loosening — de verruimde rand rust op
**geen** slechte-trade-buffer (driver-fallback = good.min()-1). Geen OOS/cross-coin-test. De twee
idx25-varianten vangen dezelfde 5 goeds, dus kies er hooguit één. **Alle vier UNCERTAIN** — eerst OOS
valideren. `phobos/volatility/lb5` (244, earlier-in-move) is het interessantst voor een pilot.

---

## RQ3 — Overbodige rules (redundantie)

Vraag: dekt een andere rule de goede trades van een rule zó vaak dat hij weg kan? Drempel om te
schrappen: ~80% dekking bij strakke tolerantie. Berekend over **alle** fires incl. shadows (1741 fires,
780 shadows — dedup verbergt dus geen overlap).

| Rule | #goede trades | Gedekt door anderen (tol=2min) | Max (tol=5min, per coin) | Belangrijkste dekker | Schrappen? |
|------|---------------|--------------------------------|--------------------------|----------------------|-----------|
| 20 | 74 | 10.8% (8/74) | 10.8% (DOGEAI 8.9% / NOS 13.8%) | rule 22 (6.8%), rule 21 (4.1%) | **Nee** |
| 21 | 76 | 5.3% (4/76) | 10.5% (DOGEAI 11.7% / NOS 6.2%) | rule 20 (5.3%) — meest unieke rule | **Nee** |
| 22 | 112 | 13.4% (15/112) | 16.1% (DOGEAI 10.7% / NOS 21.4%) | rule 23 (11.6%) | **Nee** |
| 23 | 31 | 9.7% (3/31) | 12.9% (DOGEAI 10.0% / NOS 14.3%) | rule 22 (9.7%) | **Nee** |

**Conclusie:** **COMPLEMENTAIR — schrap niets.** Elke rule zit 4–8× onder de 80%-drempel. Het enige
noemenswaardige paar is **22 ↔ 23** (~10–12%, asymmetrisch en partieel — geen van beide bevat de ander:
22 dekt 9.7% van 23; 23 dekt 11.6% van 22). Rule 21 is de meest unieke (5.3%). Elke rule vangt een
grotendeels eigen set kansen; één weghalen verliest ~85–95% van zijn goede trades ongedekt. **Alle vier
behouden.**

---

## RQ4 — Bijna-rakers (near-misses)

Vraag: zijn er no-trade-momenten met `best_upside ≥ 3%` die net niet vuren (1–2 subrules tekort), en
kunnen we de blokkerende subrule **veilig** verruimen om ze te vangen? **Eerlijk antwoord voor alle
vier rules: nee.** Elke blokkerende subrule zit in een slecht-dichte regio — de slechte rand wordt
bereikt vóór er een gemiste goede trade binnenkomt.

| Rule | #near-miss (goed) | Verdeling | Veilige loosening? | Belangrijkste blokker(s) |
|------|-------------------|-----------|--------------------|--------------------------|
| 20 | 63 | allen goed | **Nee** | `phobos/currentvalue/lb1` (24 near-misses; loosen +2/+6 slecht, 0 goed); `volumeud/volume_check/lb1` (12, null-blokker — niet drempelbaar) |
| 21 | 91 | 29 @ n_fail=1, 62 @ n_fail=2 | **Nee** | `phobos/previous_value/lb10` (37; +8 slecht, 0 goed); `phobos/currentvalue/lb1` (31; +1 slecht, 0 goed) |
| 22 | 35 | 19 @ n_fail=1, 16 @ n_fail=2 | **Nee** | `volumeud/volume_check/lb1` (null-blokker, blokkeert top-miss bu=36.4%); `phobos/currentvalue/lb1` (4; +1 slecht, 0 goed) |
| 23 | 12 (40 totaal incl. n_fail=2) | 12 @ n_fail=1 | **Nee** | `phobos/currentvalue/lb1` [47.0, 57.9] (sole-blokkeert 8 van 12; loosen +3..8 slecht, 0 goed) |

**Detail per rule (verify-bevestigd):**
- **Rule 20:** 63 goede near-misses, geen veilige single-subrule loosening. Loosenings lopen van +1 tot
  +116 nieuw slecht met 0 (in één geval 1) gemiste goede gevangen. De RQ2-band-verruimingen
  (vzo/previous_value/lb7, obv-x-value/previous_value/lb3, phobos/skewness/lb11) vangen wél goed bij 0
  nieuwe slecht — maar dat zijn **bestaande-band**-verruimingen, geen near-miss-blokkers.
- **Rule 21:** 91 goede near-misses; alle 10 driver-loosenings hebben `missed_good_admitted = 0` én
  `new_bad ≥ 1`. Zelfs de kleinste stap op de top-blokker admit 1 slecht vóór enig goed. De echte
  upside voor rule 21 zit in de RQ2-band-verruimingen, niet in near-miss-recovery.
- **Rule 22:** 35 near-misses; alle 10 blokker-loosenings admit 0 gemiste goed en ≥ 1 nieuw slecht. De
  twee sterkste captures (`obv-x-value/range_percentage/lb14` @ 2525 en `obv-x-value/currentvalue/lb1`
  @ 244) zijn wél recoverbaar — maar via de RQ2-driver (0-new-bad band-verruiming), niet via de
  near-miss-blokkers.
- **Rule 23:** 12 single-blokker goeds; van 11 geëvalueerde loosenings haalt **0** `missed_good > 0` met
  0 nieuw slecht. De enige die enig goed admit (`phobos/previous_value/lb2`) kost +15 slecht voor +1
  goed. `phobos/currentvalue/lb1` [47.0, 57.9] is het knelpunt maar niet veilig te verbreden.

**Conclusie RQ4:** geen actie voor enige rule. Dit is een reëel negatief resultaat, geen tekort aan
zoeken — de blokkers liggen consequent in slecht-dichte regio's.

---

## Onzekerheden & vervolg

- **RQ1 is solide; rule 23 het dunst.** De vier aanscherpingen zijn alle SAFE en exact gereproduceerd.
  Let op: rule 23 heeft de kleinste samples (29 slecht / 31 goed pooled; OOS bad-drops 1–3 trades,
  n_te_good zo laag als 7). `good_keep = 1.0` is daar betrouwbaar maar het reële effect is klein.
- **RQ1 drop-cijfers zijn pooled descriptive counts**, geen per-split OOS-drops (bv. rule 21: pooled
  drop 8 vs per-split train_drops 3/6/1). Dit is een label-nuance, niet een fout — de OOS-gate
  (good_keep = 1.0, 0 nieuw slecht) is wat telt en die slaagt.
- **RQ2 verruimingen MOETEN full-period gere-fired worden.** De per-coin in-sample sole-blocker-check
  van de driver is ontoereikend: een ruimere band voegt fires toe op niet-trade-datetimes. De
  volledige re-fire (`rq2_refire_check.py`) over de hele historie van **beide** coins wees **3 van 11**
  kandidaten af die nieuw slecht veroorzaken (rule 20 `vzo/previous_value/lb7`→55.0: +1 slecht op NOS;
  rule 22 `obv-x-value/currentvalue/lb1`→33.3: +1 slecht op DOGEAI; rule 23 `volumeud/previous_value/lb7`
  →-4.3: **+6 slecht** op NOS). De 8 overgebleven kandidaten geven 0 nieuw slecht over de volledige
  periode van beide coins, maar de drempel is in-sample op diezelfde periode afgeleid (geen train/test,
  geen toekomst). **Niet blind shippen** — pilot achter een monitor. Beste pilot-kandidaten (full-period
  schoon + earlier-in-move): rule 21 `vzo/skewness/lb5`, rule 23 `phobos/volatility/lb5` (244).
- **RQ4 levert niets veiligs** voor alle vier rules — bevestigd. Niet forceren.
- **RQ3:** alle vier rules complementair, schrap niets.
- **Dagelijkse herhaalbaarheid:** de zes scripts zijn herbruikbare artefacten. Bij nieuwe trades /
  promising periods: draai `rq1_tighten.py`, `rq2_earlier.py`, `rq4_nearmiss.py` opnieuw met dezelfde
  Python en vergelijk de drempels. De cijfers in dit rapport zijn reproduceerbaar uit
  `engine/out/opt/*.json`.

---

*Dit rapport wijzigt niets. Het stelt vier aanscherpingen voor (één subrule per rule), bevestigd SAFE
op de OOS-gate, plus een lijst onbevestigde RQ2-verruimingen om eventueel als monitored pilot te
overwegen.*
