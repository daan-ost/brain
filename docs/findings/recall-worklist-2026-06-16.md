# RECALL-worklist: meting-fix + gerichte 1-voor-1 tweak-analyse

**Datum:** 2026-06-16
**Scope:** de twee brain-coins DOGEAI (2525) en NOS (244). Read-only op `brain.rules`/`coin_fires` —
er is **niets toegepast**; alle tweaks zijn PROPOSALS achter de gate.
**Artefacten:** `engine/src/recall_worklist.py` (gefixte meting), `recall_shadow.py` (loosen-shadow),
`recall_loop.py` (cumulatieve bounded tweak-loop), `recall_verify.py` (onafhankelijke DiagEngine-kruiscontrole),
`brain.promising_recall_state` (het dossier), `engine/out/opt/recall_loop.json`.

---

## TL;DR

1. **De recall-meting had een echte fout** en is gefixt vóór er op cijfers gestuurd is (zoals gevraagd).
   Twee oorzaken van valse "gemist":
   - **(B) covered-by-open-position** — een stijging waar we al ín zaten (een executed positie liep over de
     groep) werd "gemist" geteld omdat de v1-`caught` alleen een executed *buy* binnen ±3min checkte.
   - **(A) no_candidate (vf=0)** — de engine vuurt alleen op `volume_found=1` ticks; `subrule_status` past die
     gate niet toe, dus een vf=0 ok-moment toonde "0 falende subrules" maar kan nooit vuren.
   Gecorrigeerde recall: **DOGEAI 57%→64%**, **NOS 8%→11%**.
2. **NOS mist vooral STRUCTUREEL, niet door rule-precisie.** ~73% van de gemiste NOS-groepen is `no_candidate`:
   de ok-momenten liggen niet op een candidate-tick (en er is geen vf=1 tick in de buurt). Geen subrule-tweak
   kan een moment vangen dat geen candidate is. Dit is een `volume_found`-ingestion-kwestie (engine-niveau),
   niet iets wat rule-tuning oplost.
3. **5 gemiste promising-groepen zijn veilig vangbaar** via een bounded loosen-stack (max 3 wijzigingen per groep),
   voor **+7 goede / +3 slechte** trades over beide coins — de pooled good/bad-ratio gaat **1,438 → 1,450**
   (licht omhóóg). Onafhankelijk bevestigd via een tweede engine (DiagEngine): fire-sets identiek, good/bad exact gelijk.
   (Een eerste telling van 6 bevatte één meet-artefact dat de adversariële verificatie ving — zie STAP 2.)
4. **De rest is een expliciete recall-vs-precisie tradeoff.** 29 groepen zijn vangbaar maar te duur (de loosen
   spoelt nieuwe slechte trades binnen); 4 zijn met geen bounded tweak veilig te vangen (`needs_new_rule`).
   **Vrijwel alle dure gevallen homen op rule 21** — r21 kan niet geloosend worden zonder de precisie te slopen.
5. **Belangrijkste voorbehoud (eerlijk):** de +7/+3 is **in-sample**. De loosens zetten de band op exact de
   doelwaarde ±EPS en de collateral is gemeten op dezelfde volledige historie waaruit de banden komen. De
   **echte out-of-sample slecht-telling is vrijwel zeker >3**; +7/+3 is een optimistische ondergrens, geen
   voorspelling. Vóór toepassing eerst een walk-forward/holdout-split draaien (zie Verificatie).

---

## STAP 0 — Datakwaliteit: het "0 falende subrules maar geen trade"-artefact

De gevraagde casus **2025-03-04 03:42:33 (rule 21, 0 falende subrules, geen trade)** bleek een **dedup-artefact**,
geen feature-gap. De rule vuurde wél (om 03:40:11 én 03:42:33), maar beide fires waren `is_executed=0` shadows:
er stond al een executed r21-positie open (buy 03:27:03, best_upside 17,6%, open tot 03:47:28) — **we zaten al
in die stijging** (P&L +1,1%). De v1-meting telde dit als "gemist" omdat de dekkende buy >3min vóór de groep-lead lag.

