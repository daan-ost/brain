# Volume-parameter sweep — rapport (read-only, 15 jun 2026)

> **Read-only analyse. Er is NIETS toegepast.** Geen wijziging aan `brain`, aan de rule-set, of aan
> `engine/src/volume.py`. Dit rapport varieert uitsluitend de per-rule `_VOLUME_OVERRIDES`-parameters
> van `check_volumeud_3` (de `volume_check`-subrule) en meet, per kandidaat, een **volledige
> in-memory persist_to_brain-equivalente re-fire** over de complete historie van **beide** coins. Elke
> bevinding is engine-gemeten (echte executed good/slecht via `best_upside`), niet cache-afgeleid.

---

## Samenvatting — wat de sweep oplevert

De volume-parameters zijn de grootste tot nu toe onaangeroerde dimensie, maar onder de strikte
auto_apply-gate (totaal goede behouden **én** totaal slecht strikt omlaag) blijken ze een **kleine
hefboom**. Eén cross-coin-robuuste single (rule 21), een schone robuuste combo die er +1 NOS bij pakt,
en drie één-coin-randverbeteringen (rules 20/22/23, OOS-fragiel).

| Rule | Param-wijziging | DOGEAI slecht | NOS slecht | goed verloren | Mechanisme | Verdict |
|------|-----------------|---------------|-----------|---------------|-----------|---------|
| **21** | `trigger_minimal_volume_relative` 0.03 → **~0.022** (plateau 0.020–0.026) | 127 → **126** | 97 → **96** | 0 | directe `volume_check`-afwijzing (beide coins) | **CROSS-COIN ROBUUST** |
| 20 | `minimal_rows_to_analyse` 5 → 10 | 127 → 127 | 97 → 96 | 0 | direct (alleen NOS) | pooled, één-coin |
| 22 | `trigger_minimal_volume_relative` 0.03 → 0.0345 | 127 → 127 | 97 → 95 | 0 | direct (alleen NOS) | pooled, één-coin |
| 23 | `minimal_relative_volume` 0.07 → 0.37 | 127 → 126 | 97 → 97 | 0 | direct (alleen DOGEAI) | pooled, één-coin |

**Kern:** de enige wijziging die de gate op **beide** coins onafhankelijk haalt is rule 21's
`trigger_minimal_volume_relative` omlaag naar het plateau **0.020–0.026** (aanbevolen ~0.022–0.024):
−1 slecht op DOGEAI **én** −1 op NOS, 0 goede trades verloren, beide via een **directe**
`volume_check`-afwijzing (geen dedup-reshuffle). De andere drie rules leveren elk één **één-coin**
slecht-verwijdering die neutraal (niet schadelijk) is op de andere coin — pooled-gate-positief maar
**niet** cross-coin robuust, en hun cross-coin OOS-transfer faalt grotendeels (overfit-risico).

**Cross-snapshot robuust (sterkste bewijs).** De rule 21-vondst is herhaald in meerdere verse processen
terwijl de auto-apply routine `brain.rules` verder aanscherpte (DOGEAI-baseline slecht 127 → 124). In
**elke** snapshot verwijdert `trigger ≈ 0.022` exact dezelfde twee slechte fires —
DOGEAI `2025-02-15 15:43:46` en NOS `2024-01-02 03:31:00` — direct, met 0 goed verloren. De wijziging
haalt dus een **specifiek slecht volume-signaal** weg, ongeacht de rule-configuratie: een echt effect,
geen artefact van één snapshot.

