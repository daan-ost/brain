---
name: brain-rule-discovery
description: Hoe je bottom-up NIEUWE koop-rules (rule 30, 31, …) ontdekt uit de handmatige yes-marks — de gemene-deler-methode, regime-clustering (meerdere rules), de parent-cascade variant (t0·t1·t2), de overfit-remmen (cross-groep/holdout/zelfde-dag/sell-engine), en de verplichte rapportagevorm. Gebruik wanneer je nieuwe rules zoekt, de parent_*.py harness draait, of resultaten van een kandidaat-rule rapporteert.
---

Bottom-up nieuwe trading-rules afleiden uit de momenten die Daan zelf als goede instap markeerde, in
plaats van regels top-down verzinnen. Volledige uitleg: [[docs/methodology/rule-discovery.md]]. Deze
skill is de snelle kaart. Zie ook [[brain-engine]], [[brain-promising-labeler]], [[brain-sell-engine]],
[[brain-rule-tuning]].

## Kernregels (niet schenden)

- **Alleen `decision='yes'`/"ok"-marks zijn grondwaarheid.** Ongelabeld is NIET slecht — nooit zo
  rapporteren. "Slechte trade" = gerealiseerde verliezer (sell `profit_loss<0`), niet "gelabeld slecht".
- **READ-ONLY**: niets in brain muteren tijdens ontdekking. De `parent_*.py`-scripts SELECT-en alleen.
- **Per groepje, niet gepoold.** Eén rise (yes-marks, gap>5min = nieuwe rise) = één groepje; triple =
  eerste 3 opvolgende ticks (schuifbaar 1-3/2-4/3-5).

## De methode in 5 stappen

1. **Groepjes** verzamelen (rises uit yes-marks), eerste-3-triple.
2. **Gemene deler per groepje**: ~30 `window_metrics` × lookbacks × 5 indicatoren (obv-x-value, vzo,
   mfi, phobos, volumeud + prijs).
3. **Clusteren in regimes = MEERDERE rules.** Groepjes delen geen één band (centers beslaan het hele
   bereik) → splits in 2-3 regimes (zoals 20-23 op lage/hoge phobos). Elk regime → eigen rule. Regimes
   kunnen per munt tegengesteld zijn (DOGEAI oversold, NOS momentum).
4. **Projecteren** met de **parent-cascade** (k=3 opvolgende ticks in de band) over de hele periode →
   survivors tellen.
5. **Verfijnen** met vorm-subregels (skewness/range/volatility + lookback). **Vorm/relatief boven
   absoluut niveau** — absolute current_value driften per dag en overfitten; vorm-metrics generaliseren.

## Overfit-remmen (een bevinding telt pas als hij ál deze haalt)

1. **Cross-groep-herhaling** (≥2-3 groepjes, liefst beide munten — onafhankelijk winnen = sterkste bewijs).
2. **Tijd-holdout** (band op vroege rises, bevestigen op late).
3. **Zelfde-dag baseline** (survivors vs random ticks op dezelfde dagen — anders meet je "stijgende dag").
4. **Gerealiseerde winst via sell-engine** (de eindtoets; buy-kwaliteit verdampt vaak na de meelopende
   stop). Baseline = **schone random sell-run**, NIET `AVG(coin_moment_sells.profit_loss)` (outlier-rijen
   → gemiddelde +80701%/trade; onbruikbaar).

## Rapportagevorm (VERPLICHT in de chat)

> **rule X** (regime + verfijning): raakt **N/M** promising groepjes | **Y** trades
> (**%** van de ticks) | Σprofijt **+Z%** | gem **±p%/trade** | verdeling **goed g% / middel m% /
> slecht s%** — naast de 20-23-richtlijn (en als sanity-vloer vs random-baseline).

Trade- en winst-centrisch, geen abstracte "edge"-getallen. Daan wil zien: hoeveel goede groepjes,
hoeveel trades (en hoe selectief), totale + gemiddelde winst, en de **goed/middel/slecht-verdeling**
(gerealiseerd op `profit_loss`: goed ≥3% / middel 0–3% / slecht <0%) — afgezet tegen de 20-23-lat.

## Succescriterium — de lat is 20-23, NIET random

Een random sell-baseline is een te lage ondergrens (sanity only). De **bestaande rules 20-23 zijn de
richtlijn** — ze zijn live en vertrouwd. Gemeten (gerealiseerd, executed trades uit `coin_fires`):

| | selectiviteit (%ticks) | gem/trade | slecht% | goed% | Σprofit |
|---|---|---|---|---|---|
| DOGEAI 20-23 per regel | 0,01–0,08% | +0,68 … +12,5% | 24–61% | 12–37% | +80 … +187% |
| NOS 20-23 per regel | 0,02–0,06% | +1,48 … +2,22% | 9–40% | 21–26% | +66 … +177% |
| **typisch (alle 4 samen)** | **~0,15%** | **~+1,9 à +2,3%** | **~28–45%** | **~19–23%** | +404 … +562% |