Breder uitgezocht over álle gemiste groepen, twee aparte oorzaken voor `fails=0`:

| Oorzaak | Wat | DOGEAI | NOS |
|---|---|---|---|
| **A — vf=0 snap** | ok-moment ligt op een volumeud-tick met `volume_found=0` → nooit een candidate; `subrule_status` toont misleidend 0 fails | 6 | **135** |
| **B — covered** | rule vuurt/positie staat open over de groep, maar buiten het ±3min `caught`-venster | 9 | 6 |

**Conclusie:** de recall-meting zelf was fout. Gefixt in `recall_worklist.py` (zie STAP 1) vóór er op de cijfers
gestuurd is. Determinisme gecontroleerd (groepering 3× stabiel in één proces); de labels groeien live tijdens
het labelen (NOS ok-momenten 231→303 tijdens deze sessie) — de worklist is daarom expliciet her-runbaar.

---

## STAP 1 — De gefixte worklist (`promising_recall_state`)

`recall_worklist.py` herrekent per promising-groep (ok-momenten gegroepeerd zoals `PromisingLabeler.php`:
>5min gap OF ≥1% drop OF handmatige `group_break`). De drie meting-fixes:

- **caught via open-positie-overlap** — caught=1 als een executed positie `[buy, selling_datetime]` de groep
  overlapt (blocker `covered`), niet alleen bij een executed buy binnen ±3min (blocker `direct`).
- **re-snap naar vf=1 candidate** — de groep-lead wordt naar de dichtste `volume_found=1` tick binnen het
  groep-venster ±3min gesnapt (de tick die de engine echt zou evalueren). Geen vf=1 in de buurt → `no_candidate`.
- **home_rule_fails telt alleen NON-volume fails** op de candidate-tick (de loosenbare subrules); volume is een
  eigen blocker (`volume`) als élke rule's `volume_check` faalt.

De routine-velden (`status`/`tried`/`resolution_note`) overleven re-runs (dossier carry-over via span-overlap
`inherit()`), zodat het dossier accumuleert.

### Gecorrigeerde recall

| coin | groepen | gevangen | recall (oud → nieuw) | no_candidate | volume | feature |
|---|---|---|---|---|---|---|
| DOGEAI (2525) | 107 | 69 (61 direct + 8 covered) | **57% → 64%** | 6 | 6 | 25 |
| NOS (244) | 199 | 20 (14 direct + 6 covered) | **8% → 11%** | **135–149** | 0 | ~36–61 |

(NOS-getallen schuiven met de live groeiende labels; de structuur is stabiel.)

---

## De NOS-bevinding (waarom NOS zoveel mist)

| | DOGEAI | NOS |
|---|---|---|
| ok-momenten | 227 | 231–303 |
| op een `volume_found=1` candidate-tick | **191 (84%)** | **45 (20%)** |
| vf≠1 én geen vf=1 tick binnen 30s | 0 | ~170 |

**~80% van de NOS ok-momenten ligt niet op een candidate-tick** en heeft geen vf=1 tick in de buurt. De
buy-rules (20-23) worden door `rule_engine.fires` alleen op `volume_found=1` ticks geëvalueerd, dus daar kan
**geen enkele regel ooit vuren** — los van welke band je loosened. Dit is de dominante NOS-blocker
(`no_candidate`) en verklaart de lage NOS-recall. Het is een `volume_found`-ingestion/detectie-kwestie op
engine-niveau (hoe candidate-ticks voor de langzame coin worden gemarkeerd), **niet** een rule-precisie-probleem.
→ Geparkeerd als conclusie voor een aparte routine/epic (zie STAP 3); buiten de scope van subrule-tweaks.