**Robuuste combo (optioneel, +1 NOS).** De verificatie vond ook een gate-robuuste **combo**:
`trigger ≈ 0.022 + minimal_rows_to_analyse = 7` → DOGEAI −1, **NOS −2**, pooled slecht 224 → **221**,
0 goed verloren. De `min_rows`-component verwijdert een **aparte, disjuncte** NOS-slechte fire
(`2024-09-20 22:16:01`) óók **direct** (`no_longer_fires`) — dus de NOS-additiviteit is schoon, niet
dedup. Wel is `min_rows=7` op zichzelf één-coin (alleen NOS) en OOS-ongetest, en de DOGEAI-hefboom komt
volledig van `trigger`. **Let op de variant** `trigger + maximal_relative_volume=4.6`: zelfde totaal,
maar de extra NOS-drop is daar een `now_shadowed(dedup)` reshuffle én hij flipt een DOGEAI-goede erbij —
**niet gebruiken**. Daarom blijft de **single de schone aanbeveling**, de `min_rows`-combo een
gedocumenteerde +1-NOS-optie.

> ### ⚠️ Meta-bevinding: `brain` muteerde live tijdens de analyse
> De auto-apply routine tightende `brain.rules` concurrent met deze sweep: de DOGEAI-baseline schoof
> **172 → 176 → 177** goede trades en **127 → 124** slechte over ~20 minuten (meerdere rule-snapshots,
> wisselende fingerprints). Daardoor zijn **absolute** getallen tussen losse processen niet vergelijkbaar.
> Dit rapport is daarom afgeleid uit **één bevroren in-memory snapshot** (fingerprint `92962ba8…`, 182
> non-volume subrules, baseline DOGEAI 177/127, NOS 122/97) waarin baseline én alle kandidaten consistent
> gemeten zijn. De **delta's** (welke slechte fires een param-wijziging weghaalt) zijn wél stabiel over alle
> snapshots — daarop rust de conclusie. Les voor een toekomstige routine: meet altijd binnen één geladen
> snapshot, leg de fingerprint vast, en draai niet tegelijk met de auto-apply routine.

---

## Methode

**Gate (identiek aan `auto_apply`, NIET oracle-agreement):**
- **GOED** = executed fire met `best_upside ≥ 3`. **SLECHT** = executed fire met `best_upside < 0.5`.
- Een param-set wordt alleen gehouden als **totaal goede behouden of stijgt** EN **totaal slecht strikt
  daalt**. Daarbovenop onderscheiden we **cross-coin robuust** (slecht strikt omlaag op DOGEAI **én**
  NOS, 0 goed verloren op elk) van **pooled** (alleen het totaal, kan één-coin zijn).
- Bewust **losgekoppeld van legacy**: oracle-agreement (validate_period) is **niet** de maat en mag
  dalen. `best_upside` (`coin_fires.best_upside`) is de trade-kwaliteit.
- Coins: **DOGEAI = 2525** (snel), **NOS = 244** (traag).

**Harness (`engine/src/volume_sweep.py`, read-only — schrijft alleen naar `engine/out/opt/`):**
Elke evaluatie is een volledige re-fire van alle rules 20–23 over de complete kandidaat-historie van
een coin, mét de single-position dedup en `best_upside` — dus exact wat `persist_to_brain.py` doet,
maar **in-memory** (mutatie­vrij). Gevalideerd: `volume_sweep.py probe` reproduceert de persisted
`brain.coin_fires`-baseline **exact**, inclusief per-rule g/b.

**Snelheidstruc (exact, geen benadering):** de volume-params raken **uitsluitend** de
`volume_check`-subrule. Alle andere subrules zijn invariant onder de sweep, dus de set kandidaat-
datetimes die alle **niet-volume** subrules passeert wordt per rule **één keer** voorberekend; elke
param-set draait daarna alleen `check_volumeud_3` over die set + de goedkope dedup. Dit verlaagt een
evaluatie van **~16 s naar ~0.05 s** — vandaar dat coarse grid + coördinaat-descent + pairwise combos
+ cross-coin OOS allemaal haalbaar zijn.

**Gevarieerde parameters (10):** `minimal_relative_volume`, `maximal_relative_volume`,
`multiplier_volume_sum_min/max`, `trigger_minimal_volume_relative`, `not_negative_before_x_values`,
`max_price_diff_percentage`, `min_price_diff_percentage`, `rows_to_analyse`, `minimal_rows_to_analyse`.
(`minutes_to_analyse` is **inert** in de engine — het `_vol_rows`-venster is in `rule_engine` hard op 60
gezet — dus niet meegenomen.)

