# Sell-engine — tijdlijn & uitkomsten

Chronologisch overzicht van alles wat we met de sell-engine hebben gedaan, wanneer, en wat het opleverde.
Voor de huidige technische stand: zie [sell-engine.md](sell-engine.md).

---

## 2026-06-13 — Specificatie + eerste PoC

**Wat:** Legacy selling-process byte-voor-byte gedocumenteerd. Eerste engine gebouwd met stop-loss + rule-101 + validatie.

**Uitkomst:** 87% total P&L vs legacy (winst-lock nog UIT). Verkoop-mechanisme bewezen herbouwbaar.

**Documenten:**
- `docs/methodology/selling-process.md` — autoritative legacy-spec vastgelegd
- `docs/roadmap.md` — sell in roadmap als "E09 Exit policy (later)"

---

## 2026-06-17 — Winst-lock aan + live doorgevoerd

Dit was de grote dag: winst-lock aangezet, engine doorgevoerd op alle live trades, UI gebouwd.

### Stap 1: Winst-lock aanzetten (ochtend)

**Wat:** `lock_profit()` aangezet. Knobs (`hp1..hp7`, `array_profit`) instelbaar via `strategies.sl_settings`. `sell_lock.py` als gedeelde pure-functions library (één source of truth voor validator én engine). Per-tick trail opgeslagen in `coin_sell_ticks`. Vergelijk-instrument `sell_compare.py` gebouwd (4 varianten: bare/no_ratchet/full/smooth).

**Uitkomst (oracle-validatie):**
- Win/loss-richting: **95%** (630 van 661 DOGEAI-trades correct)
- Exact selling_price: 333→463, exact profit_loss: 334→465
- Totale P&L: **+1279% vs legacy +1102%**
- Winst-lock brengt systeem van 87% naar 95% trouwheid aan legacy

### Stap 2: Engine doorgevoerd op alle live trades (middag)

**Wat:** `persist_to_brain.py` herrekent alle trades DOGEAI + NOS met winst-lock aan. Changelog (`coin_fires_changelog`) schrijft elke klasse-overgang. Handmatige overrides blijven leidend.

**Uitkomst (live trades):**
| Maat | Voor | Na |
|---|---|---|
| Trades | 859 | 868 |
| Verliezers | 608 | **548** (−60) |
| Σprofit | +488,3% | **+579,2%** (+91%) |
| Klasse goed | 86 | 79 |
| Klasse middel | 165 | 241 (+76) |
| Klasse slecht | 608 | 548 (−60) |

De winst-lock kan de stop alleen omhoog zetten → 0 trades zakken van winst naar verlies; 60 stijgen van verlies naar winst.

### Stap 3: UI, beste-sell-datum, terminologie

**Wat:** Beste-sell-datum UI gebouwd (berekend tot volgende koop, niet kale 60-min-max). Harde verkoopdatum. Handmatige klasse-override. Voorrang: handmatig > berekend > legacy. Terminologie vastgelegd: "trades" (niet "coin_fires"), "winst-lock" (niet "ratchet").

### Stap 4: Sell-tuning routine (dag einde)

**Wat:** Dagelijkse per-munt instelknoppen-afsteller (`sell_tuning.py` + `sell_apply.py`). Grid-search op huidige knob-waarden, holdout-gated, toepassen alleen als Σprofit niet omlaag én verliezers niet omhoog. Override-laag in `coin_strategies`. Routine journaalt via `routines.py`.

**Uitkomst (eerste live run):**
| Munt | Σprofit voor | Σprofit na | Verliezers voor | Na |
|---|---|---|---|---|
| NOS | +352,8% | +373,9% | 274 | 266 |
| DOGEAI | +379,9% | +401,2% | 359 | 339 |

Sterkste lever overal: `minimal_profit`.

---

## 2026-06-17 — Critical-eye hardening (avond)

**Wat:** 3 hardening-fixes na critical-eye review:
1. Whitelist voor `apply_safe` (alleen bekende kolommen)
2. Rowcount-check (refire mag trades niet laten verdwijnen)
3. Refire-restore bij commit-fout
4. `sell_x_below` legacy off-by-one fix (telde verkeerde tick)

