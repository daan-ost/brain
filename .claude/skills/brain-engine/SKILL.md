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

**Two coins** (everything is rebuilt for both; the optimization validates cross-coin DOGEAI↔NOS):
- **DOGEAI** = `trading_symbol_id` 2525 (fast). First validated slice: ~25 Feb 2025.
- **NOS** = `trading_symbol_id` 244 (slow).
Only these two are in `brain` — screens/analysis show exactly DOGEAI + NOS. `volumeud` is normalised
per coin (raw/min_volume); see the scale guard in [[brain-rule-tuning]].

## Module map (`engine/src/`)

- **`calc.py`** — `window_metrics(vals)` returns the shared metric-set over a newest-first window (first/last, diff_previous_number, max_diff_number, lowest/highest, range_percentage, standard_deviation, volatility, skewness). `subrule_value(name, value_condition, vals, prices)` selects which metric a subrule checks. `calc_percentage(frm,to)` = signed %.
- **`volume.py`** — stateful volume subrules: `missingdata(rows)`, `check_volumeud_3(rows, min_volume, settings)`. Per-rule settings via `volume_settings(rule)` (base `_BASE_VOLUME` + `_VOLUME_OVERRIDES` for 20/21/22/23).
- **`validate_period.py`** — THE validator. `validate_period.py [rule] [from] [to]`. Replays a rule at the oracle datetimes, compares value + pass + fire verdict.
- **`validate_rule.py`** — single-datetime debug validator.
- **`sell_lock.py`** — SHARED pure functions `parse_sl()` + `lock_profit()` (the trailing-floor
  ratchet, ported byte-for-byte from legacy `functions_br.php:4744`). Used by BOTH `validate_sell.py`
  (oracle, bot_signals) and `sell_engine.py` (production, brain) — one source of truth for the ratchet.
- **`sell_engine.py`** — `SellEngine(symbol).sell(buy_dt, buy, rule, trace=False)` → the production sell
  over brain, 1-hour hold. `trace=True` returns the full per-tick trail. **`sell_rule101.py`** — rule-101
  subrules. **`validate_sell.py`** — sell-side replay vs oracle (the gate).
- **`sell_ticks.py`** — writes the per-tick trail to `coin_sell_ticks` (1 row/tick) for the executed
  fires, via `sell(trace=True)`. Daan's "store per datetime ALL values".
- **`persist_to_brain.py`** — THE canonical re-fire/write path: replays rules 20-23 (`RULES` tuple)
  over the in-scope range, applies single-position dedup (first fire opens a position; later overlapping
  fires become `is_executed=0` shadows), and writes `coin_fires`/`coin_periods` with `best_upside` +
  `in_good_period`. Used by `daily_optimization`, `auto_apply`, and the ratio re-measurement.
  (`run_engine.py` / `populate_engine.py` are the older replay scripts; prefer `persist_to_brain.py`.)
- **Automation / tuning layer** — `rq1_tighten.py` (SAFE bad-edge candidate generator), `auto_apply.py`
  (engine-gated apply of the strongest candidate per rule), `daily_optimization.py` (propose-only daily
  pipeline), `routines.py` (journaled routine runner → `/routines`), `rules_history.py` (append-only
  rules audit-trail). See [[brain-rule-tuning]] for the tuning principles and the auto vs manual paths.
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

## Adding a NEW rule_number (e.g. splitting an outlier good trade into its own rule)

There is no single rule-list constant yet — `rule_number` is hardcoded in ~13 spots. To add a rule
(say 24), update them all (or, better as a first step, centralise into one `RULES` constant):
`rule_engine.py` (`RULES` tuple + the `IN (...)` query), `persist_to_brain.py` (`RULES` + the
coin_fires query), `seed_rules.py`, `rq1_tighten.py`, `opt_lib.py`, `opt_diag.py`, `per_rule_band.py`,
`rules_vs_promising.py`, `feature_store.py`, `dry_run_subrules.py`. Also the www screens/pulldowns
(Trades/CoinExplorer) filter to 20-23. A new rule needs its own subrules in `brain.rules` and
`coin_rule_settings` (min_volume) per coin. Validate with a full refire (`persist_to_brain.py`) and
the engine-refire gate (0 good lost, total slecht drops) before keeping it.

## The oracle-validation method (critical)

`wp_trading_simulation_trades_indicator` stores per datetime per subrule: the computed `value`, `result_ok`, and a `settings` JSON with the boundary **active at that time**. To validate:
1. Rebuild the calc, compare to the oracle `value`.
2. Check pass using `oracle_bound(settings, "boundary_low"/"boundary_high")` — NOT the current band.

**Boundary drift** is the #1 gotcha: rule bands were widened over time. Validating against the current band gives false mismatches. Always use the oracle's historical boundary.

**`futureprice` / `futureprice_x_rows`** are backtest-only look-ahead (legacy disables live, `functions_br.php:782`). Treat as live "PASS" sentinel — leak-free; live rules fire more than backtest (correct).

## The sell model (authoritative — see methodology/selling-process.md)

SL = **max of mechanisms, NEVER lowered**, trailing up from market/peak. `determine_stop` runs in order:
1. CHECK 1 — hard floor `min_sl1 * buy` (~0.988) — below it, always sell,
2. CHECK 2 — age/profit ladder `array_profit` (`[[5,-0.4],[7,-0.1],[8,0],[20,0.5]]`) — too little profit for the age → sell,
3. CHECK 3 — trailing breach (prev stop > market) → sell,
4. else new stop = **max(lock_profit ratchet, rule-101 mult × market)** — lock wins unless rule-101 returns `overrule`.

**`lock_profit` ratchet** (the bit that was inert in the old 87% version — NOW ON): keys on
`highest_profit_loss` (percent, incl. current tick). Tiers hp1..5 for small peaks, `+(hi/hp6)/100` (hp6=4)
for 0.7–5%, `+(hi-hp7)/100` (hp7=15, hard) for ≥5%. All knobs (`hp_setting1..8`, `array_profit`) are
configurable in `strategies.sl_settings`. `selling_price = stop * stoploss_multiplier` (0.9996 DOGEAI);
`profit_loss = round((selling_price-buy)/buy*100, 3)`; ROUNDING=16.

Turning the ratchet on lifted oracle agreement: **win/loss direction 80%→95%**, exact selling_price
333→463/661, exact profit_loss 334→465/661. Total P&L now +1279% vs legacy +1102% (a ~16% overshoot
concentrated in ~5 big winners — the remaining gap is **rule-101 sell-signal timing**, Epic S punt 2).
The per-tick trail (`coin_sell_ticks`, byte-for-byte == the legacy log on sim 15212) is the instrument
to debug that. **Promising-moment trails + the rule-101 timing fix are the open follow-ups.**

## Validation status

Rules 20 (99.6%), 21 (99.96%), 22 (98.8%), 23 (99.7%) fire-agreement vs oracle. Rules 10/11/12/18 not yet ported (deferred). Sell-side: ratchet ON, win/loss 95% vs oracle (rule-101 timing is the tail).

## When you change the engine

- Re-run `validate_period.py [rule]` and confirm fire-agreement didn't regress.
- Never compare against current bands — use oracle settings.
- Don't leak future quantities (profit_loss, price-as-time-proxy) into features.
