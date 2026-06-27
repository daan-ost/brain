---
name: brain-regime-gate
description: De regime-gate van nobrainersbot — bepaalt automatisch wanneer een munt actief (traden) of inactief (pauze) is, op basis van het rollende trade-resultaat. Wanneer je iets verandert aan het bepalen/opslaan van actieve perioden, de aan/uit-streep in /coins/weekly, het scoren tegen de benchmark, of het toepassen van de actieve-periode-filter in de optimalisatie of de schermen.
---

De regime-gate beslist **wanneer we een munt wel/niet handelen** — los van wélke trades we maken als
we handelen (dat is de buy/sell-engine). Het idee: een munt heeft een leven (opkomst → afkoeling); op
tijd stoppen met een aflopende munt is een van de successen van de bot. Deze skill is de kaart.
Volledige uitleg: [[docs/regime-gate.md]]. Plan: [[docs/epics/epic-G-coin-regime-gate.md]] (de gate) +
[[docs/epics/epic-H-regime-apply.md]] (overal toepassen). Memory: [[coin-regime-gate-plan]].

## Eén alinea

Signaal = het **rollende gerealiseerde trade-resultaat** over de laatste 4 weken (≈1 maand). Een munt
gaat **UIT** na 2 weken aaneen met rollend resultaat < 20% (Daans "onder ~20%/maand is na slippage geen
echte winst"), en pas weer **AAN** na 3 weken aaneen ≥ 30% (hogere herstart-lat = demping → niet
herstarten op een losse goede maand). De gate start pas bij de eerste week mét trades. Kansrijk +
beweeglijkheid (`coin_daily_metrics`) zijn alleen context, sturen de gate **niet** — het echte
trade-resultaat is het signaal. Wekelijkse cadans is getest als beste (beter dan dagelijks/3-daags).

## Terminologie (gewone taal — geen Engels jargon, zie [[CLAUDE.md]])

- **actief / inactief** (niet "regime on/off" tegen Daan). Een munt staat actief = we traden; inactief = pauze.
- **rollend resultaat** = som van de gerealiseerde `profit_loss` over de laatste 4 weken.
- **demping** = de hogere herstart-lat + de "X weken aaneen"-bevestiging, tegen geflikker.
- **benchmark** = Daans handmatige ideale aan/uit per munt; het **ijkpunt**, niet de motor.

## Waar het zit

| Onderdeel | Bestand |
|---|---|
| Gate-logica + streep (UI, backtest) | `www/app/Livewire/Coins/Weekly.php` (`applyGate`, de `GATE_*`-constants) + `resources/views/livewire/coins/weekly.blade.php` |
| Cadans-test (dag vs 3-daags vs week) | `engine/src/regime_backtest.py` |
| Statistische validatie (4 toetsen) | `engine/src/regime_validate.py` |
| Niet-circulaire bevestiging: economisch mét slippage (P1) | `engine/src/regime_economics.py` |
| Niet-circulaire bevestiging: vooruit-voorspellend (P2) | `engine/src/regime_forward.py` |
| Benchmark (ground truth) | `engine/data/regime_benchmark.json` |
| Opslag actieve perioden (epic-H) | tabel `coin_regime` + `engine/data/coin_regime.json` |
| Berekenen + routine (epic-H) | `engine/src/coin_regime.py` + set `coin-regime` in `routines.py` |
| "Is actief?"-helper (epic-H) | `engine/src/regime.py` (Python) + `App\Services\CoinRegime` (PHP) |

## De gate-knoppen (gevalideerd, niet zomaar wijzigen)

```
GATE_ROLL_WEEKS     = 4    # rollend venster (~1 maand)
GATE_STOP_FLOOR     = 20   # onder deze rollende % → kandidaat-uit
GATE_STOP_CONFIRM   = 2    # zwakke weken aaneen vóór stop
GATE_RESTART_FLOOR  = 30   # boven deze rollende % → kandidaat-aan (hoger = demping)
GATE_RESTART_CONFIRM= 3    # sterke weken aaneen vóór herstart
```

