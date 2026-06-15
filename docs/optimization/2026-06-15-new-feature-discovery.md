# Nieuwe-berekeningen ontdekking — kunnen NIEUWE features de muur van rule 21 doorbreken? (15 jun 2026)

> **Read-only analyse. Er is NIETS toegepast** — geen rule, database, engine of cache gewijzigd. Dit
> rapport zoekt NIEUWE deterministische berekeningen (features) die goede van slechte trades scheiden
> beter dan de bestaande 31, en meet hun échte meerwaarde via de engine-gate. Workflow: 4 parallelle
> familie-ontdekkingsagents → één geconsolideerde engine-gate tegen één bevroren snapshot → adversariële
> verificatie per kandidaat → synthese (65 agents). Plus een onafhankelijke ML-diagnose (tree cross-coin AUC).

## De vraag en de lat

Hoofddoel: **de muur van RULE 21 doorbreken** (79 goed / 68 slecht, ratio **1.16**; succes vergt
`#goed ≥ 2×#slecht`, dus ~**57 slecht-equivalenten** weg: 2×68−79). Secundair: rules 20/22/23 versnellen.
De bestaande 31 features zijn losse **single-indicator venster-statistieken**; de gemiste edge is
vermoedelijk **multivariaat/dynamisch**. Dus zochten we in vier families:

- **A interactie** — volume×momentum, divergentie prijs-vs-indicator, cross-indicator
- **B vorm/dynamiek** — helling, versnelling, kromming, "al-gepumpt", clean-vs-choppy (prijs-dynamiek is
  echt nieuw: de 31 rekenen op indicator-wáarden, niet op prijsbeweging)
- **C context/regime** — afstand tot recente high, volatiliteitsregime, positie binnen de move
- **D sequentie** — volume-lead-vs-lag, kort-vs-lang lookback (versnelt het momentum de entry in?)

**Vaste definities:** GOED = executed `best_upside ≥ 3%`; SLECHT = executed `< 0.5%`; `best_upside` is het
LABEL (nooit een feature → geen leakage). Coins: **DOGEAI = 2525**, **NOS = 244**. Bindende lat = de
count-ratio (de magnitude-lat ≥3× is overal al gehaald).

---

## Het antwoord, in één zin

> **Nee — geen enkele nieuwe feature doorbreekt of dent de muur van rule 21 betekenisvol.** De sterkste
> bevestigde cross-coin rule-21 hefboom verwijdert **4 van de ~57** benodigde slecht (~7%). De bindende
> beperking is het **2-coin universum**, niet een ontbrekende feature.

Dit is een **schoon negatief resultaat voor de muur**, met een handvol kleine, écht-bevestigde bijvangsten.

---

## Wat de zoektocht opleverde (de trechter)

124 kandidaat-features (4 families × 6 lookbacks + short-vs-long paren), elk een leak-vrije as-of scalar
over de RAW engine-reeks. De trechter, met de eerlijke afval per stap:

| Stap | Methode | Overlevenden |
|------|---------|--------------|
| Cache-scheiding (Cohen's d + bad-edge + OOS) | overschat fors (zoals gedocumenteerd) | ~60 met in-sample drop |
| **Engine-gate** (in-memory full-refire, dedup, best_upside) | totaal goed behouden ÉN totaal slecht strikt omlaag | **59 "passen"** |
| **Adversariële verify** (5 aanvallen per kandidaat) | overfit / één-coin / dedup-wash / leakage / knife-edge | **6 survives** · 26 weak · 27 refuted |

**De engine-gate "passeren" betekent weinig.** 59 van 60 kandidaten halen de gate (slecht omlaag, 0 goed
verloren) — maar de adversariële verify ontmaskert **27 als knife-edge/één-coin overfit** en degradeert
**26 tot "weak"** (echt maar piepklein/één-coin). Slechts **6** zijn cross-coin robuust, direct (geen
dedup-wash), leak-vrij, met een **stabiel threshold-plateau** en 0 goed verloren.

---

## De 6 bevestigde survivors (echte nieuwe berekeningen, vrij te shippen)

Alle 6: 0 goed verloren, drops zijn **direct** (de subrule doodt de fire, geen dedup-reshuffle),
cross-coin (slecht omlaag op **beide** coins), gespreid over data (géén 2025-03-19-achtige same-day-overfit),
leak-vrij (geverifieerd in de code), en een stabiel plateau over ±20% threshold.

| # | feature | familie | rule | DOGEAI slecht | NOS slecht | pooled | wat het meet |
|---|---------|---------|------|---------------|------------|--------|--------------|
| 1 | `volmom_vzo_lb7` | interactie | **21** | −3 | −1 | **−4** | volume-spike × vzo-momentum (relvol₀·z-slope) |
| 2 | `price_maxstep_lb7` | vorm | **21** | −2 | −1 | **−3** | grootste enkele prijssprong in 7 (al-gepumpt) |
| 3 | `volmom_price_lb10` | interactie | 22 | −1 | −3 | **−4** | volume-spike × prijs-momentum |
| 4 | `volmom_vzo_lb10` | interactie | 22 | −1 | −3 | −4 | idem (**redundant** met #3 — zelfde 4 trades) |
| 5 | `price_volregime_lb20` | context | 22 | −1 | −3 | **−4** | korte/lange prijsvolatiliteit-ratio |
| 6 | `price_zslope_lb7` | vorm | 22 | −1 | −2 | **−3** | OLS-helling van de prijs (z-genormaliseerd) |

**Belangrijk:** de families hádden gelijk dat er een multivariaat/vorm/regime-signaal zit dat de 31 missen
— `volmom_*` (interactie), `price_maxstep`/`price_zslope` (prijsvorm) en `price_volregime` (regime) zijn
allemaal nieuwe berekeningen die de bestaande set niet kent. Maar het zijn **bakstenen, geen muur-breker**.

**Effect op de ratio's** (per-rule, als losse tightening):
- rule 21 + `volmom_vzo_lb7`: 79g/68b → 79g/**64b**, ratio 1.16 → **1.23** (doel 2.0).
- rule 22 + `volmom_price_lb10`: 113g/83b → 113g/**79b**, ratio 1.36 → **1.43**.

De 4 sterkste survivors (op robuustheid) zitten op **rule 22** (die al bijna gezond is), niet op de muur.

---

## Waarom de muur niet valt — twee onafhankelijke bewijzen

### 1. De engine-gate: de hefboom is te klein, en niet op te schroeven

Rule 21 moet ~57 slecht-equivalenten kwijt. De twee bevestigde rule-21 survivors verwijderen 4 en 3, en
ze **overlappen zwaar** (bijv. de NOS-trade 2024-12-02 06:24:48 komt terug bij meerdere kandidaten). Een
optimistische niet-overlappende stack van élke bevestigde rule-21 hefboom haalt **~6–8 slecht** (~10–14%
van 57). En het is **niet op te schroeven**: elke poging de drempel te verruimen voor méér drop begint
direct goede trades te slachten — precies wat de 27 refuted knife-edges aantonen (bijv. `price_volregime_lb20`
verliest 16 goede bij −20% threshold; `price_vsmean_lb5` verliest er 17; `price_zslope_short_minus_long`
verliest 30 goede voor 15 slecht).

### 2. De ML-diagnose: geen cross-coin generaliserende edge (alleen diagnose, geen handelaar)

Een HistGradientBoosting-boom (max_depth 2) op goed-vs-slecht met **alle 122 nieuwe features**, getest
cross-coin (train op één coin, test op de andere — de echte generalisatie-toets):

| rule | n | DOGEAI g/b | NOS g/b | D→N AUC | N→D AUC | pooled 5-fold (in-sample plafond) |
|------|---|-----------|---------|---------|---------|-----------------------------------|
| 20 | 117 | 47/27 | 29/14 | **0.660** | 0.607 | 0.734 |
| **21** | 147 | 63/49 | 16/19 | **0.569** | **0.500** | 0.652 |
| 22 | 196 | 57/38 | 56/45 | 0.542 | 0.542 | 0.584 |
| 23 | 52 | 10/6 | 21/15 | n.v.t. | 0.500 | 0.658 |

*(0.5 = toeval; >0.65 = echte generaliserende edge.)*

- **Rule 21 (de muur): 0.569 / 0.500** — nauwelijks signaal. De kloof in-sample (0.652) vs cross-coin
  (~0.53) is precies de **overfit-kloof**: het signaal transfereert niet. Zelfs álle 122 features samen
  in een flexibele boom scheiden goed/slecht op de andere coin niet beter dan toeval. Dit is het
  kwantitatieve bewijs dat geen nieuwe feature de muur met 2 coins doorbreekt.
- **Alleen rule 20** draagt een echte cross-coin edge (0.66/0.61) — consistent met "rule 20 is dichtbij"
  (need 6). Tóch ving géén enkele losse feature die edge robuust (alle sterke rule-20 magnitude-kandidaten
  werden als knife-edge **refuted**) — een multivariate edge die geen univariate drempel pakt. Dat is
  exact de oorspronkelijke `feature_query`-bevinding ("edge is multivariaat").
- **Rule 22**: 0.542 — geen generaliserende edge; zelfs het in-sample plafond is laag (0.584). De 4
  rule-22 survivors zijn dus echte maar dunne randverbeteringen, geen structurele scheiding.
- **Rule 23**: te dun (52 trades) — geen betrouwbaar signaal.

Beide bewijzen wijzen dezelfde kant op: de refutaties traceren consequent naar **(1) één-coin-effecten**
(een drop op DOGEAI terwijl NOS inert is, met 2 coins niet te onderscheiden van ruis) en **(2) knife-edge
drempels** gefit op een handvol specifieke trades (instorten tot no-op bij +20%, slachten goede bij −20%,
en lopen al van hun sweet-spot af als `brain` muteert tussen snapshots: 42fa1ec → bb23fbfa → c17ac30b,
NOS-baseline dreef 93→88 slecht). Beide zijn **sample-grootte-problemen**.

---

## Per rule

- **Rule 20 (need 6) — dichtbij, maar geen losse feature pakt het.** De boom toont de beste cross-coin
  edge (AUC 0.66), maar elke losse rule-20 kandidaat is óf piepklein (−1/−2 per coin) óf knife-edge. Beste
  bevestigde "weak": `vol_accel_lb5` / `vol_ratio_short_long_3_14` (−1 per coin, cross-coin, 0 goed verloren).
  De RQ1-tightening-routine sluit de gap van 6 waarschijnlijk sneller en veiliger dan een nieuwe feature.
- **Rule 21 (need 57) — de muur valt niet.** Beste: `volmom_vzo_lb7` (survives, −4 pooled) en
  `price_maxstep_lb7` (survives, −3). Geen feature verwijdert meer dan 4 slecht cross-coin. Niet als
  "bijna goed" verkopen — het is ~7% van de gap, op een smalle basis (4 van 5 drops DOGEAI op 2 dagen).
- **Rule 22 (need 53) — de sterkste survivors zitten hier, maar het is niet de muur.** `volmom_price_lb10`
  en `price_volregime_lb20` (elk −4, breed plateau, overleefden zelfs snapshot-drift) plus `price_zslope_lb7`
  (−3). Echt bruikbaar als vrije tightenings, maar 53 nodig en de AUC bevestigt geen structurele edge.
- **Rule 23 (need 11) — dood spoor hier.** Niets overleeft cross-coin op schaal; alle kandidaten wassen
  weg tot één trade of werden ge-refuted.

---

## Eerlijke conclusie

1. **De muur van rule 21 wordt niet doorbroken door een nieuwe berekening.** De beste bevestigde features
   verwijderen 4 en 3 slecht (~7% en ~5% van 57), overlappen zwaar, en zijn niet op te schroeven zonder
   goede trades te slachten. Een stack van álles haalt ~10–14% van de gap. De ML-diagnose bevestigt: geen
   cross-coin generaliserende edge op rule 21 (AUC 0.50–0.57).
2. **6 echte, vrij-te-shippen micro-tightenings bestaan** (0 goed verloren, direct, cross-coin, stabiel
   plateau, leak-vrij): 2 op rule 21 (`volmom_vzo_lb7`, `price_maxstep_lb7`) en 4 op rule 22
   (`volmom_price_lb10`/`volmom_vzo_lb10` [houd er één], `price_volregime_lb20`, `price_zslope_lb7`). Ze
   valideren de hypothese dat de gemiste edge multivariaat/vorm/regime is — maar het zijn bakstenen. Als ze
   ooit toegepast worden: achter exact de `auto_apply`-refire-gate (0 goed verloren, totaal slecht strikt
   omlaag), max één per rule (principe 1), en níet de redundante zustervariant.
3. **Meer data is de echte vereiste, niet nóg een feature.** Het bindende knelpunt is het 2-coin universum.
   Met ~213 gepoolde slecht over 2 coins kan de gate een echte scheider niet onderscheiden van een gelukkige
   snede, en een 57-slecht-gap is niet te dichten met features die elk 1–4 écht-cross-coin trades vinden.
   Meer coins zouden (a) cross-coin robuustheid betekenis geven (3+ coins die corroboreren), (b) de plateaus
   verbreden zodat drempels geen knife-edges meer zijn, en (c) het slecht-volume leveren dat nodig is om
   tientallen (niet enkele) slecht te verwijderen. **Aanbeveling: stop met het zoeken naar nieuwe
   deterministische features tegen deze 2-coin set; voeg coins/periodes toe.**

---

## Addendum — de "keeper"-lens: standalone discriminerende kracht over ALLE trades

De engine-gate hierboven meet **marginale** waarde (een feature als subrule áán één rule, na dedup) — dat
straft overlap af: vangt een andere rule de slechte al, dan is het marginale effect klein. Een aanvullende,
even waardevolle vraag (Daans inzicht, zo werd skewness gevonden): laat een berekening los over **alle**
goede vs **alle** slechte executed trades (alle rules, beide coins); als hij puur qua **aantal** veel slecht
apart zet — ook al vangen andere rules die deels al af — dan is het een berekening om te **bewaren** voor de
toekomst (nieuwe rules, nieuwe coins, de labeler, ML). Artefact: `engine/src/pooled_keepers.py`
→ `engine/out/opt/pooled_keepers.json`. Populatie (huidige executed-set, ná verdere routine-tightening):
~293 goed / ~208 slecht.

**Bevinding 1 — zelfs pooled zijn de aantallen klein, voor álles.** De hoogste in-sample pooled drop is **6**;
cross-coin robuust **2–5** — voor de nieuwe features ÉN de bestaande 31. De executed slechte trades die de
rules al doorlaten zijn **individueel moeilijk**: geen enkele berekening (oud of nieuw) zet een grote brok
apart terwijl alle goede behouden blijven. Dat onderschrijft de hoofdconclusie (meer data, niet nóg een drempel).

**Bevinding 2 — skewness als benchmark klopt, maar is ook bescheiden in absolute telling.** `vzo/skewness/lb20`
komt boven (pooled 4), naast `mfi/sideways_lower`, `phobos/average_reversal_size`. De methode werkt; op deze
gefilterde populatie zijn de getallen gewoon klein.

**Bevinding 3 — de nieuwe features zijn keeper-waardig náást de beste bestaande calcs** (hoog cross-coin
aantal, goed-behoud ~0.99):

| nieuwe berekening | familie | pooled weg | cross-coin robuust | goed-behoud |
|---|---|---|---|---|
| `volmom_price_lb10` (volume×prijs-momentum) | interactie | 6 | **4** | 0.99 |
| `price_volregime_lb20` (vol-regime) | context | 6 | 2–4 | 0.99 |
| `volmom_vzo_lb10` (volume×vzo) | interactie | 4 | 2 | 1.00 |
| `div_price_vzo_lb7` (prijs-vzo divergentie) | interactie | 4 | 2 | 0.99 |

`volmom_price_lb10` evenaart op cross-coin telling álles in de bestaande 31 → **volume×momentum en het
volatiliteits-regime zijn keeper-waardige nieuwe berekeningen** (bewaren voor de toekomst), ook al is hun
marginale gate-waarde nu klein.

**Bevinding 4 — aantal ≠ Cohen's d.** `price_maxstep` heeft de hoogste d (0.48–0.50) maar **cross-coin
telling 0**: het scheidt in verdeling maar transfereert geen schone telling. Op het aantal-criterium is
`volmom_price` dus een betere keeper dan `price_maxstep`. De twee maatstaven zijn het oneens — kies de telling.

**Bevinding 5 — val: de volumeud-level metrics ogen sterk (`highest_value`/`standard_deviation`, pooled 5–6)
maar zijn engine-schaal-onveilig** (cache = relatief volume, engine = raw → inert als ruwe subrule, zie
[[volumeud-cache-engine-scale]]). Houden als discriminator voor ML/labeler, **niet** als losse engine-subrule.

---

## Reproduceerbare artefacten (read-only, muteren niets)

```
PY=/Users/daanvantongeren/Documents/Sites/brain/engine/.venv/bin/python
cd engine/src
$PY new_feat_lib.py 2525                       # 124 kandidaat-features, leak-vrije as-of self-test
$PY new_feat_discover.py all <familie> 30      # cache-stijl ranking (Cohen's d + bad-edge + OOS) per rule
$PY new_feat_gate.py probe                      # valideert: reproduceert coin_fires-baseline exact
$PY new_feat_gate.py one 21 price_maxstep_lb7 lower 0.085   # engine-gate één kandidaat + mechanisme-diag
$PY new_feat_gate.py batch <cands.json> <out.json>          # engine-gate een lijst tegen één snapshot
$PY pooled_keepers.py 30                        # KEEPER-lens: standalone bad-telling over ALLE trades (nieuw + 31)
```

- `engine/src/new_feat_lib.py` — de 124 nieuwe features (4 families), elk een leak-vrije as-of scalar over
  de RAW engine-reeks, schaal-vrij voor volume (within-window ratio's, nooit raw level → cross-coin).
- `engine/src/new_feat_discover.py` — read-only cache-stijl ontdekking (overschat; alleen een filter).
- `engine/src/new_feat_gate.py` — de **echte** in-memory full-refire engine-gate (alle rules 20-23, dedup,
  best_upside; gevalideerd dat hij `coin_fires` exact reproduceert), met direct-vs-dedup mechanisme-uitsplitsing.
- Ranking-output: `engine/out/opt/new_feat_<rule>_<familie>.json`. Workflow-resultaat (60 gate-passers →
  6 survives): de gestructureerde return in de workflow-transcript.

*Dit rapport wijzigt niets. Het stelt 6 vrije micro-tightenings vast (geen muur-breker) en concludeert dat
meer data — niet nóg een feature — de echte vereiste is om rule 21's count-lat te halen.*

---

## Critical-eye correcties (15 jun 2026)

Een onafhankelijke sceptische review bevestigde de **kern-conclusie als solide** ("de muur valt niet,
hefboom = meer data") en verifieerde het sterkste deel: **geen leakage** (elke feature is strikt as-of,
≤ buy-moment; best_upside is uitsluitend label) en de gate is **persist-equivalent** (reproduceert
`coin_fires` exact). Maar drie presentatie-claims kloppen niet en zijn hierbij gecorrigeerd:

1. **De 6 survivors zijn GEEN "directe, schone" kills.** De twee rule-21 survivors (`volmom_vzo_lb7`,
   `price_maxstep_lb7`) zijn netto −3/−4 mét **1 dedup-reshuffle + 1 nieuw-gecreëerde bad** per stuk. De
   claim "de subrule doodt de fire direct (geen dedup-reshuffle)" is voor deze twee weerlegd door de gate
   zelf. **Gate-fix doorgevoerd:** `cross_coin_robust` eist nu `new_bad == 0` per coin
   (`new_feat_gate.py`) — daarmee vallen de twee rule-21 survivors terug naar "weak".
2. **"Cross-coin robuust" leunt op ruis.** De NOS-kant is telkens −1 op een sample van 19 (één trade); de
   DOGEAI-kant van `volmom_vzo_lb7` is 4 drops op slechts 2 dagen (same-day-cluster = overfit-signaal dat
   het rapport elders zelf noemt). Het verschil tussen "survivor" en "weak" is hier dun.
3. **Keeper-dubbeltelling:** `volmom_vzo_lb10` (#4) pakt dezelfde 4 trades als `volmom_price_lb10` (#3) —
   het zijn **3 unieke keepers**, niet 4. Houd er één.
4. **Rule 23 is stale in dit rapport** (31g/21b → need 11): de auto-apply routine heeft 'm intussen naar
   **31g/16b (ratio 1,94)** gebracht — geen "dood spoor" maar door de routine opgelost.

**Eindoordeel (doorgevoerd): niets shippen als engine-subrule.** De rule-21 survivors zijn ruis op de
muur (netto −3 op een gap van 57, deels reshuffle, één-NOS-trade); de rule-22 survivors zijn klein op een
rule die de routine al draagt. Diminishing-returns (elke verwijderde slechte → er weer 1) + principe 1
maken de kost groter dan de baat. **Wél behouden: de 3 unieke keepers** (`volmom_price_lb10`,
`price_volregime_lb20`, `div_price_vzo_lb7`) als ML/labeler-features voor later — níet als ruwe subrule.
