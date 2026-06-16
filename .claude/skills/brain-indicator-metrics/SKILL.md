---
name: brain-indicator-metrics
description: The indicator_metrics calculation cache (brain) — what it holds, how it's filled, and the rule to keep it current. Use when working on rule precision / the per-rule band analysis, or when trades/promising periods change.
---

`indicator_metrics` is the precomputed **calculation cache** in the brain DB: per coin/datetime/
indicator/lookback, all ~29 "Test type" calculations. It is the substrate for the per-rule
precision band-analysis (and later ML). Rule-INDEPENDENT (the values don't depend on any rule;
each rule's subrules read the calcs they need). Mirrored to Parquet for fast sweeps.

## Shape (option A)

One row per **(trading_symbol_id, datetime, indicator, lookback)** with the 29 calculations as
columns. `lookback` 1..20 ("voor zover mogelijk" — fewer if not enough history). The 5 indicators:
vzo, phobos, obv-x-value, mfi, volumeud (volumeud stored as RELATIVE volume = value/min_volume).

The 29 calcs = `engine/src/calc.py` `WINDOW_METRIC_KEYS` (ported from legacy
`calc_abs_diff_percentage`): current/first/last_value, diff_previous (value+number), max_diff
(number+%), diff_prev_max/min (number+%), sum_avg_positive_%, lowest/highest/sum_value,
diff_lowest/highest_value_period, standard_deviation, volatility, range_percentage,
consecutive_increases/decreases, reversal_count, average_reversal_size, median_value, skewness,
count_positive/negative, max_same_value.
Plus **sideways_upper / sideways_lower** (checkSideWays band — extremes removed).

**The other "special" Test types are NOT cached, because in legacy they were never implemented:**
`trend_up_and_down` (empty case), `increase_all_indicators` (0 occurrences),
`profit_change_compared_to_current` (0 occurrences) — UI radio buttons only. And `fast_increase`
is a price-only fixed-7-window classifier (the "te snelle stijging" detector) → `calc.fast_increase()`
for rule-eval, not a per-(indicator,lookback) cache column.

## Scope (which datetimes)

Every datetime **inside a promising period** (coin_periods span) + **every trade** (coin_fires) +
**every OK-marked moment** (`coin_moment_labels` `decision='yes'` — the owner's confirmed good entries
from the labeler, see [[brain-promising-labeler]]). We ALWAYS compute laag 2 for the ok-moments too,
even if they fall outside a promising period (snapped to the volumeud tick at/≤ the label). Only ~2-3%
of all ticks (DOGEAI 274k raw → ~5.8k computed; NOS 190k → ~6.1k) — not all 640k (that would be too
much). These are exactly the moments the precision analysis + the keeper/labeler/ML work need
("op aankoopdatum alle waardes"). The raw indicator VALUES exist for all ticks (`indicators`); only the
computed window-calcs are scoped.

## Fill / maintenance — THE RULE

`engine/src/build_indicator_metrics.py [symbol_id ...]` rebuilds the cache for a coin (brain table
+ Parquet mirror, idempotent per symbol). No arg = rebuild all coins; the daily routine calls it
without args via `daily_optimization.run("build_indicator_metrics.py")` right after the refire.

**Keep it current: re-run for a coin whenever its trades or promising periods change** —
- after `persist_to_brain.py` (new/changed trades or periods),
- after new indicators are imported (`import_indicators.py`) or the promising definition changes,
- after a rule change that alters which datetimes are trades.

Order: `import_indicators` → `seed_rules` (once) → `persist_to_brain` (periods + trades) →
`build_indicator_metrics` (the cache). The cache depends on coin_periods + coin_fires, so it is
ALWAYS rebuilt last.

## Querying (the per-rule band analysis)

The owner's question — "for all good trades, for rule X, the min/max of skewness at lookback 15,
and which bad trades fall outside" — is a single SQL join:

```sql
SELECT MIN(m.skewness), MAX(m.skewness)
FROM indicator_metrics m
JOIN coin_fires f ON f.trading_symbol_id=m.trading_symbol_id AND f.datetime=m.datetime
WHERE m.indicator='vzo' AND m.lookback=15 AND f.is_executed=1 AND f.best_upside>=3;  -- good trades
```

The Parquet mirror (engine/data/metrics/) is faster for large cross-rule sweeps from Python/duckdb.

## Connections / faithful rebuild

Reads ONLY brain. Related: [[brain-engine]], [[bot-signals-schema]]. The calc port lives in
`calc.py`; validate any calc change keeps the promising validation stable.
