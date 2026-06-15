# Success-lat gap-analyse — wat kunnen we nog binnen bestaande hefbomen? (15 jun 2026)

> Read-only analyse (workflow: 4 rule-agents + synthese + adversariële verify). Niets toegepast.
> Scope: **alleen tighten (rq1) / loosen (rq2) / volume / combo — géén nieuwe berekeningen of rules.**

## Het succescriterium en waar we staan

Een rule is succesvol iff **(1) #goed ≥ 2×#slecht** én **(2) Σresult ≥ 3×Σslecht**.
Criterium **2 is overal al gehaald** (Σbest_upside goed/slecht = 139/47/76/341×; ook in onze-sell-termen
winnen de goede trades >3× van de slechte verliezen). **De enige bindende beperking is criterium 1 (count).**

| Rule | goed | slecht | ratio | need* | oordeel |
|------|------|--------|-------|-------|---------|
| 20 | 76 | 41 | **1,85** | 6 | **Dichtbij** — routine haalt het over enkele runs |
| 23 | 31 | 21 | **1,48** | 11 | **Haalbaar, gestapeld** — geen single sluit het, 2-3 runs |
| 22 | 113 | 83 | **1,36** | 53 | **Haalbaar, traag** — tighten draagt, veel runs |
| 21 | 79 | 68 | **1,16** | 57 | **Tegen het plafond — niet haalbaar met bestaande hefbomen** |

*need = 2×slecht − goed = verbeterpunten nodig (1 slecht weg = 2 pt, 1 goed erbij = 1 pt).

## Belangrijkste bevinding (en waarom je de getallen voorzichtig moet lezen)

De cache-gebaseerde "SAFE kandidaten" **overschatten de echte hefboom enorm**: ze overlappen op dezelfde
slecht-pool (dubbeltelling) en de meeste zijn out-of-sample **no-ops**. Concreet uit de verify:
- rule 20: **91%** van de SAFE-singles is OOS een no-op;
- rule 21: mediane OOS bad_drop = **0** (de meeste tightens doen op ongeziene data niets);
- rule 22: slechts **26%** van de SAFE-singles heeft nonzero OOS-effect, en 74/535 verliezen wél goed.

De routine past **1 lever per rule per run** toe achter een engine-gate die per run veel minder laat
vallen dan de cache voorspelt. Lees de headroom dus als "is er überhaupt ruimte", niet als "zoveel halen we".

## Per rule

- **Rule 20 (need 6) — dichtbij.** Ruime tighten-headroom; de gap is klein genoeg dat de routine 'm over
  enkele runs dicht. Vertrouw géén enkele kandidaat als "gegarandeerde sluiter" (de meeste zijn OOS no-op) —
  laat convergeren. Geen actie nodig.
- **Rule 23 (need 11) — haalbaar, gestapeld.** Geen enkele single sluit de gap (beste dropt 4). Combineer
  tighten (`phobos/skewness/lb3`, `obv-x-value`) **plus** de ene schone loosen (`phobos/volatility/lb5`,
  NOS). De routine doet dit vanzelf over 2-3 runs. Klein sample → blijf voorzichtig.
- **Rule 22 (need 53) — haalbaar maar traag.** Tighten-lever is overvloedig (echte cap = 83 slecht ≫ need),
  maar zware overlap → diminishing returns, dus **veel** runs. Loosen verwaarloosbaar (1 entry, +3). Geduld.
- **Rule 21 (need 57) — tegen het plafond.** `loosen = 0` (rq2 vindt geen schone versoepeling), de
  tighten-pool overlapt op slechts 68 slecht, beste single dropt er **4**, en de OOS-drops zijn grotendeels 0.
  **Met tighten/loosen/volume/combo alleen is ≥2× hier realistisch niet te halen.** Combo-pairs is géén
  reële extra hefboom (de pair-bestanden zijn volume-param-sweeps op een andere populatie). Volume is al benut.

## De eerlijke conclusie

- **Rules 20, 22, 23: laat de routine convergeren** — de bestaande hefbomen zijn voldoende (20/23 dichtbij,
  22 over meer runs). Geen nieuw werk nodig; geduld over runs.
- **Rule 21 is de muur.** Die rule is zo goed als de bestaande levers toelaten. ≥2× vergt daar **fundamenteel
  een nieuwe discriminerende feature** (nieuwe berekening of nieuwe rule die de 68 slecht scheidt zonder
  goeds te raken) — buiten de nu gestelde scope. Niet als "bijna goed" verkopen: het is een plafond.

*Reproduceerbaar: `rq1_tighten.py <rule> 1` + `rq2_earlier.py <rule> both` → `engine/out/opt/`. Dit rapport wijzigt niets.*