**Onafhankelijk geverifieerd (adversariële agent, eigen herberekening, niet via de worklist):** 303 NOS
ok-momenten, slechts **55 (18,2%) op een vf=1 tick**; 215 hebben geen vf=1 binnen ±180s. Op groep-niveau
(199 NOS-groepen): **143 = 71,9% `no_candidate`** (en 79,9% onder de níet-gevangen groepen). Scepticus-check
op de snap-tolerantie: bij strákkere tolerantie (0–120s) stíjgt het naar 77–83%; ±180s is de meest royale
redelijke instelling en geeft nog 71,9% — de conclusie is dus **conservatief, niet opgeblazen**. DOGEAI is het
spiegelbeeld (84% op vf=1, 6,5% no_candidate), wat bevestigt dat dit een NOS-specifieke structurele eigenschap is.

---

## STAP 2 — Bounded, 1-voor-1, cumulatieve tweak-loop

**Shadow-harness (`recall_shadow.py`).** Een snelle in-memory full-refire die feature-subrule band-LOOSENING
met cross-rule single-position dedup + best_upside ondersteunt (de loosen-tegenhanger van `new_feat_gate`/
`volume_sweep`). Gevalideerd: de lege override reproduceert `brain.coin_fires` **exact** op beide coins
(DOGEAI 177g/120b/475exec, NOS 122g/88b/384exec). Pooled baseline **good=299, bad=208** (ratio 1,438).

**De loop (`recall_loop.py`).** Per genuine feature-gemiste groep (38 doelen, fails 1-3, op upside gesorteerd),
cumulatief: loosen de falende feature-subrule(s) van de home-rule net genoeg om de groep te vangen (≤3
wijzigingen), lees de shadow, en **accepteer** als: groep gevangen **EN** incrementeel ≤1 nieuw slecht **EN**
de pooled ratio zakt niet onder 98% van baseline (cumulatieve rem). Geaccepteerde loosens stapelen, zodat een
latere groep op een al-verruimde band kan meeliften en de rem de volledige collateral ziet.

### Resultaat: 5 groepen gevangen, netto precisie-winst

| coin | groep-lead | upside | home | loosen | +goed | +slecht |
|---|---|---|---|---|---|---|
| DOGEAI | 2025-03-04 15:08 | 10,4% | r20 | mfi/currentvalue/lb2 b_min 39,1→38,7 | +1 | +1 |
| DOGEAI | 2025-02-22 17:03 | 8,9% | r22 | phobos/range_percentage/lb12 b_max 508,33333→508,33346 | +1 | 0 |
| NOS | 2024-02-11 07:41 | 5,5% | r22 | phobos/previous_value/lb3 b_min −5,9→−6,5 | +2 | +1 |
| NOS | 2023-12-10 19:09 | 3,3% | r22 | phobos/previous_value/lb3 b_min −6,5→−7,1 (zelfde subrule, stapelt) | +1 | 0 |
| NOS | 2023-12-08 04:15 | 31,0% | r20 | obv-x-value/phobos lb (2 subrules) | +2 | +1 |

> **Adversariële vondst (niet verbergen):** een eerste telling claimde 6 gevangen groepen. De onafhankelijke
> verificatie (agent 2, via DiagEngine) toonde dat de 6e — NOS r23, lead 2023-12-06 20:50 — niet door de stack
> vuurt: hij werd alleen "gevangen" via de ±180s-tolerantie van `caught_at()` die een *baseline*-fire (21:00:40)
> raakte — dekking die al in de baseline bestond, dus niet veroorzaakt door de tweak. Dit was een **verborgen
> recall-inflatie**. Gefixt in `recall_loop.py` (caught-by-stack telt alleen als de groep onder de stack gevangen
> is ÉN niet al in de lege baseline) → de eerlijke telling is **5**.

**Geaccepteerde stack (PROPOSAL, niet toegepast):**
```json
{"20": {"21": [38.70, 87.6], "13": [null, 21.90], "18": [null, 51.00]},
 "22": {"21": [null, 508.33346], "42": [-7.10, null]}}
```
(keys: `rule → {subrule_index → [b_min, b_max]}`.)