Een nieuwe regel is **succesvol** als hij per munt de richtlijn haalt:
1. **Selectiviteit** ≤ ~0,1% van de ticks (sluipschutter, zoals 20-23: 0,01–0,08% per regel).
2. **Gem winst/trade** ≥ +0,7% (de zwakste bestaande regel), streefwaarde ~+2%.
3. **Verdeling**: slecht ≤ ~45% én goed ≥ ~19% (de beste rules halen 9–24% slecht).
4. **Σprofijt** positief en betekenisvol (elke bestaande regel draagt +65 … +187%).
5. **Poorten**: standhouden op tijd-holdout én positief op een 2e munt (of expliciet als
   *coin-specifiek* gemerkt — 20-23 hebben ook per-munt-instellingen via `coin_rule_settings`).

Worked example (waarom random misleidt): rule 31 (NOS, regime+phobos-skew) leek met +240% Σ /
+0,48%/trade een edge **vs random**, maar **tegen 20-23**: 0,32% van de ticks (4× te los), +0,48%/trade
(ónder de zwakste rule 21), 52% slecht / 8% goed, en faalt cross-coin op DOGEAI (+0,04%/trade ≈ ruis).
→ GEEN keeper als universele regel.

## Engine (`engine/src/discovery/`, READ-ONLY) — Epic RD, GEBOUWD juni 2026

De methodiek is geconsolideerd tot één engine. **Draaien vanuit `engine/src`:**
`../.venv/bin/python -u -m discovery.run --coin both` (achtergrond; eerste run bouwt de `.cache/`).

| Module | Doet |
|---|---|
| `data.py` | feature-tabel per munt (lean INDS1×LB1(5,10,20)×METRICS1 + prijs) + doel=`profit_loss` + CPCV-blokken; dev-cache in `.cache/` |
| `segment.py` | **pysubgroup** Subgroup Discovery (doel=`is_promising`) → catalogus promising-dichte segmenten |
| `funnel.py` | subregels stapelen, **CPCV-gestuurd** (drempel uit train-blokken, trefkans op apart-gehouden blok); stopt bij OOS-instorting |
| `validate.py` | CPCV+herfit+embargo, toeval-toets+Šidák, schone nullijn, incrementele bijdrage op 20-23 (uit `coin_fires`) |
| `report.py` | compacte rapportage + oordeel tegen de 20-23-lat | 
| `run.py` | CLI-orkestratie (per munt + cross-coin), één munt tegelijk in geheugen |

LET OP: `os.environ["NUMBA_DISABLE_JIT"]="1"` vóór `import pysubgroup` (numba SIGBUST op Apple Silicon).

De oude losse `parent_*.py` blijven als read-only proef (`parent_eval.py` = sell-engine→rapportagevorm,
`parent_fullperiod.rises()`, `parent_crossgroup.AsOf`, `parent_spoor1.lean_metrics/arrays1` worden door de
engine hergebruikt). Verbinding: brain op MAMP poort 8889 (zie [[brain-engine]]).

## Status (juni 2026 — engine gebouwd, Epic RD)

De engine (pysubgroup + CPCV) gaf de methodiek zijn **eerlijke beste kans op 2 munten**. Resultaat
DOGEAI/NOS: de funnel dikt door tot bijna 20-23-selectiviteit (0,2-0,4%), de **OOS-trefkans stort niet
meer in** (vastpinnen op toeval opgelost), beide rules zijn **out-of-sample winstgevend** (CPCV +1,2-1,6%/
trade, élk tijdblok positief) en **significant ná Šidák-correctie** (p≤0,037). Eén coin-agnostische feature:
`price|L5|mindip` (een kleine dip kopen). **Maar GEEN KEEPER:** 56-63% slecht / 7-9% goed → de strikte
20-23-kwaliteitslat wordt net niet gehaald (netto-winst wél positief; winst-lock maakt verliezers goedkoop).
De bindende beperking is nu de **trade-kwaliteitsmix**, niet overfit/generalisatie. 2-munten-plafond
verdiend met het juiste gereedschap → hefboom = **meer munten** (Epic 07).

**COIN-AGNOSTISCH + VASTGELEGD (juni 2026).** `pooled.py` draait nu coin-agnostisch: één rule met
**gedeelde banden** voor alle munten (schaal-invariante features + gepoolde drempels — `scale_invariant_cols`),
NIET per-munt. `apply.py` legt 'm vast als **rule 30** (55 gedeelde subregels, `active=0`, source
`discovery-RD-pooled`, `rules_history` v16, getrouwheid lean-vs-live 100%). Cijfers: NOS bijna KEEPER
(0,13% ticks, +1,14%/trade, 47% slecht, CPCV +2,9%), DOGEAI zwakker (0,24%, +0,64%, 65% slecht); netto
winstgevend + p=0,000 op beide, geen strikte KEEPER. **INACTIEF** — vuurt niets tot activatie: RuleEngine
moet rule 30 laden (nu hardcoded 20-23) + **ongepoort** laten vuren (buiten `brain_volume_found`) +
`coin_strategies` (sell) per munt + `active=1`. Daarna tunen de routines de slecht% omlaag ([[brain-rule-tuning]]).
Cijfers/details: [[docs/methodology/rule-discovery.md]] §12, memory [[parent-gate-gemene-deler]].
