---
name: brain-rule-tuning
description: How to tune/extend the buy rules (20-23) — the principles for adding subrules and placing their thresholds. Use when proposing or adding subrule conditions for rule precision.
---

How we sharpen the main buy rules (20/21/22/23) by adding subrule conditions, using the
`indicator_metrics` cache. Two principles that OVERRIDE naive "tightest band" thinking.

## Good / bad (the target)

GOOD trade = executed, `best_upside >= 3%` (the opportunity was real — see brain-indicator-metrics).
BAD trade = executed, `best_upside < 0.5%` (slecht — to prevent). Middel (0.5-3%) is the grey zone.
Quality is the best available exit, NOT our sell P&L (the sell-engine is separate, Epic S).

## Principle 1 — as FEW subrules per main rule as possible

Every subrule is an AND. The more conditions, the more **potential good trades you lose** because
they just miss ONE subrule. So: prefer one or two strong, robust conditions over a long stack.
Stacking also overfits (demonstrated). Don't add a subrule unless it survives out-of-sample.

## Principle 2 — place the threshold at the BAD edge, NOT the good edge

When a condition separates good from bad, do NOT set the threshold tight against the good trades
(the good min/max). Set it in the GAP, at the **bad edge** — the most extreme bad trade that lies
just beyond the good band. This leaves a buffer for future good trades.

**Worked example** (lower bound, e.g. vzo range_percentage lb17):
- good trades' lower bound (min) = **−20** → all good are ≥ −20.
- of the bad trades that lie BELOW −20, the highest is **−30**.
- → set the rule's lower bound at **−30**, NOT −20.

Why: a future good trade at −25 still passes (kept) — with a −20 threshold it would be killed. You
still exclude the extreme bad (below −30). You trade dropping the single borderline bad for keeping
the maximum room for good trades. **Favor not-missing-good over dropping-every-last-bad.**

The gap between the good edge and the bad edge is what makes a condition SAFE. No gap (good and bad
overlap/adjacent) → no clean threshold → don't use that feature.

## Two paths: automated (default) vs manual

There are now TWO ways tightenings reach `brain.rules`, distinguished by the `source` column:

- **`auto-applied`** — the daily routine. `routines.py --trigger routine --apply` runs the chain in
  `daily_optimization.py` (refire → rebuild cache → `rq1_tighten.py` → diff vs already-applied) and
  then `auto_apply.py` applies the **single strongest new SAFE rq1 candidate per rule** behind a real
  engine-refire gate: keep ONLY if 0 good trades lost AND total slecht strictly drops, else revert.
  Every run is journaled to `routine_runs`/`routine_run_log` (the `/routines` screen). This is the
  day-to-day driver — most new subrules now arrive this way.
- **`tuned-precision`** — the manual loop below, for things the routine won't do on its own
  (pairs/combos, exploratory candidates, anything you want to vet by hand).

`rq1_tighten.py [rule|all] [min_drop] [--pairs]` is the SAFE-candidate generator both paths consume
(writes `out/opt/rq1_tighten_all.json`); `daily_optimization.new_safe_candidates()` reads it.
`band_gate.py` / `precision_gate.py` are the parquet-based exploratory gates (good-envelope [min,max]
resp. out-of-sample p5-p95 bad-drop) — analysis only, they apply nothing.

## Manual workflow (dry run first, always)

1. `dry_run_subrules.py` — per rule, candidate conditions placed at the bad edge + how many bad they
   prevent (creates nothing).
2. `validate_subrules.py` — out-of-sample: do the held-out GOOD trades STAY (good_keep ~1.0)? Only
   safe candidates qualify.
3. Add at most one or two SAFE conditions per rule to `brain.rules`; re-run `persist_to_brain` +
   `build_indicator_metrics`; re-measure the per-rule good/bad ratio.

## When no single safe condition exists: a PAIR (`combo_subrules.py`)

Some rules (e.g. rule 20) have NO single feature with a clean good/bad gap that drops bad
out-of-sample. Then search a **pair** of bad-edge conditions, AND'd together. Because each keeps
~100% of good (bad-edge buffer), the pair keeps good while dropping the UNION of bad each catches.
`combo_subrules.py [rule] [topK] [min_drop]` ranks pairs by out-of-sample good_keep (must be ≥0.99)
then bad dropped. A pair costs two subrules — only use it when no single condition works (it beat
principle 1 here because rule 20 had no single safe option). Worked: rule 20 vzo skewness lb13
≤1.4173 AND mfi diff_number_prev_min lb17 ≥−22.3 → 1.42→1.68, 0 good lost.

Live record of every added condition: `add_tuned_subrules.py` (source='tuned-precision', idempotent).

## Scale-validity guard (volumeud cache-vs-engine mismatch) — MUST obey

The `indicator_metrics` cache stores **volumeud** as RELATIVE volume (`raw / min_volume`), but the
rule engine evaluates subrule metrics on the RAW volumeud series. A cache-derived threshold is only
valid in the engine if the metric is **scale-invariant**. LEVEL/absolute volumeud metrics
(`median_value`, `*_value`, `diff_number_*`, `max_diff_number`, `standard_deviation`,
`average_reversal_size`) become a silent **no-op** when added to a rule — the threshold is in the
wrong units. INVARIANT metrics (percentage/ratio/count/shape: `*_percentage`, `range_percentage`,
`volatility`, `skewness`, counts, `sideways_*`) are safe. Non-volumeud indicators are never affected.
`opt_lib.scale_unsafe(indicator, calc)` enforces this; `rq1_tighten.py` flags such candidates
`SCALE_UNSAFE` so they can't surface as SAFE. **Always engine-validate a tightening via a full re-fire
(`add_tuned_subrules.py` → `persist_to_brain.py` → ratio) — a cache-only number can be inert.**

## Provenance: `rules_history`

Every rules mutation is logged append-only to `brain.rules_history` (one row per changed rule:
full snapshot + diff vs previous + per-rule toelichting + `source`), written by `rules_history.py`
(hooked into both `add_tuned_subrules.py` and `auto_apply.py`). `rules_history.py show [rule]` prints
the changelog. Reconstruct rule R at version N = latest row for R with version ≤ N. The `source` on a
subrule (`legacy-seed` / `tuned-precision` / `auto-applied`) tells you where it came from.

Related: [[brain-indicator-metrics]] (the calc cache), [[brain-engine]] (rule evaluation).