**Zoekstrategie:** per rule een coarse grid per param (beide richtingen, verruimen én aanscherpen),
greedy coördinaat-descent (gestapelde toelaatbare moves), pairwise combos van toelaatbare singles, en
een **echte train→test OOS**: leid de optimale waarde af op de train-coin, test de transfer op de
andere coin. Een tightening kan alleen fires *wegnemen*, maar via de dedup kan dat goede shadows
un-shadowen of slechte naar een andere rule verschuiven — daarom telt altijd het **totaal**, full-period.

---

## Resultaten per rule (snapshot `92962ba8…`, baseline DOGEAI 177/127 · NOS 122/97)

### Rule 21 — `trigger_minimal_volume_relative` 0.03 → ~0.022  *(de enige robuuste)*
- **Fijn raster** (cross-coin gated) toont een **plateau**, geen knife-edge:

  | trigger | DOGEAI g/b | NOS g/b | verdict |
  |--------|-----------|---------|---------|
  | 0.016–0.018 | 177/127 | 122/96 | pooled (alleen NOS) |
  | **0.020–0.026** | **177/126** | **122/96** | **ROBUUST** |
  | 0.028 | 177/126 | 122/97 | pooled (alleen DOGEAI) |
  | 0.030 (huidig) | 177/127 | 122/97 | baseline |

- **Mechanisme (`diag`):** beide gedropte slechte trades verdwijnen **direct** (`no_longer_fires`):
  DOGEAI `2025-02-15 15:43:46`, NOS `2024-01-02 03:31:00`. **Geen dedup-reshuffle, 0 goed verloren.**
- **OOS:** train DOGEAI (→0.021) transfereert naar NOS; train NOS (→maximal_relative_volume, →min_rows)
  transfereert naar DOGEAI. 4/5 param-moves transfereren cross-coin (de uitzondering, trigger→0.09, is
  duidelijk overfit: NOS −7 maar DOGEAI −5 goed).
- **Aanbevolen waarde:** midden van het plateau, **~0.022–0.024**. Effect: pooled slecht 224 → 222.

### Rule 20 — `minimal_rows_to_analyse` 5 → 10  *(één-coin)*
- DOGEAI ongewijzigd (127/127), NOS slecht 97 → 96 (directe verwijdering `2023-12-26 04:25:52`), 0 goed
  verloren. Pooled-gate haalt het (224 → 223), maar **niet** cross-coin robuust. OOS: 1/2 transfer.
- Verdict: veilig-maar-marginaal, alleen-NOS. Geen DOGEAI-bewijs → niet als robuust beschouwen.

### Rule 22 — `trigger_minimal_volume_relative` 0.03 → 0.0345  *(één-coin)*
- DOGEAI ongewijzigd, NOS slecht 97 → 95 (twee directe verwijderingen: `2024-02-14 07:44:34`,
  `2024-03-25 08:12:06`), 0 goed verloren. Pooled 224 → 222, **niet** robuust. OOS: 1/4 transfer
  (de DOGEAI-afgeleide kandidaten schaden NOS → overfit).
- Verdict: alleen-NOS, sterkste één-coin-drop, maar geen cross-coin steun.

### Rule 23 — `minimal_relative_volume` 0.07 → 0.37  *(één-coin)*
- DOGEAI slecht 127 → 126 (directe verwijdering `2025-03-10 11:10:12`), NOS ongewijzigd, 0 goed
  verloren. Pooled 224 → 223, **niet** robuust. OOS: 1/3 transfer. Kleine samples (rule 23 is dun).
- Verdict: alleen-DOGEAI, marginaal.

