# Precision: greedy gate-stacking overfits — the data is too thin for hand-rules

**Date:** 2026-06-14 · `engine/src/precision_gate.py` (single-gate sweep) + `precision_stack.py`
(greedy stacker). Both validated out-of-sample (train 70% / test 30% by time).

## The question

Per coin, can we stack single-feature gates (keep fires inside the good fires' [p5,p95] band)
until `#bad <= #good`? Goal ratio ≥ 1.

## Single gates DO carry out-of-sample signal (modest)

| coin | best single gate | good kept (oos) | bad dropped (oos) |
|---|---|---|---|
| DOGEAI | `vzo range_percentage` (lb 9–17) | ~100% | 28–34% |
| NOS | `phobos volatility` (lb 2–10) | ~82% | 45–50% |

So one robust gate drops ~30–50% of bad while keeping most good — real, generalizing signal.

## But STACKING them overfits hard

Greedy stack (gates chosen on train, measured on held-out test):

| coin | train ratio | **test ratio** | test good kept | test bad dropped |
|---|---|---|---|---|
| DOGEAI | 0.21 → **0.94** (6 gates) | 0.08 → **0.07** | 3/9 | 75/118 |
| NOS | 0.14 → **1.07** (4 gates) | 0.18 → **0.18** | 2/11 | 50/61 |

The stack looks like it reaches the goal in-sample (ratio ~1) but the test ratio **does not
improve** — the few good test fires get dropped alongside the bad. A naive pipeline would have
shipped a "ratio 0.94" rule that fails in production. This is THE overfitting trap the project
flagged, demonstrated.

## Root cause

Too few good fires (60 DOGEAI / 31 NOS — the rules rarely fire at a real promising moment).
Bands fit to ~50 good points don't cover the ~10 held-out good points; with ~1000 features,
greedy selection finds spurious combinations.

## What this means for the direction

1. **Hand-stacked rule gates are not the way to precision here** — not enough good fires to fit
   multi-feature bands that generalize. One robust single gate is the most we can safely add.
2. **The real precision win needs more good examples and/or regularization:**
   - Train the filter on the **534 promising best-entries** (the good ground truth), not just the
     ~60 good fires — far more positives. (The filter learns "what a promising moment looks like"
     and applies it to drop bad fires.)
   - Use **ML with cross-validation + regularization** (the meta-filter, E03/E06) rather than
     greedy band-stacking — far more robust to small samples. Report only cross-validated metrics.
3. **Recall may have to come first:** with only 60 good fires, the precision model is starved.
   Discovering NEW rules that fire inside the missed promising periods would both raise recall AND
   produce more good fires to train the precision filter on.

## Immediate safe win

A single gate (e.g. DOGEAI `vzo range_percentage` lb≈11, or NOS `phobos volatility` lb≈3) drops
~30–50% of bad fires out-of-sample at ~no good loss. Apply one per coin as a conservative
precision filter while the ML/recall work proceeds.
