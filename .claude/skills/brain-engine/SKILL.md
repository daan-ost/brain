---
name: brain-engine
description: How the nobrainersbot Python trading engine works — modules, the oracle-validation method, the sell model, connection strings, and the read-only bot_signals constraint. Use when working on engine/src (rules, calc, sell, validation, feature store).
---

The trading brain lives in `brain/engine/` (Python). It rebuilds the legacy `bot_signals` business rules faithfully, validates them against the legacy DB (the oracle), and replays buy/sell. This skill is the map so you don't reconstruct it every session.

## The one hard rule

`bot_signals` is a **READ-ONLY source**. SELECT only, never INSERT/UPDATE/DELETE. All results go to the `brain` DB. Eloquent models that map bot_signals tables block writes in `booted()`.

## Connections

```
Legacy (read-only):  /Applications/MAMP/Library/bin/mysql80/bin/mysql -u root -proot -P 8889 -h 127.0.0.1 bot_signals
Results DB:          same client, database `brain`
PHP:                 /Applications/MAMP/bin/php/php8.4.17/bin/php
Python:              brain/engine/.venv/bin/python   (py3.10: lightgbm, sklearn, duckdb, pandas, pymysql, scipy)
```

DOGEAI = `trading_symbol_id` 2525 (5m). First validated slice: ~25 Feb 2025.

## Module map (`engine/src/`)

- **`calc.py`** — `window_metrics(vals)` returns the shared metric-set over a newest-first window (first/last, diff_previous_number, max_diff_number, lowest/highest, range_percentage, standard_deviation, volatility, skewness). `subrule_value(name, value_condition, vals, prices)` selects which metric a subrule checks. `calc_percentage(frm,to)` = signed %.
- **`volume.py`** — stateful volume subrules: `missingdata(rows)`, `check_volumeud_3(rows, min_volume, settings)`. Per-rule settings via `volume_settings(rule)` (base `_BASE_VOLUME` + `_VOLUME_OVERRIDES` for 20/21/22/23).
- **`validate_period.py`** — THE validator. `validate_period.py [rule] [from] [to]`. Replays a rule at the oracle datetimes, compares value + pass + fire verdict.
- **`validate_rule.py`** — single-datetime debug validator.
- **`validate_sell.py` + `sell_rule101.py`** — sell-side replay vs oracle.
- **`run_engine.py` / `populate_engine.py`** — replay + write to brain DB.
- **`promising.py`** — port of legacy `find_promising_trades` (good-moment definition). `PromisingEngine(symbol, order)` loads the volumeud series; `.promising(entry_dt)` returns highest/lowest_10/checkpoints/verdict. **Order = ascending** (validated vs labels; DESC is a legacy quirk). `_validate()` checks vs result=1/3 labels (DOGEAI 95.1%).
- **`cluster_promising.py`** — dedups overlapping promising moments into periods (`scan_periods`, `best_entry`). One best entry per rise.
- **`rules_vs_promising.py`** — overlays actual legacy rule-fires on promising periods. Shows rules' low recall + low precision vs the good ground truth. Use the recorded-trade overlay (section A), not the current-boundary live re-eval (section B, drifts).
- **`feature_store.py`** (Epic B) — builds the Parquet feature store: per in-scope datetime × indicator × lookback 1..20, the full window_metrics. Modes: `full` (promising-period dts + fires) / `fires` (labeled fires only, fast). Writes engine/data/features/ (gitignored). Leak-free as-of.
- **`feature_query.py`** — sweeps the store: ranks (indicator,lookback,metric) by good/bad separation (Cohen's d). The new-rule / precision-feature discovery primitive. Finding: volume features dominate; edge is multivariate (no single threshold).

## How a buy rule works

A rule (e.g. 20/21/22/23) is a **flat AND** of subrules from `wp_trading_rules` (`rule_number=RULE, active=1`). `sort` is just eval order, NOT groups. Each subrule:
- has a `subrulename` (currentvalue, previous_value, volatility, skewness, range_percentage, volume_check, missingdata, futureprice…),
- a `def1_value` = window length (limit),
- a `value_condition` JSON selecting the metric (`{diff_number:1}` = value at/before the datetime),
- a `[b_min, b_max]` band.
The rule fires when ALL subrules pass.

## The oracle-validation method (critical)

`wp_trading_simulation_trades_indicator` stores per datetime per subrule: the computed `value`, `result_ok`, and a `settings` JSON with the boundary **active at that time**. To validate:
1. Rebuild the calc, compare to the oracle `value`.
2. Check pass using `oracle_bound(settings, "boundary_low"/"boundary_high")` — NOT the current band.

**Boundary drift** is the #1 gotcha: rule bands were widened over time. Validating against the current band gives false mismatches. Always use the oracle's historical boundary.

**`futureprice` / `futureprice_x_rows`** are backtest-only look-ahead (legacy disables live, `functions_br.php:782`). Treat as live "PASS" sentinel — leak-free; live rules fire more than backtest (correct).

## The sell model (authoritative — see methodology/selling-process.md)

SL = **max of three mechanisms, NEVER lowered**, trailing up from market/peak:
1. hard floor ~1% below buy (`min_sl1 * buy`) — max 1% loss,
2. time-based rising stop (age ladder `[(5,-0.4),(7,-0.1),(8,0),(20,0.5)]`),
3. rule-101 business exits (indicator drop → stop to ~99.5% market).

`selling_price = stop * stoploss_multiplier` (0.9996 DOGEAI). `profit_loss = round((selling_price-buy)/buy*100, 3)`. ROUNDING=16. Current fidelity ~87% of total P&L.

## Validation status

Rules 20 (99.6%), 21 (99.96%), 22 (98.8%), 23 (99.7%) fire-agreement vs oracle. Rules 10/11/12/18 not yet ported (deferred). Sell-side ~87%.

## When you change the engine

- Re-run `validate_period.py [rule]` and confirm fire-agreement didn't regress.
- Never compare against current bands — use oracle settings.
- Don't leak future quantities (profit_loss, price-as-time-proxy) into features.
