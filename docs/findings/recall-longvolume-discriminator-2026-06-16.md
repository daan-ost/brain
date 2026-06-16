# Lange-periode volume-trend als rule-specifieke discriminator voor de bijna-rakers

**Datum:** 2026-06-16
**Scope:** de twee brain-coins DOGEAI (2525) + NOS (244). De 61 FEATURE-gemiste bijna-rakers (fails 1-3)
uit `promising_recall_state`. READ-ONLY — er is **niets toegepast**; dit is een keeper-onderzoek.
**Artefacten:** `engine/src/recall_volagg.py` (de gated re-fire + aggregaat-precompute),
`engine/src/recall_volagg_analyze.py` (bad-edge + holdout), `engine/out/opt/recall_volagg_flood.json`.

---

## Vraag

Voor de promising-groepen die net 1-3 FEATURE-subrules missen: scheidt een **lange-periode
volume-aggregaat** (som-volume / aantal-negatieve-ticks / netto-helling over 30/60/120 ticks) — per rule
apart — de GOEDE bijna-rakers van de SLECHTE collateral die het loosenen van die rule binnenhaalt? Zo ja,
dan kun je de rule agressiever loosenen (de hoog-upside bijna-rakers vangen, vooral de r21-muur) en met
het volume-aggregaat als extra gate de binnengespoelde slechte trades weren.

## Antwoord (kort)

