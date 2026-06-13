# EPIC 01: Data foundation & leak-free feature store

**Phase:** 0 — Foundation
**Status:** Planned
**Depends on:** nothing (entry point)

## Goal

Build the data substrate everything else stands on: export a manageable slice from the read-only `bot_signals` source into a fast analytical store, and a feature pipeline that turns the base indicator series into model features under a strict point-in-time contract.

## Rationale

The source has ~101M indicator rows; we never query it live for research and we never write to it. We need a clean, reproducible, leak-free dataset. Getting this wrong (especially look-ahead leakage) makes every downstream result a lie.

## Scope

1. **Slice export.** Copy only what a run needs from the read-only source into `bot_signals_slice` (or Parquet files). First slice: DOGEAI 5m (`trading_symbol_id=2525`), 2025-02-25, plus ~3h margin before the window for lookback. Tables: `wp_trading_indicator` (filtered), `wp_trading_simulation` (labels), `wp_trading_simulation_trades_indicator`, `wp_trading_symbols` (the chosen rows), `wp_trading_rules` + `wp_trading_allrules` (full, small). Export is **SELECT-only** from the source.
2. **Analytical store.** Load the slice into DuckDB/Parquet for fast columnar access from Python (pandas/Polars). Reproducible: same query → same dataset, versioned.
3. **Base-indicator series.** Per coin, assemble the time series of the 5 base indicators (`vzo`, `phobos`, `obv-x-value`, `mfi`, `volumeud`) from `wp_trading_indicator`. These are consumed as-is (TradingView black-box), never recomputed.
4. **Derived feature pipeline.** Compute the "Test type" library features over configurable windows of the base series: skewness, std deviation, volatility, range %, reversal count, consecutive increases/decreases, median, slope, drawdown, volume-sum, sideways, etc. Each feature is a pure function of a window.
5. **Point-in-time contract (the leakage guard).** Every feature for an entry at time `t` uses only rows with `datetime < t`. Build an automated check that flags any feature touching `datetime >= t`. Quarantine "future" quantities (`highest_profit_loss`, `selling_price`, "Future price") as label-only, never features.
6. **Feature store.** Persist computed features keyed by (symbol, datetime, window) so they're computed once and reused across models and runs.

## Acceptance criteria

- [ ] A documented, repeatable export produces the DOGEAI 25 Feb slice without writing to `bot_signals`.
- [ ] The slice loads into DuckDB/Parquet and is queryable from a Python notebook in seconds.
- [ ] The derived feature pipeline computes ≥10 features from the "Test type" library over the 5 base indicators.
- [ ] The leakage guard runs automatically and fails if any feature references data at/after the entry timestamp.
- [ ] A feature matrix exists: one row per labeled trade in the slice, columns = features, all point-in-time correct.

## Out of scope

- Modeling (E03), labeling logic (E02), multi-coin/multi-day scaling beyond the first slice.

## Notes

- Base indicators come from TradingView via webhook; we cannot reproduce them from price. Derived features are ours.
- Keep the export parameterized (symbol_ids, date window) so E07/E08 can reuse it at scale later.

## Recommended tooling & prior art (from research)

> Provenance: verified research bundle + adversarial verification pass (this supersedes the earlier empty-payload note). Maturity figures from the research; re-confirm versions at install time.

**Analytical store & feature compute** [VERIFIED — benchmarked]
- **DuckDB** 1.2+ (https://duckdb.org) — production-grade to 100M–1B rows; **wins decisively on memory** for large Parquet scans (~1.3 GB peak vs Polars ~17 GB on a 140 GB file). Has native `REGR_SLOPE` (our slope feature) and **`ASOF JOIN`** to align the 5 base indicator streams at different timestamps.
- **Polars** 1.x (https://github.com/pola-rs/polars) — Rust-backed, lazy, lower memory than pandas; native `rolling_std`/`rolling_mean`/`rolling_skew`. **Caveat:** `rolling_skew` is documented-slow for large windows (issue #17339, https://github.com/pola-rs/polars/issues/17339) — **route the skewness feature through a DuckDB window function instead**, or benchmark explicitly.
- **Parquet** for versioned slices. **File layout dominates engine choice:** partitioning by coin+date reduces DuckDB memory ~8× — do this before optimizing the engine. Benchmark source: https://www.codecentric.de/en/knowledge-hub/blog/duckdb-vs-polars-performance-and-memory-with-massive-parquet-data

**Indicator primitives for the derived 'Test type' library** [ESTABLISHED]
- **TA-Lib** 0.6.x (https://github.com/TA-Lib/ta-lib-python) — Statistic Functions (`STDDEV`, `VAR`, `LINEARREG_SLOPE`, `LINEARREG_ANGLE`, `TSF`) map directly to several derived features; 0.6.x ships pre-built wheels (verify the wheel for your Python/arch in CI before committing it as a build dep).
- **pandas-ta-classic** (https://github.com/xgboosted/pandas-ta-classic) — the SAFE successor. **Do NOT install the original `pandas-ta`**: its PyPI history was wiped in a 2025 maintainer-transfer incident that the community flagged as a supply-chain risk.

**Offline feature discovery (NOT the live path)** [VERIFIED — slow]
- **tsfresh** (https://github.com/blue-yonder/tsfresh) — extracts 794 features + built-in **FRESH** selection (Benjamini-Yekutieli FDR control). Run it **once, offline** on the labeled slice to discover which features carry signal, then **re-implement only the survivors (expect 30–80) in Polars/DuckDB** for live computation. It takes 5–15 min per 10k observations on 8 cores — far too slow for 5m inference, and ~50% of its features are correlated FFT coefficients.
- **tsfel** (https://github.com/fraunhoferportugal/tsfel) — fractal/entropy features (sample entropy, Higuchi fractal dimension) that TA-Lib/pandas-ta lack; candidate regime-detection signals.

**The right name for the leakage guard** [VERIFIED]
- This is **point-in-time (PIT) correctness / look-ahead-bias prevention** and, specifically, **label-concurrency control** (López de Prado, *AFML* Ch. 7). A feature for entry `t` may touch only rows with `datetime < t`. Quarantine `highest_profit_loss`, `selling_price`, 'Future price' as label-only. Implement the guard hand-rolled and auditable; add a deliberately-leaky test feature to prove the guard catches it. Reference: 'Label Concurrency: The Hidden Flaw in Financial ML Pipelines' — https://www.mql5.com/en/articles/19850

**Honest caveat** [REASONED]
- The research reasoned about a '~3,400-trade' set — that is our good+bad core only. We have 15,262 rows (10,356 to auto-label in E02). Keep the feature store keyed by (symbol, datetime, window) and a label version hash so the larger set stays reproducible.

**References**
- López de Prado, *AFML* (2018), Ch. 7 — https://www.wiley.com/en-us/Advances+in+Financial+Machine+Learning-p-9781119482086 · Empirical evaluation of time-series feature sets (arXiv 2110.10914) — https://arxiv.org/abs/2110.10914 · DuckDB ASOF Joins — https://duckdb.org/2023/09/15/asof-joins-fuzzy-temporal-lookups