**Recall-vs-precisie (expliciet):**
- **Recall-winst:** 5 gemiste promising-groepen worden gevangen.
- **Precisie-kost:** pooled good **299 → 306 (+7)**, bad **208 → 211 (+3)**, ratio **1,438 → 1,450**.
- De ratio gaat licht **omhoog** (meer goed dan slecht erbij) en er gaat **0 goede verloren**, dus **geen
  verborgen precisie-verlies binnen deze (in-sample) meting**. De +3 slechte trades zijn de eerlijk benoemde
  prijs; ze zijn echt `best_upside<0,5`. **Let op:** deze meting is in-sample (zie Verificatie) — out-of-sample
  ligt de slecht-telling vrijwel zeker hoger.

**Onafhankelijke verificatie (`recall_verify.py` + adversariële workflow).** Drie onafhankelijke checks:
- `recall_verify.py`: met de aparte, oracle-gevalideerde `DiagEngine` (de engine die auto_loosen/rq2 gebruiken)
  opnieuw doorgerekend — per coin, per rule de fire-set onder de stack **identiek** aan de shadow; een vanaf-nul
  herschreven dedup geeft exact dezelfde **306/211**. → **ALLES KLOPT=True** op beide coins.
- Adversariële agent (DiagEngine, eigen script): **5 van de 6** kandidaat-groepen vuren echt onder de stack, de
  **3 nieuwe slechte trades hebben echt `best_upside<0,5`**, en **0 goede verloren**. De 6e bleek een meet-artefact
  (zie boven) — verdict *partial*, wat de telling 6→5 corrigeerde.
- Adversariële agent (NOS-claim, eigen herberekening): zie de NOS-bevinding hieronder.

---

## STAP 3 — Parkeren

- **`needs_new_rule` (4 groepen):** geen bounded loosen (≤3) vangt ze zonder de gate te schenden. Bv. NOS
  2024-02-11 01:14 (up 0,25% — nauwelijks de moeite) en NOS 2023-12-15 19:44 (r21, f2). Geparkeerd als
  conclusie voor een latere nieuwe-rule-routine (buiten scope).
- **`rejected_collateral` (29 groepen):** de tweak vangt de groep wél, maar kost >1 nieuw slecht of laat de ratio
  zakken. **De duurste gevallen homen vrijwel allemaal op rule 21:** loosenen om één gemiste groep te vangen
  spoelt 50-85 nieuwe slechte trades binnen (ratio crasht naar ~1,19-1,26). Voorbeelden: DOGEAI 2025-03-09 20:28
  (+50g/+85b), DOGEAI 2025-02-20 07:39 (+48g/+79b), DOGEAI 2025-02-14 15:45 up 31,6% (+38g/+61b). **Conclusie:
  rule 21 is de "muur"** — de hoog-upside gemiste groepen die op r21 homen zijn niet via een band-loosen te
  vangen; ze vragen een aparte (nauwere) rule die díe stijging isoleert. Een cleanup-tightening (STAP 2c: elders
  aanscherpen om het nieuwe slecht weg te halen) is de volgende stap voor de net-niet-gevallen `feature`-gevallen.
- **`no_candidate` (135-149 groepen, vooral NOS):** onverhandelbaar via rule-tweaks — eerst de `volume_found`-
  candidate-detectie op engine-niveau aanpakken (zie de NOS-bevinding).

---

## Verificatie & bekende beperkingen

De cijfers zijn langs drie onafhankelijke wegen gecontroleerd (een adversariële multi-agent workflow + `recall_verify.py`):

- **Harness-fidelity (code-review + probe):** `RecallEval.evaluate({})` reproduceert `coin_fires` exact
  (299g/208b pooled) — de single-position dedup, `best_upside`, de `volume_found` candidate-gate en de
  lazy volrows-cache zijn getrouw. De speed-trick (een feature-loosen verandert alleen de PASS van die ene
  subrule) is correct; EPS-loosen, `merge_stack` (most-permissive union) en `covered_by_hold` kloppen.
- **Engine-kruiscontrole:** fire-sets onder de stack zijn identiek tussen de shadow en de aparte DiagEngine;
  een vanaf-nul herschreven dedup geeft exact 306/211. De +7g/+3b is dus dubbel bevestigd.