### Combos & descent
- **Pairwise combos:** rule 21 heeft naast de robuuste single ook **gate-robuuste combos** (geverifieerd),
  pooled slecht 224 → **221** (DOGEAI −1, NOS −2), 0 goed verloren. Twee smaken, niet gelijkwaardig:
  `trigger 0.021 + minimal_rows_to_analyse 7` is **schoon** (`min_rows` neemt een aparte NOS-fire
  `2024-09-20 22:16:01` direct weg, disjunct), terwijl `trigger + maximal_relative_volume 4.6` hetzelfde
  totaal haalt via een **dedup-shadow** én een DOGEAI-goede erbij flipt → **afgeraden**. Geen combo haalt
  ≥2 slecht op beide coins (DOGEAI blijft −1 van `trigger`). Rules 20/22/23: 0 robuuste combos. → de
  combo is een **optionele** +1 NOS, niet de hoofdkeuze (principe 1).
- **Greedy descent** maximaliseert *pooled* slecht-drop en kiest daarom voor rule 21 de **één-coin**
  `trigger=0.0075` (+ `min_rows=7`) boven de robuuste `0.022` — beide geven pooled −2, maar de descent
  optimaliseert niet op robuustheid. **De descent-stack is dus NIET de aanbeveling**; de robuuste single is.

---

## Cross-coin OOS samenvatting (overfit-toets)

Per rule, hoeveel train→test param-moves cross-coin transfereren (train-optimale waarde mag de test-coin
niet schaden): rule 20 **1/2**, rule 21 **4/5**, rule 22 **1/4**, rule 23 **1/3**. Alleen rule 21 heeft
een meerderheid die transfereert — consistent met de robuuste-single-bevinding. Voor 20/22/23 schaadt de
op de ene coin afgeleide optimale waarde meestal de andere coin: **reëel overfit-risico met slechts 2 coins.**

---

## Adversariële verificatie (verse processen, onafhankelijk)

Vijf onafhankelijke agents herverifieerden elk in een **vers proces** (elk een eigen, mogelijk net
verder-gemuteerde snapshot — dat test cross-snapshot-stabiliteit gratis mee):

1. **Plateau-stabiliteit (rule 21 trigger).** Vers proces reproduceert het robuuste plateau **exact**:
   `trigger ∈ [0.020, 0.026]` → DOGEAI 126, NOS 96 (ROBUUST); 0.018 → alleen NOS (pooled); 0.028 →
   alleen DOGEAI (pooled). Plateaubreedte ~0.006, midden ~0.022–0.024. **Geen knife-edge, stabiel.**
2. **Mechanisme-refutatie (rule 21 trigger).** `diag` bevestigt: beide gedropte slechte trades
   verdwijnen `no_longer_fires(direct)`; **geen** `-GOOD`-regels (geen goede trade gekild), **geen**
   dedup-reshuffle. Niet te weerleggen — echte, directe verwijdering. Risk: **low.**
3. **Robuuste combo (rule 21).** `pairgrid` vond 8/6/10 gate-robuuste paren over
   `trigger × {min_rows, maximal_relative_volume, multiplier_volume_sum_min}`. Beste **schone**: pooled
   slecht 224 → **221** (DOGEAI −1, NOS −2) via `trigger 0.021 + min_rows 7`, waarbij `min_rows` een
   **aparte** NOS-fire direct wegneemt (disjunct, geen dedup). De `maximal_relative_volume=4.6`-variant
   geeft hetzelfde totaal maar via een dedup-shadow (fragiel) — afgeraden. Geen enkele combo haalt
   ≥2 slecht op **beide** coins; de DOGEAI-kant blijft −1 (alleen `trigger`). (Corrigeert de eerdere
   lezing "0 robuuste combos".)
4. **Één-coin-audit (rules 20/22/23).** Bevestigd: elk is een directe verwijdering op **één** coin,
   neutraal op de andere, 0 goed verloren — maar geen cross-coin steun en OOS-fragiel. Risk: **medium**
   (20: alleen-NOS), **medium** (22: alleen-NOS, sterkste één-coin), **medium** (23: alleen-DOGEAI, dun).
5. **Completeness-criticus.** Fijne rasters op `not_negative_before_x_values`, `multiplier_volume_sum_min`,
   `maximal_relative_volume`, `min_price_diff_percentage` (rule 21) en op rule 20/22: **0** nieuwe
   robuuste waarden. De coarse grid was volledig — **`trigger` is de enige robuuste hefboom** voor rule 21.

