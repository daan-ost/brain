# Documentatie — nobrainersbot

Master-index van alle documentatie. Start hier als je niet weet waar je iets moet zoeken.

---

## Startpunt voor nieuwe lezers

| Document | Wat je er uithaalt |
|---|---|
| [functional-overview.md](functional-overview.md) | Wat doet het systeem — de trade-lifecycle in gewone taal |
| [technical-overview.md](technical-overview.md) | Architectuur, modules, verbindingen, hoe je iets draait |
| [roadmap.md](roadmap.md) | North star, product vision, fasen, epic-index |

---

## Engines — referentiedocumentatie

### Sell-engine
| Document | Inhoud |
|---|---|
| [sell-engine.md](sell-engine.md) | Functioneel + technisch: mechanismen, knobs, code-locaties, tabellen |
| [sell-engine-history.md](sell-engine-history.md) | Tijdlijn: wat we gedaan hebben, wanneer, met welk resultaat |
| [methodology/selling-process.md](methodology/selling-process.md) | Byte-voor-byte legacy-specificatie (de oracle-implementatie) |

Skills: `brain-sell-engine`

### Koop-engine & rule-discovery
| Document | Inhoud |
|---|---|
| [buy-engine-history.md](buy-engine-history.md) | Tijdlijn: rule-rebuild, discovery, futureprice-fix, alle bevindingen |
| [methodology/rule-discovery.md](methodology/rule-discovery.md) | Bottom-up discovery-methode + statistisch discipline-protocol |
| [methodology/rule-boundary-method.md](methodology/rule-boundary-method.md) | Hoe regels getoetst worden tegen de oracle (boundary-drift) |
| [methodology/feature-store.md](methodology/feature-store.md) | Feature-precompute specificatie (20 lookbacks × 29 berekeningen) |
| [analysis/legacy-analyse-en-rewrite-plan.md](analysis/legacy-analyse-en-rewrite-plan.md) | Oorspronkelijke analyse van de legacy-code voor de rebuild |
| [analysis/onderzoek-relatief-volume-rule30.md](analysis/onderzoek-relatief-volume-rule30.md) | Relatief-volume onderzoek voor rule 30 |

Skills: `brain-engine`, `brain-rule-tuning`, `brain-rule-discovery`, `brain-indicator-metrics`, `brain-routines`

---

## Bouwplannen (epics)

| Document | Inhoud |
|---|---|
| [epics/README.md](epics/README.md) | Overzicht actieve bouw-epics + grand plan (E01–E11) |
| [epics/epic-A-good-trade-periods.md](epics/epic-A-good-trade-periods.md) | Good trade-period discovery + explorer |
| [epics/epic-B-lookback-store.md](epics/epic-B-lookback-store.md) | Per-rule lookback feature store |
| [epics/epic-R-rule-tuning-discovery.md](epics/epic-R-rule-tuning-discovery.md) | Rule tuning, scoring & automatische discovery |
| [epics/epic-L-promising-labeler.md](epics/epic-L-promising-labeler.md) | Promising labeler — koop-kwaliteit per moment |
| [epics/epic-RD-rule-discovery-engine.md](epics/epic-RD-rule-discovery-engine.md) | Rule-discovery engine (bottom-up, Subgroup Discovery) |
| [epics/epic-RDA-rule-discovery-automation.md](epics/epic-RDA-rule-discovery-automation.md) | Discovery-automatisering (autonome loop) |
| [epics/epic-S-sell-precision.md](epics/epic-S-sell-precision.md) | Sell-engine precision (geparkeerd — kern nu gebouwd) |

Overige epics E01–E11: zie [epics/README.md](epics/README.md)

---

## Uitzoekwerk & bevindingen

Alles in `findings/` en `optimization/` is **gedateerd uitzoekwerk**: wat we onderzochten, de methode, en de conclusie. Nooit weggooien — ook negatieve resultaten (zoals amplitude-exit GEEN signaal) zijn waard om te bewaren zodat we het niet opnieuw doen.

### findings/ — 18 bestanden