- **NOS-claim:** onafhankelijk herrekend op 71,9% no_candidate, robuust onder snap-tolerantie.

**Gevonden en gefixt (HIGH):** de eerste telling "6 caught" bevatte 1 meet-artefact — de `caught_by_stack`
final-pass trok de baseline niet af, waardoor NOS 2023-12-06 (al gedekt door een baseline-positie via de
±180s-tolerantie op de gesnapte tick) ten onrechte als gratis vangst werd geteld. Gefixt; echte winst = **5**.
Twee adversariële agents én de code-review convergeerden onafhankelijk op exact deze bug.

**Grootste beperking — geen out-of-sample validatie (de eerlijke kanttekening bij de +7/+3):** de
completeness-critic flagde dit als de zwakste schakel. `recall_loop.loosen_of` zet elke band op exact de
doelgroep-waarde ±EPS (1e-6) en meet de +3 slecht-collateral op **dezelfde volledige historie** waaruit de
banden zijn afgeleid — volledig **in-sample**, maximaal strak om de in-sample punten. De ware out-of-sample
slecht-telling is vrijwel zeker **>3**; **+7/+3 is een optimistische ondergrens, geen voorspelling.** Dit raakt
direct het 2-coin-universum-probleem (530 ok-momenten over 2 coins, geen validatieset). De pooled ratio stijgt
in-sample (1,438→1,450) en er gaat **0 goede verloren** (onafhankelijk bevestigd: `lost_good=0`), dus er is geen
verborgen precisie-verlies *binnen* deze meting — maar de generalisatie is onbewezen. **Aanbevolen vóór
toepassing:** een walk-forward/holdout-split (bv. loosens fitten op data <2025-01-01, collateral meten op
≥2025-01-01) om te zien of de +3/ratio-stijging out-of-sample standhoudt.

**Bekende beperkingen (LOW, raken de cijfers niet):**
1. De `caught`-definitie verschilt subtiel tussen de worklist (anker op de groep-span) en de loop (anker op de
   gesnapte tick T, die tot 180s buiten de groep kan liggen). Dit kan een groep als `feature` labelen terwijl een
   baseline-positie de gesnapte T net dekt. Aanbeveling: lijn beide caught-definities uit op T + baseline-aftrek.
2. De worklist slaat de subrule-index `i` op; de loop (apart proces) vertrouwt die index. Veilig zolang
   `brain.rules` niet wijzigt tussen de twee runs (de read-only randvoorwaarde hier), maar robuuster is om in de
   loop `i` te her-deriveren via (indicator, subrulename, def1).
3. `inherit()` kan in een randgeval een dossier-rij dubbel overnemen (exact-lead match negeert de `used`-set) —
   raakt alleen de PROPOSAL-velden (status/tried/resolution_note), niet de feitelijke kolommen.

## Aanbevolen vervolgacties

1. **Maak `recall_worklist.py` een routine** (data-integriteit-set of een eigen `recall`-set): het dossier wordt
   dan bij elke relabel/refire bijgehouden. De meting-fixes zitten er nu in.
2. **De 5 geaccepteerde tweaks** passen binnen de bestaande `auto_loosen`-gate (0 goed verloren, ratio omhoog),
   maar pas ze **niet toe vóór een out-of-sample check**: draai eerst een walk-forward/holdout-split (loosens
   fitten op een vroege periode, slecht-collateral meten op een latere) — de +7/+3 is in-sample en optimistisch.
3. **NOS `volume_found`-detectie** als aparte epic onderzoeken: de recall-bovengrens voor NOS onder de huidige
   candidate-gating is structureel ~20%. Dít, niet rule-tuning, is de grootste recall-hefboom voor NOS.
4. **Rule 21** apart bekijken (outlier-split naar een nieuwe rule, roadmap-stap 2b): de hoog-upside r21-gemiste
   groepen zijn niet via loosenen te vangen.