**Cross-snapshot bewijs.** Tussen de processen schoof de baseline DOGEAI-slecht van 127 → 124 (de routine
tightende door), tóch verwijdert `trigger ≈ 0.022` in elke snapshot exact dezelfde twee slechte fires
(DOGEAI `2025-02-15 15:43:46`, NOS `2024-01-02 03:31:00`) met 0 goed verloren. Sterkste mogelijke
robuustheidssignaal met 2 coins.

---

## Conclusie & aanbeveling

1. **De volume-params zijn een kleine, grotendeels al-benutte hefboom** onder de strikte gate — niet de
   grote onontgonnen winst waar je op zou hopen. De enige cross-coin-robuuste single verwijdert
   **2 slechte trades** (−1 per coin); de beste robuuste combo **3** (DOGEAI −1, NOS −2), telkens met
   0 goede verloren. Klein, maar echt en cross-snapshot bevestigd.
2. **Aanbevolen (indien toegepast, achter dezelfde refire-gate):** rule 21
   `trigger_minimal_volume_relative` van 0.03 naar **~0.022–0.024**. Cross-coin én cross-snapshot robuust,
   directe `volume_check`-afwijzing, plateau (geen knife-edge), 0 goed verloren. Optioneel +1 NOS via de
   schone combo met `minimal_rows_to_analyse=7` (aparte, directe NOS-verwijdering) — één-coin-component,
   OOS-ongetest, dus alleen mee als je de extra NOS-drop wilt; níet de `maximal_relative_volume`-variant.
3. **Niet aanbevolen zonder meer coins:** de rule 20/22/23 één-coin-moves. Veilig-maar-marginaal en
   OOS-fragiel; pas hooguit als gemonitorde pilot toe, niet auto-apply.
4. **Let op — substitueerbaar met de tightening-routine.** De auto-apply routine verlaagde tijdens de
   analyse zelf al slecht (127 → 124 op DOGEAI) door subrules aan te scherpen. Als die routine later de
   datetimes `2025-02-15 15:43:46` / `2024-01-02 03:31:00` óók afvangt, verdampt de marginale
   volume-param-winst. Nu zijn ze additief; meet vóór toepassen opnieuw of de doel-fires nog bestaan.
5. **Mogelijke routine in de Rule-precisie set:** een `volume-param-tuning` routine die per rule de
   `_VOLUME_OVERRIDES` coördinaat-sweept (+ pairwise) en alleen **cross-coin-robuuste** moves voorstelt,
   achter exact de auto_apply refire-gate (0 goed verloren, totaal slecht strikt omlaag), is haalbaar —
   de harness draait een volledige sweep in seconden. Houd hem **propose-only** tot er >2 coins zijn
   (overfit), draai hem **na** (niet tijdens) de tightening-routine, en meet binnen één snapshot.

---

## Reproduceerbaarheid

```
PY=/Users/daanvantongeren/Documents/Sites/brain/engine/.venv/bin/python
cd engine/src
$PY volume_sweep.py probe                 # valideer: reproduceert coin_fires-baseline exact
$PY volume_sweep.py report                # autoritatieve single-snapshot run -> out/opt/volume_sweep_report.json
$PY volume_sweep.py finegrid 21 trigger_minimal_volume_relative 0.016 0.030 8
$PY volume_sweep.py diag 21 trigger_minimal_volume_relative 0.022     # mechanisme (direct vs dedup)
$PY volume_sweep.py pairgrid 21 trigger_minimal_volume_relative minimal_rows_to_analyse
```

Artefacten: `engine/src/volume_sweep.py` (harness), `engine/out/opt/volume_sweep_report.json`
(autoritatief), `volume_sweep_rule*.json`, `volume_descent_rule*.json`, `volume_oos_rule*.json`,
`finegrid_r21.json`, `diag_*.log`. **Dit rapport wijzigt niets.**
