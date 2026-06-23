# Sell-tuning vervolg-trajecten — read-only probes (2026-06-23)

De twee vervolg-trajecten uit de critical-eye review zijn read-only verkend (niets gemuteerd, DB schoon).
**Beide leveren een helder NEGATIEF resultaat**, elk om een concrete structurele reden. Dat is waardevol:
het bespaart bouwwerk en bevestigt tweemaal waar de echte hefboom zit (meer munten).

## Traject 1 — promising-trades als brede meetbron → NIET de hefboom
Probe: `engine/src/probe_promising_tuning.py` (genereert de promising-set in-memory, hergebruikt
`sell_tuning.split_per_rule`/`metrics`/`verdict`/`GRID`/`signflip_pvalue`).

| | executed | promising |
|---|---|---|
| beslisbaar (holdout) | 48 | 46 |
| SAFE | 3 | 2 |
| **gecertificeerd (toeval-toets)** | **1** | **1** |

Meer momenten → niet meer certificering. Drie redenen:
1. **98% DEFAULT_RULE=20-vervuiling**: ~18k (DOGEAI)/15k (NOS) promising-momenten, maar 98% heeft geen
   echte fire-rule → allemaal bucket 20. De regels die we tunen (21/22/23) krijgen op de promising-set
   *minder* trades dan executed (echte 21/22/23-fires vallen zelden samen met een promising-tick).
2. **De "kan-niet-certificeren"-floor kwam in geen van beide bronnen voor** (0 executed, 0 promising) —
   de `perm_n`'s waren al ~10-13. De aanleiding voor de hypothese (toeval-toets blokkeert door te weinig
   data) doet zich bij deze 2 munten niet voor. De bindende rem is `verdict()`, niet de floor.
3. **Bucket 20 is rijk (4386 geraakte momenten) maar structureel onbruikbaar**: elke winstgevende
   knop-richting wint Σprofit door winnaars naar verliezers te klappen (87 flips) → `verdict` keurt af
   (Daans "gebalanceerd"). De enige flip-vrije richting verliest Σprofit.

**Conclusie:** de bindende grens is het 2-munten-universe (meer munten → meer échte 21/22/23-fires →
dikkere holdouts), NIET de meetbron. Re-bevestigt [[new-feature-discovery-2coin-wall]] +
[[rule-improvement-roadmap]]. Advies: promising hooguit als read-only *diagnose*-modus toevoegen
(`load_promising()` + `source`-param), nooit als beslis-bron (promising ≠ productie: geen dedup); en als
je 'm ooit gebruikt, gooi de momenten zonder echte fire-rule weg i.p.v. ze op rule 20 te plakken.

## Traject 2 — amplitude-berekeningen als verkoop-signaal → GEEN signaal
Probe: `engine/src/probe_exit_signals.py` (alternatieve exit = verkoop op eerste hold-tick waar
`range_percentage`@PX / `gini`@VV / `iqr_normalized`@PX over de laatste N ticks de drempel kruist;
leak-vrij t/m tick i; 144 kandidaten/munt; holdout-split + `signflip_pvalue`+Šidák).

Harnas gevalideerd: baseline reproduceert live `profit_loss` exact (816/816); niet-vurende drempel → ΔΣ=0
(geen leak); altijd-vurende drempel → Σ stort in (richting klopt).

| munt | baseline Σ | beste Δtest (holdout) | beste Δtrain | positief op BEIDE splits |
|---|---|---|---|---|
| DOGEAI | +905% | −64,7% | −11,3% | **0 / 144** |
| NOS | +746% | −3,1% | −30,3% | **0 / 144** |

Elke kandidaat netto-negatief op de holdout; alle p_raw=1,0 (niets te certificeren). Richting "amplitude
zakt in → verkoop" is consequent het schadelijkst.

**Conclusie:** amplitude is geen verkoop-signaal. Structureel: zo'n signaal kan alleen *eerder* verkopen,
en de **winst-lock** laat winnaars al doorlopen → vroeg uitstappen kapt winnaars af terwijl verliezers via
de lock toch al goedkoop zijn. Bevestigt het kern-onderscheid: feature_quality meet KOOP-kwaliteit, niet
EXIT-timing — zie [[discovery-currentvalue-no-edge]], [[coin-volatiliteit-stoplicht]]. Niet de moeite om
er een rule-101 subrule-type van te maken.

## Netto
Beide "logische volgende stappen" zijn dood spoor → de echte hefboom blijft **meer munten gelijktijdig**
(de MEXC-scan epic, [[mexc-volatile-coins-discovery]]). Probe-scripts blijven staan als herbruikbare
diagnose (opnieuw draaien zodra er munten bij zijn — dan kan traject 1 alsnog kantelen). Niets toegepast.
