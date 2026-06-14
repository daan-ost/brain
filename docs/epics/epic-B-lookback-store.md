# EPIC B: Per-rule lookback feature store (fast-queryable)

**Phase:** 0 — Foundation (build now)
**Status:** Planned
**Depends on:** `calc.py` (window_metrics), Epic A (good windows) — but can be built in parallel.
**Refines:** E01 (data foundation / feature store) — this is the concrete, query-shaped version.

## Goal

Store **all computed values per rule / coin / indicator**, for **lookback 1..20**, for every relevant datetime — and lay it out so future analysis queries are **fast**, e.g.:

> "For all good trades, for rule 20, give the highest and lowest value of lookback 15 of the skewness feature."

Compute once, store, query in milliseconds — instead of recomputing over 101M rows every time.

## Rationale

New-rule discovery and the ML filter need to sweep across **all** calc-types × indicators × lookbacks (1..20), not just the few a current rule uses. That only works if the values are precomputed and stored in an aggregation-friendly shape. The legacy `save_cache_values` (`functions_br.php:9013`) already built a 20-deep cache per datetime; this epic makes it a durable, queryable store.

## Scope

1. **What to compute.** For each `(symbol, datetime, indicator, lookback ∈ 1..20)`, compute the full `window_metrics` set (skewness, std, volatility, range_percentage, max_diff, diff_previous, lowest, highest, …). "Per rule" = tag rows with the rule(s) whose subrules reference that `(indicator, lookback)` so a query can scope to "rule 20's features", but the underlying values are rule-agnostic (computed once, shared).
2. **Scope of datetimes.** Driven iteratively (not all 101M): the datetimes of real trades + the datetimes inside Epic A's good windows + rule-fire datetimes. Keyed so more coins/periods append cleanly.
3. **Storage choice (the key decision).** Optimize for `MAX/MIN/AVG of feature F at lookback L across a set of datetimes`. Options:
   - **DuckDB / Parquet** (recommended per E01 research) — columnar, partition by `symbol+date`, fast aggregations, low memory; Python-native. Best for analytical sweeps.
   - **MySQL table** in brain — `feature_values(symbol_id, datetime, indicator, lookback, metric, value)` long-format, indexed on `(symbol_id, indicator, lookback, metric)`. Simpler to join with Laravel screens, heavier for big sweeps.
   - Likely **both**: DuckDB/Parquet as the analytical store of record; a thin MySQL summary for screens.
4. **Layout for speed.** Long/tidy format `(symbol, datetime, indicator, lookback, metric, value)` partitioned by symbol+date. This makes the example query a single filtered aggregation. Store a `params_hash`/`feature_version` so recomputes are reproducible and diffable.
5. **Leakage contract.** Every value at datetime `t` uses only indicator rows with `datetime ≤ t` (as-of). No future quantity ever enters this store (those are labels, Epic A's outcomes).
6. **Query helpers.** A small Python API (and a couple of SQL views) for the common questions: per-rule feature ranges over good vs bad trades; distribution of a metric at a lookback; good-vs-bad separation for a `(indicator, lookback, metric)` triple (the new-rule search primitive).

## Acceptance criteria

- [ ] For the DOGEAI slice, the store holds `window_metrics` for every indicator × lookback 1..20 at every in-scope datetime.
- [ ] The example query — "highest and lowest value of lookback 15 of metric X across all good trades for rule 20" — runs in well under a second.
- [ ] Values match `calc.py` / the oracle where comparable (spot-check vs `wp_trading_simulation_trades_indicator`).
- [ ] The store is leak-free (as-of contract enforced; a deliberately-leaky test value is caught).
- [ ] Reproducible: same `feature_version` → identical store; appending a new coin/period doesn't rebuild everything.
- [ ] A documented query helper answers the per-rule feature-range questions used by new-rule discovery.

## Out of scope

- The actual new-rule search and ML training (later precision epics) — this epic is their fast substrate.
- The good-period definition and per-datetime sell outcomes (Epic A).
- Rules 10/11/12/18 (deferred).

## Open questions (for Daan)

- Confirm storage of record: DuckDB/Parquet for analysis + a thin MySQL summary for screens — agreed?
- Which metrics are "useful" enough to always store vs compute-on-demand? (Default: store the full `window_metrics` set — it's cheap once.)
- Lookback unit: confirmed as **count of values (1..20)**, not minutes? (Legacy `save_cache_values` is count-based.)
