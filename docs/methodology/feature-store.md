# Feature store (D) — the precompute substrate for discovery + ML

**Date:** 2026-06-13 · Spec captured from Daan. Refines epics E01 (data foundation) and E02 (labeling).

## Purpose

The redundant, precomputed data layer that everything in the precision/discovery phase stands on: finding **new business rules**, measuring **recall/precision per rule**, and training the **ML filter**. Compute once, store, query fast — instead of recomputing on every analysis.

## What is stored, per datetime

For each relevant **datetime**, store both:

1. **Feature values** — `window_metrics()` (`calc.py`) computed per **indicator × calc-type × lookback**, with **lookback = 1..20** (the legacy `save_cache_values` 20-deep cache). i.e. for each indicator (vzo, phobos, obv-x-value, mfi, volumeud) and each lookback 1..20, the full metric set (skewness, std, max_diff, reversal_count, range%, median, …). This is the "huge data".
   - Stored per rule/coin/indicator **where useful** (not blindly all 100M — driven by the active coins/rules/periods we take over iteratively).

2. **Sell-engine outcome** — run the sell-engine check **from this datetime** (as if bought here) → the result: `selling_price`, `profit_loss %`, time-in-trade, the excursion. So **per datetime you know what the trade would have returned** if entered there.

## Scope — not just actual trades

Store the above for:

- **All actual trades** (per rule/coin/indicator).
- **All good *potential* trade windows** — a from→to period where a good trade *could* start — **even if no rule currently buys it.** For every datetime inside that window: compute the features AND run the sell-engine check, so we know the potential result at every entry point in the window.

This is the key: we capture the **ground truth of good moments independent of the current rules** (Daan's point: "some good moments are already caught by rules, some not"). It turns "which datetimes are good entries" into measurable data.

## Why this unlocks the precision phase

With this store we can:
- **Find new rules** — for the good moments, compute feature-bands across **all** ~30 calc-types × 5 indicators × 20 lookbacks (not just the few the current rules use) and search for the combination that best separates good from bad. Uncaught good moments → a new rule.
- **Measure recall/precision per rule** — which good moments each rule catches, and how much bad it admits.
- **Train the ML filter** — the features are the X, the labels/sell-outcomes are the y.
- **Automate the best sell moment** — since every datetime in a window has its sell-outcome, we can later learn the optimal sell datetime / "don't-sell-after" window (currently manual).

## Building blocks (already present)

- `calc.py::window_metrics()` — computes the metric set for a window (the per-feature calc).
- `volume.py`, `sell_rule101.py`, `validate_sell.py` — the sell-engine logic to produce the per-datetime outcome.
- The buy-side engine (`run_engine.py`, `validate_period.py`) — identifies rule fires; the feature store extends this to *all* datetimes in the chosen windows, not just fires.

## Build outline (later)

1. Pick a coin/period slice (iteratively — not all 100M).
2. Define the good-potential windows (from C: the good-moment definition).
3. For each datetime in scope: precompute the 20-lookback feature set + the sell-engine outcome.
4. Store in brain DB tables (the redundant storage), queryable for discovery + ML.