**Nee — zwak. Bewaren als keeper, niets toepassen.** Op de rules die de recall-prijs dragen (r21 = de
muur met de 31,6%/16,1%-upside groepen, en r20 = het gros) weert het beste lange-periode volume-aggregaat
— als je 100% van de goede bijna-rakers wilt behouden — slechts **3-16%** van de binnengespoelde slechte
trades. De goede bijna-rakers en de slechte collateral **overlappen vrijwel volledig** in
lange-periode-volume-ruimte. De ratio-na blijft ver onder het succescriterium (#goed ≥ 2×slecht). En de
enige ogenschijnlijk-sterke gevallen (r22/r23) zijn piepkleine, enkel-coin floods die **out-of-sample
instorten**. Dit bevestigt het vooraf-vermoeden: de bijna-rakers worden door feature-subrules geblokkeerd,
niet door volume, en een lange-periode volume-trend onderscheidt de goede niet van de slechte.

---

## Methode (de echte re-fire, niet alleen in-sample separatie)

1. **Aggregaten (nieuw t.o.v. de 31 én t.o.v. new_feat_lib's lookbacks 3..20):** over LANGE vensters
   N∈{30,60,120} ticks, leak-vrij as-of op de RAW volumeud-reeks (`eng._vals("volumeud", N, T)`):
   `sum_raw`, `cnt_neg` (aantal ticks value<0), `cnt_neg_frac`, `slope_raw` (OLS-helling), `slope_z`
   (helling van de z-gescoorde reeks), `net_pos_frac` (= Σvalue/Σ|value|), `relvol` (value[0]/mediaan|value|).
   Raw-varianten per coin (DOGEAI-volume ≈ 30× NOS, schaalt niet); scale-free varianten ook gepoold.
   volumeud is **signed** (~44% van de ticks negatief), dus "aantal negatieve ticks" meet echt netto
   verkoopdruk over een lang venster.
2. **Flood per target:** loosen de falende feature-subrule(s) van de home-rule net genoeg om de groep te
   vangen (zoals `recall_loop.loosen_of`), draai de **echte full re-fire** (`recall_volagg.evaluate_gated`,
   reproduceert `coin_fires` exact: DOGEAI 177/120/475, NOS 122/88/384), en lees welke NIEUWE executed
   trades verschijnen — goed (`best_upside≥3`) vs slecht (`<0,5`). Dat is de collateral die de gate moet weren.
3. **De beslissende test (projectprincipe 2, bad-edge):** zet de gate-drempel net voorbij de meest-extreme
   GOEDE flood-tick, zodat **100% van de goede bijna-rakers behouden** blijft, en meet hoeveel van de
   slechte collateral dan nog geweerd wordt. Een bruikbare discriminator weert de meeste slechte. Plus het
   succescriterium kept_good ≥ 2× rest_slecht, en een **temporele holdout** (drempel fitten op de vroege
   helft, toepassen op de late helft).

---

## Resultaat per rule (bad-edge: houd 100% goed, hoeveel slecht geweerd?)

| rule | flood goed / slecht | base-rate (slecht) | beste aggregaat | slecht geweerd | ratio-na | 2× gehaald? |
|---|---|---|---|---|---|---|
| **20** (gros) | 80 / 78 | 0,49 | cnt_neg N=120 | **4/78 (5%)** | 1,08 | nee |
| **21** (de muur) | 208 / 333 | 0,62 | slope_raw N=30 | **21/333 (6%)** | 0,67 | nee |
| **22** | 16 / 19 | 0,54 | slope_z N=30 | 10/19 (53%) | 1,78 | nee (en zie holdout) |
| **23** | 6 / 18 | 0,75 | relvol N=120 | 15/18 (83%) | 2,00 | schijn — zie holdout |

- **Rule 21 (de prijs):** de hoog-upside gemiste groepen homen hier (DOGEAI 2025-02-14 up31,6% → +28g/+50b;
  2025-02-20 up16,1% → +36g/+66b; 2025-03-09 up4,3% → +38g/+67b). Om álle goede te behouden weert het beste
  aggregaat maar **6%** van de 333 slechte → ratio zakt naar 0,67. Per coin identiek zwak (DOGEAI 8%, NOS 7%).
  **De goede en slechte flood liggen op elkaar in lange-volume-ruimte.**
- **Rule 20:** idem, 5-16% geweerd, ratio 1,0-1,35. Onder de 2× bar.
- **Rule 22/23:** lijken sterk (53% / 83% geweerd), MAAR het zijn piepkleine floods (35 resp. 24 ticks) en
  **enkel-coin** (r22-flood is ~volledig NOS, r23 ~volledig DOGEAI) — niet cross-coin te valideren, en de
  ratio haalt de 2× nét niet (r22) of berust op 6 goede ticks (r23).

## Holdout (temporeel, per coin) — de doodsteek

| geval | train-drempel | test goed behouden | test slecht doorgelaten | test-ratio |
|---|---|---|---|---|
| r21 slope_z N=60 DOGEAI | ≤0,0254 | 50/50 | **162/163** | 0,31 |
| r21 cnt_neg N=60 DOGEAI | ≤35 | 42/50 | 149/163 | 0,28 |
| r21 slope_z N=60 NOS | ≥−0,016 | 24/24 | **34/34** | 0,71 |
| r22 slope_z N=30 NOS | ≤0,047 | 4/5 | 5/12 | 0,80 |
| r23 relvol N=120 DOGEAI | ≥6,104 | 0/0 | 1/11 | 0,00 (geen test-goed) |

Op rule 21 laat een op de vroege helft gefitte drempel out-of-sample **vrijwel alle** slechte trades door
(test-ratio 0,28-0,71 — ver onder de staande ~1,1 baseline, laat staan 2,0). De schijnbaar-sterke
r22/r23-separatie (53%/83% in-sample) **overleeft de holdout niet** (test-ratio 0,80 resp. geen test-goed):
het was overfit op een handvol enkel-coin punten.

---

## Conclusie & afweging (eerlijk)

- **Recall-vs-precisie:** er is **geen** lange-periode volume-aggregaat dat de bijna-rakers veilig ontsluit.
  Op de rules met recall-potentieel (r20, r21) is de in-sample bad-edge-winst al marginaal (3-16% slecht
  geweerd terwijl alle goed behouden blijft), en de holdout bevestigt dat zelfs die marge niet generaliseert.
  De enige in-sample "winst" (r22/r23) is enkel-coin, te klein om te vertrouwen, en valt om in de holdout.
- **Waarom (consistent met eerder onderzoek):** de bijna-rakers worden door **feature-subrules**
  geblokkeerd, niet door volume — een volume-kenmerk kan alleen helpen als het een blokkerende
  feature-subrule kan vervangen/verzachten, en in de praktijk overlapt de volume-signatuur van de goede
  bijna-rakers volledig met die van de slechte collateral. Dit sluit aan op (a) de bekende bevinding dat
  van 50 "volume aanwezig"-groepen er maar 7 vuurden, (b) de eerdere zwakke volume-features op 2 coins
  (keepers), en (c) de r21-muur: de hoog-upside r21-groepen vragen een aparte, nauwere rule die díe
  stijging isoleert (roadmap-stap 2b), niet een band-loosen + volume-gate.
- **Niets toepassen.** Bewaard als keeper. De volume-aggregaat-harness (`recall_volagg.py`) blijft staan
  voor toekomstig gebruik (bv. als er ooit meer coins/data zijn die de 2-coin-beperking opheffen).

## Bekende beperkingen

- **In-sample maximaal strak, holdout klein:** de floods zijn klein (vooral r22/r23) en de twee coins
  overlappen niet in tijd, dus een echte out-of-sample set ontbreekt. De holdout is een within-coin
  temporele split — voldoende om de instorting te tonen, niet om een (afwezige) winst te bewijzen.
- **De gate filtert ALLE fires van de rule, niet alleen de flood.** In dit onderzoek is de bad-edge gemeten
  op de flood-ticks; een echte coin-brede gate zou ook bestaande goede fires kunnen raken (extra
  recall-verlies). Dat maakt de zaak alleen maar slechter, niet beter — de conclusie (niet toepassen) staat.
- Verbruikt dezelfde 2-coin-/r21-wall-constraint die in de eerdere recall-docs is benoemd
  (`recall-worklist-2026-06-16.md`, `recall-nocandidate-altvolume-2026-06-16.md`).