| Bestand | Onderwerp | Conclusie |
|---|---|---|
| [step1-engine-validation.md](findings/step1-engine-validation.md) | Eerste engine-validatie (rule-21, 99,96%) | Rebuil slaagt |
| [milestone-1-entry-filter.md](findings/milestone-1-entry-filter.md) | Entry-filter PoC resultaat | — |
| [feature-store-v1.md](findings/feature-store-v1.md) | Feature store v1 opzet | — |
| [good-moment-defaults.md](findings/good-moment-defaults.md) | Standaard-parameters voor "goed moment" definitie | — |
| [rules-vs-promising.md](findings/rules-vs-promising.md) | Rules vs promising trades vergelijking | — |
| [promising-port-validation.md](findings/promising-port-validation.md) | Promising-port validatie | — |
| [precision-overfitting.md](findings/precision-overfitting.md) | Precision sweep overfit (greedy stacker) | Stacking overfit, niet bouwen |
| [trade-horizon-1hour.md](findings/trade-horizon-1hour.md) | Trade-horizon 1 uur gekozen | 1 uur is de bindende horizon |
| [recall-worklist-2026-06-16.md](findings/recall-worklist-2026-06-16.md) | NOS recall plafond ~20% (no-candidate probleem) | Structureel ~80% NOS-misses door afwezigheid vf=1 kandidaten |
| [recall-nocandidate-altvolume-2026-06-16.md](findings/recall-nocandidate-altvolume-2026-06-16.md) | Volume-gate als no-candidate oplossing | Werkt NIET (25/143 gevangen, flood+dilutie) |
| [recall-longvolume-discriminator-2026-06-16.md](findings/recall-longvolume-discriminator-2026-06-16.md) | Lange-termijn volume als scheidingsteken | ZWAK — in-sample winst klapt in holdout |
| [recall-seed-tighten-nos-2026-06-16.md](findings/recall-seed-tighten-nos-2026-06-16.md) | (min,max)-hull op NOS seed-ticks | Overfit — 0 van 2429 boxes overleeft tijds-holdout |
| [coin-volatiliteit-stoplicht-2026-06-17.md](findings/coin-volatiliteit-stoplicht-2026-06-17.md) | Coin-volatiliteit als aan/uit-stoplicht | Flikkert + winst-lock maakt verliezers goedkoop → niet zinvol per minuut |
| [mexc-volatiele-coins-2026-06-19.md](findings/mexc-volatiele-coins-2026-06-19.md) | MEXC-marktscan voor nieuwe munten | Epic M klaar; wacht op groen licht |
| [subregel-kracht-rule30-31-2026-06-23.md](findings/subregel-kracht-rule30-31-2026-06-23.md) | Subregel-kracht meting r30/31 | Winnaar-schaarste is de rem (niet slechte-abundantie) |
| [feature-berekeningen-research-2026-06-23.md](findings/feature-berekeningen-research-2026-06-23.md) | 13 nieuwe berekeningen gemeten op koop-kwaliteit | Gini/IQR beste nieuwe; prijs-amplitude scheidt (niet richting) |
| [sell-tuning-critical-eye-fixes-2026-06-23.md](findings/sell-tuning-critical-eye-fixes-2026-06-23.md) | 4 critical-eye fixes sell-tuning routine | Per-regel split bracht 3-4 SAFE zichtbaar (was 0) |
| [sell-tuning-vervolg-probes-2026-06-23.md](findings/sell-tuning-vervolg-probes-2026-06-23.md) | 2 probes: promising-bron + amplitude-exit | Beide verworpen — hefboom = meer munten |

### optimization/ — 5 bestanden + daily/

| Bestand | Onderwerp |
|---|---|
| [2026-06-14-rule-set-optimization.md](optimization/2026-06-14-rule-set-optimization.md) | Eerste ronde rule-optimalisatie |
| [2026-06-15-new-feature-discovery.md](optimization/2026-06-15-new-feature-discovery.md) | Nieuwe features op 2 munten (geen doorbraak) |
| [2026-06-15-rule-2b-outlier-split-analysis.md](optimization/2026-06-15-rule-2b-outlier-split-analysis.md) | Outlier-split analyse rule 2b |
| [2026-06-15-success-bar-gap-analysis.md](optimization/2026-06-15-success-bar-gap-analysis.md) | Gap-analyse voor succes-lat |
| [2026-06-15-volume-param-sweep.md](optimization/2026-06-15-volume-param-sweep.md) | Volume-param sweep (kleine hefboom) |
| [daily/](optimization/daily/) | Dagelijkse optimalisatie-rapporten |

---

## Overige docs

| Document | Inhoud |
|---|---|
| [strategy.md](strategy.md) | Strategische keuzes en uitgangspunten |
| [build-orchestrator-plan.md](build-orchestrator-plan.md) | Build-orkestratie plan |
| [assessment-uniqueness-and-prior-art.md](assessment-uniqueness-and-prior-art.md) | Prior art en uniciteits-beoordeling |

---

*Skills staan in `.claude/skills/` — lees die als eerste bij nieuw uitzoekwerk.*
