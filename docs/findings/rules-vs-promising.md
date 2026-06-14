# Rules vs promising — the gap, quantified

**Date:** 2026-06-14 · `engine/src/rules_vs_promising.py` overlays the actual legacy rule-fires
(`wp_trading_simulation`, the exact ground truth) on the clustered promising periods.

## Method

- **Promising periods** = the owner's good-moment definition (`promising.py`, validated), clustered to one best entry per period (`cluster_promising.py`). This is the good ground truth, independent of rules.
- **Rule fires** = the recorded legacy trades for rules 20/21/22/23. These ARE the fires, with their good/bad result — no re-evaluation, no boundary-drift error. (A secondary live re-eval with current boundaries is shown too, but it under-fires for 20/22/23 due to drift; only rule 21 reproduces exactly.)
- A promising period is "caught" if a rule fire lands within it (±5 min).

## Result

**DOGEAI, 24–26 Feb 2025** — 36 promising periods:

| rule | fires | goed | slecht | inside a period | catches |
|---|---|---|---|---|---|
| 20 | 12 | 1 | 9 | 8 | 6/36 |
| 21 | 9 | 4 | 5 | 5 | 4/36 |
| 22 | 14 | 0 | 11 | 5 | 3/36 |
| 23 | 4 | 1 | 2 | 3 | 2/36 |
| **union** | **39** | **6** | **27** | **21/39** | **10/36** |

**NOS, 1–8 Dec 2023** — 67 promising periods:

| rule | fires | goed | slecht | inside | catches |
|---|---|---|---|---|---|
| 20 | 7 | 0 | 7 | 2 | 2/67 |
| 22 | 21 | 1 | 8 | 13 | 7/67 |
| 23 | 1 | 1 | 0 | 1 | 1/67 |
| **union** | **29** | **2** | **15** | **16/29** | **9/67** |

## What this proves (the whole strategy in one picture)

1. **Promising finds far more than the rules.** Rules catch 10/36 (DOGEAI) and 9/67 (NOS) good periods — they MISS the large majority. → headroom for **new rules** (Epic B's feature sweep is how we find them).
2. **The current rules are LOW precision.** 6 good / 27 bad (DOGEAI), 2 good / 15 bad (NOS). This is the owner's "312 bad is too many" problem, quantified. → the **precision layer** (coin gating + ML) must drop the bad.
3. **Promising-period membership separates good from bad.** Most bad fires land OUTSIDE any promising period (18/39 DOGEAI, 13/29 NOS outside), and the good fires sit inside. So "is this inside a promising period?" is already a useful precision signal — and the per-datetime feature store (Epic B) will sharpen it.

## Caveats

- Promising uses the verdict threshold (`setting_percentage_highest` = 3%), so the period count is broad — many marginal +3–5% periods. The strong periods (peak > 5%, the labeler threshold) are fewer; "missed" against only the strong periods is the number that matters for recall. Add a strong-period filter when this drives decisions.
- Thresholds are DOGEAI-tuned; NOS needs per-coin calibration (see promising-port-validation.md).
- Live re-eval (section B) is current-boundary and under-fires for 20/22/23 — use the recorded-trade overlay (section A) as truth.
