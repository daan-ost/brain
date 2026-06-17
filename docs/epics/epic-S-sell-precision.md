# EPIC S: Sell-engine precision (87% → higher)

**Phase:** 1 — Foundation BUILT (2026-06-17). Winst-lock aan, doorgevoerd op de live trades, opslag/overrides/log staan.
**Status:** Kern af. Verdere precisie = per-coin tuning (eigen sessie, via workflow).
**Depends on:** `validate_sell.py`, `sell_rule101.py`, `sell_lock.py`, `sell_engine.py`, methodology/selling-process.md, [docs/sell-engine.md](../sell-engine.md).

## Why this exists

The rebuilt sell engine currently reproduces **~87% of total P&L** vs legacy (mine +954.7% vs legacy +1102.0% over DOGEAI closed trades). That is good enough to move on for now, but it is a **silent dependency of Epic A**: Epic A runs the sell engine at *every* datetime in a good window to get "the result per moment". Every one of those results carries the sell engine's error. So before we treat per-datetime outcomes as ground truth — or train anything on them — the sell engine's accuracy must be either improved or explicitly bounded.

## The known gap

The 87% version is conservative and correct on the floor sells (the losing trades). The remaining error is **rule-101 sell-signal timing** — winners run slightly too long or exit slightly early because the exact moment rule 101 tightens the stop is imprecise. The blocker last time was that nailing it needs worked, minute-by-minute examples of the SL trail on a few specific trades.

## What this epic must produce (when picked up)

1. **A bounded error statement.** Per trade, the tolerance between my `profit_loss` and legacy's — so any consumer (Epic A, ML) knows the confidence of a per-datetime outcome.
2. **Rule-101 timing fix.** Reproduce the exact datetime rule 101 sets/raises the stop, using worked examples from `wp_trading_simulation_trades_indicator` for rule 101.
3. **Re-validation.** `validate_sell.py` total-P&L fidelity moves meaningfully above 87%, with the exact/within-0.5% agreement counts reported.
4. **A "confidence" column** carried into Epic A's `period_datetime_outcomes` so screens/graphs can show how trustworthy each per-datetime result is.

## Acceptance criteria

- [x] **Winst-lock aangezet en getrouw geport.** `sell_lock.py` is byte-voor-byte uit legacy `functions_br.php:4744` (lock_profit). Win/loss-richting 80%→95% vs oracle, exacte selling_price 333→463 / 661, exacte profit_loss 334→465 / 661.
- [x] **Knobs instelbaar in data.** `array_profit`, `hp_setting1..8` in `strategies.sl_settings`. Data-migration `2026_06_17_020000`.
- [x] **Per-tick trail-opslag.** Tabel `coin_sell_ticks` (1 rij per tick: marketprice, profit, peak, floor, lock-price, rule101-mult, stop, orderstatus). Byte-voor-byte gelijk geverifieerd vs de legacy sell-log (sim 15212).
- [x] **Doorgevoerd op de echte trades.** Beide coins herrekend: 859→868 trades, 608→548 verlies (60 minder), Σprofit +488→+579% (+91%). Geen winnaar zakt naar verlies.
- [x] **Beste sell-datum begrensd tot volgende koop.** `best_sell_in_window(until_dt=...)` zodat een rebuy-rally niet aan deze trade wordt toegerekend. Legacy `best_selling_datetime` gemigreerd (342 DOGEAI + 123 NOS) en met voorrang: handmatig > legacy > berekend.
- [x] **Handmatige overrides + audit log.** `manual_klasse` leidend (heranalyse overschrijft niet), `best_sell_datetime` + `hard_sell_datetime` invoerbaar in detailscherm, klasse-veranderingen door rerun in `coin_fires_changelog`.
- [x] **Documentatie.** Functionele + technische beschrijving in [docs/sell-engine.md](../sell-engine.md), skill [`brain-sell-engine`].
- [ ] **Per-coin tuning-routine** (volgende sessie, via workflow): de knobs per coin/rule bijstellen op basis van nieuwe trades, met Σprofit als meetlat. NOS levert nu Σprofit in terwijl het 21 verliezers redt — de routine moet die ruil expliciet maken.
- [ ] **Rule-101 timing-staart** — handvol uitschieters (zoals 13069 +127% terwijl legacy +14% pakte). Het diagnose-instrument (de per-tick trail + oracle-log) ligt klaar.
- [ ] **Per-datetime confidence** voor Epic A's outcomes.

## Out of scope

- Exit-policy *optimization* (selling better than legacy) — that is E09. This epic is about **faithfully reproducing** legacy selling, precisely enough to trust per-datetime outcomes.

## Notes

- The sell model itself is settled (max-of-mechanisms, never-lowered, trail from market/peak — see methodology/selling-process.md). This epic is precision, not redesign.
