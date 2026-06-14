# Feature store v1 (Epic B) — built + first separation sweep

**Date:** 2026-06-14 · `engine/src/feature_store.py` (build) + `feature_query.py` (sweep).

## What was built

A Parquet store of record (DuckDB-written) holding, per in-scope datetime, the full
`window_metrics` set for every **indicator × lookback 1..20**:

```
features_<sym>_<window>.parquet :  symbol, datetime, indicator, lookback, metric, value
context_<sym>_<window>.parquet  :  symbol, datetime, in_good_period, period_upside, rule_fire, result
```

- **In-scope datetimes:** `full` mode = every volumeud datetime inside a promising period (good ground truth) + every legacy rule-fire. `fires` mode = labeled fires only (fast, for the good/bad sweep over full history).
- **Leak-free:** lookback values at T use only `datetime <= T` (as-of via bisect). No future quantity (profit, selling price) ever enters — those live in `context.result` as labels.
- **Scale:** 5 indicators × 20 lookbacks × 10 metrics = 1000 feature values per datetime. DOGEAI full label span (423 fires) → 423k rows in ~17s.

## The owner's example query — answered in milliseconds

> "for all good trades, for rule X, highest and lowest value of lookback 15"

is a single filtered aggregation over the Parquet (see `feature_store.py` demo + `feature_query.py`).

## First separation sweep (good vs bad fires, Cohen's d)

**DOGEAI** (68 good / 286 bad — full label history):

| feature | mean good | mean bad | d |
|---|---|---|---|
| volumeud highest_value (lb 9–13) | ~64,000 | ~47,000 | 0.55 |
| phobos range_percentage (lb 11) | 56.5 | 4.7 | 0.52 |
| phobos standard_deviation (lb 6–8) | ~6 | ~4.3 | 0.51 |

**NOS** (10 good / 168 bad — small good sample):

| feature | mean good | mean bad | d |
|---|---|---|---|
| volumeud volatility (lb 9–20) | ~13 | ~0.9 | ~2.5 |

## What this tells us

1. **Volume dynamics dominate.** Both coins' top separators are volumeud features (DOGEAI: the recent volume peak; NOS: volume volatility). Good entries come on volume — quantified.
2. **No single feature cleanly separates good from bad on DOGEAI** (best d ≈ 0.55, medium). If one did, the rules would already use it. → **the edge is multivariate**: combine features (ML meta-filter, E03/E06), not one more threshold. This is the core justification for the precision layer.
3. **NOS's huge d ≈ 2.5 is suggestive but small-sample** (10 good fires — the rules caught few good NOS moments, per rules-vs-promising.md). To get enough good examples for training we need `full` mode (promising-period datetimes), not just the rule-fires.

## Next

- Build the `full`-mode store (promising-period datetimes) so good examples aren't limited to the few the rules caught — the training set for the ML filter.
- Thin MySQL rollup for the screens (per rule/coin/metric/lookback: min/max/avg over good vs bad) — Epic B's screen-facing half.
- Feed the top separators into the entry-filter PoC (E03).