**Uitkomst:** Σprofit: +940,8% → +951,1%, verliezers 241→240. Fix was klein maar correct — de off-by-one was een echte bug.

---

## 2026-06-23 — 4 critical-eye bugs in de tuning-routine

**Wat:** Deep review van `sell_tuning.py` + `sell_apply.py` vond 4 bugs:

1. **GATE 3 keek alleen naar munt-totaal** — een knop-wijziging op rule 20 die schade naar rule 21 verschuift stond nog als "OK". Nu: munt-Σ ÉN regel-Σ mogen niet zakken.
2. **Fingerprint miste trade-drift** — na een refire (bijv. futureprice-fix) detecteerde de start-gate geen wijziging als alleen `coin_fires` veranderde. Nu: `with_fires` ook voor sell/buy/discovery sets.
3. **Apart-gehouden testperiode globaal geknipt** — `mid=n//2` over alle regels gaf een late regel (zoals rule 21 na 2025) een lege mini-holdout → vals SAFE. Nu: `split_per_rule()` knipt elke regel op zijn eigen mediaan + `MIN_SPLIT=4`.
4. **Toeval-toets ontbrak vóór auto-apply** — gecorreleerde knob-delta's konden SAFE halen puur door toeval. Nu: `_toeval_filter()` met sign-flip toets (Šidák-gecorrigeerd) vóór de dure refire.

**Uitkomst:**
- Bug 3 was niet cosmetisch: per-regel split liet **3-4 SAFE** zien waar de globale split telkens **0 SAFE** gaf
- Toeval-toets keurt ruis correct af: DOGEAI r20 p=0,25 (afgewezen), r21 p=0,0005 (doorstaan)
- Tests: 15/15 (was 11)

**Documenten:** `docs/findings/sell-tuning-critical-eye-fixes-2026-06-23.md`

---

## 2026-06-23 — Probes: twee doodlopende wegen gesloten

**Aanleiding:** Na de critical-eye fixes twee logische vervolgvragen onderzocht (read-only).

### Probe 1: Promising-trades als bredere meetbron

**Hypothese:** Meer promising-momenten → meer gecertificeerde tuning-voorstellen.

**Uitkomst:** NIET de hefboom.
- 98% van de promising-momenten heeft geen echte fire-rule → bucket 20-vervuiling
- Rules 21/22/23 krijgen op de promising-set *minder* trades dan executed
- Gecertificeerd: 1 (executed) = 1 (promising) — geen verbetering

### Probe 2: Amplitude-berekeningen als verkoopsignaal

**Hypothese:** `range_percentage`/`gini`/`iqr_normalized` kunnen vroeg uitstappen triggeren.

**Uitkomst:** GEEN signaal. 0 van 144 kandidaten positief op de holdout (alle p_raw=1,0).

Structureel: zo'n signaal kan alleen *eerder* verkopen. De winst-lock laat winnaars al doorlopen → vroeg uitstappen kapt winnaars af terwijl verliezers via de lock toch al goedkoop zijn.

**Conclusie beide probes:** de echte hefboom is **meer munten gelijktijdig** — niet de meetbron of het exit-signaal.

**Documenten:** `docs/findings/sell-tuning-vervolg-probes-2026-06-23.md`, scripts `probe_promising_tuning.py` + `probe_exit_signals.py`

---

## Huidige stand (2026-06-23)

| Component | Status |
|---|---|
| Winst-lock | ✅ Aan, doorgevoerd |
| Per-tick trail (`coin_sell_ticks`) | ✅ Live |
| Beste-sell / harde verkoopdatum UI | ✅ Gebouwd |
| Sell-tuning routine (dagelijks) | ✅ Live (15:05 elke dag) |
| Per-munt overrides (`coin_strategies`) | ✅ Live |
| 15/15 tests | ✅ |

## Wat nog te bouwen

Zie [sell-engine.md §Nog te bouwen](sell-engine.md):
1. **Rule-101 ontdekking (v2)** — nieuwe verkoopregels uit `coin_sell_ticks` leren
2. **Keten-analyse** — vroeg eruit + herkopen vs vasthouden
3. **Best-sell-gap als lering** — wat had ons eerder uitgeduwd
4. **Meer munten** — echte hefboom voor betere tuning (meer 21/22/23-fires)