Asymmetrisch: **snel uit, traag aan**. De band 20–30 is "plakkerig" (status blijft staan). Dit pinde
o.a. MUMU's foute mei-'25-herstart eruit (+21% < 30%) en hield NOS' augustus-spike (+50%) wél.

## Validatie-status (2026-06-25) — doorstaan

Tegen de benchmark, via `regime_validate.py`: **nullijn** (gate 94,3% vs altijd-aan 45% → +39 punten),
**toeval-toets** (3000× geschud, p ≤ 0,009 alle munten), **apart-gehouden testperiode** (vroeg ≈ laat),
**munt-eruit-laten** (90–98% op de ongeziene munt). Kanttekening: 4 munten + deels-soft benchmark =
sterk, niet absoluut → herbevestig met meer munten. Draai bij twijfel `regime_validate.py` opnieuw.

## Backtest → live: het herstart-signaal (cruciaal)

In de backtest bestaan trades over de hele periode, dus herstart kan op echt resultaat. **Live niet:**
zodra een munt inactief staat, handel je niet → geen nieuwe trade-resultaten → het echte resultaat kan
niet herstellen. Oplossing: de indicatoren blijven binnenkomen voor élke munt; op een inactieve munt
**simuleert de engine een schaduw-trade** (`coin_moment_sells`, sell-engine zonder geld), en dát voedt
het herstart-oordeel. Dus: **stoppen op echt resultaat, herstarten op het schaduw-/indicatorsignaal.**

## De actieve-periode-filter (epic-H) — de #1 regel bij optimalisatie & schermen

Trades uit een **inactieve** periode tellen **standaard niet mee** — niet in de cijfers, niet in de
buy/sell-rule-optimalisatie. Reden: die periodes hadden we niet gehandeld; ze meetellen vertekent de
prestatie én laat de rule-tuner leren van trades die nooit zouden hebben plaatsgevonden.

- **Engine:** het filter zit in `opt_lib.load_trades()` / `load_all_fires()` (+ de eigen loaders in
  `sell_tuning.py`, `subrule_power.py`, `gate_window.py`). Default uit-filtert; `include_inactive=True`
  voor analyse. **Cache-laag (sinds de snelheidswijziging):** de regime-versie MOET in `_long_fingerprint`
  (per-munt long-cache, `opt_lib.py`) én `input_fingerprint` (`routines.py`, de data-veranderd-gate), anders
  maskeert een oude cache de filter. `fires_cache.py` (re-fire) blijft alle trades berekenen — daar NIET ingrijpen.
- **Schermen:** `Trades/Index.php` + `CoinExplorer.php` tonen default alleen actieve trades (toggle voor
  de rest). `/coins/weekly` toont juist de héle historie (kalibratie-bril) met de aan/uit-streep.
- **Volgorde-discipline (feedback-lus):** trades → regime berekenen → rules tunen op actieve trades →
  re-fire → regime herberekenen. Zie [[brain-routines]].

## Veelgemaakte valkuilen

- **Niet vooruitkijken.** Elke beslissing gebruikt alleen verleden-data t/m dat moment.
- **GROUP BY-alias-botsing:** bij wekelijkse trade-sommen NOOIT aliassen naar `id` — dat botst met
  `coin_fires.id` → MySQL groepeert op de primaire sleutel → 1 trade/week i.p.v. de hele week.
- **Cache maskeert de filter:** filter je de actieve periode in `load_trades` maar laat je de regime-versie uit
  `_long_fingerprint` / `input_fingerprint`, dan blijft een oude cache de verouderde (volledige) trade-set serveren
  en draait de keten niet opnieuw bij een regime-wijziging. Regime-versie hoort in elke cache downstream van `load_trades`.
- **Kansrijk/beweeglijk sturen de gate niet** — alleen het trade-resultaat. (Wel als context getoond.)
- **Gate ≠ rule-kwaliteit.** De gate = wanneer in de markt; de rules = welke trades als je in de markt
  bent. Het filter koppelt ze: tune rules alleen op trades uit actieve perioden.
