# Technical overview — nobrainersbot

**Architecture, data model, modules, and how to run.** Product-level counterpart: [functional-overview.md](functional-overview.md).

## Components

```
┌─────────────────────────┐     read-only SELECT      ┌──────────────────────────┐
│  bot_signals (legacy)    │ ◀──────────────────────── │  brain/engine (Python)    │
│  MySQL, ~101M ind. rows  │   the ORACLE of truth     │  rules, calc, sell, valid │
│  NEVER written to        │                            └─────────────┬────────────┘
└─────────────────────────┘                                          │ writes results
                                                                      ▼
┌─────────────────────────┐      Eloquent (write)       ┌──────────────────────────┐
│  brain (MySQL)           │ ◀──────────────────────────│  brain/www (Laravel)      │
│  engine_* + app tables   │      reads for screens      │  Livewire screens, admin  │
└─────────────────────────┘                              └──────────────────────────┘
```

- **`brain/engine`** — Python. The trading brain: rebuilds the legacy business rules, validates them against the oracle, replays buy/sell, and (soon) builds the feature store.
- **`brain/www`** — Laravel + Livewire (basewebsite child site). Screens for trades/engine, admin, and eventually the customer SaaS. MAMP at https://brain:8890.
- **`bot_signals`** — the legacy MySQL DB. **Read-only source of truth.** See the `bot-signals-schema` skill for the table map.
- **`brain` DB** — our own results live here (`engine_runs`, `engine_signals`, `engine_subrule_values`, plus the standard basewebsite app tables).

## Connection details

| What | Value |
|---|---|
| MAMP MySQL | `/Applications/MAMP/Library/bin/mysql80/bin/mysql -u root -proot -P 8889 -h 127.0.0.1` |
| Legacy DB | `bot_signals` (read-only) |
| Results DB | `brain` |
| PHP | `/Applications/MAMP/bin/php/php8.4.17/bin/php` |
| Python venv | `brain/engine/.venv/bin/python` (py3.10; lightgbm, sklearn, duckdb, pandas, pymysql, scipy) |
| Web | https://brain:8890 · admin: `daan@interus.nl` |

## The engine modules (`engine/src/`)

| File | Responsibility |
|---|---|
| `calc.py` | Generalized window-metric calculator. `window_metrics(vals)` → the shared metric-set (skewness, std, volatility, range%, max_diff, …). `subrule_value(name, value_condition, vals, prices)` selects which metric a subrule checks. The rebuilt core of legacy `calc_abs_diff_percentage()` + the subrulename selector. |
| `volume.py` | The stateful volume subrules: `missingdata()` and `check_volumeud_3()` (volume-spike-after-accumulation). Per-rule settings via `volume_settings(rule)` (base + overrides for 20/21/22/23). |
| `validate_period.py` | Scaled validator: replays a rule at the exact datetimes legacy evaluated (the oracle datetimes) and compares value + pass + fire verdict. Usage: `validate_period.py [rule] [from] [to]`. Uses the oracle's **historical** boundary (from the settings JSON) — boundaries drifted over time. |
| `validate_rule.py` | Single-datetime validator (debug a specific moment). |
| `sell_lock.py` | SHARED pure functions for the trailing-floor (`parse_sl()` + `lock_profit()` = winst-lock). Used by BOTH the validator and the production engine — one source of truth. |
| `sell_engine.py` | Production sell-engine over brain. `SellEngine(symbol).sell(buy_dt, buy, rule, trace=False, hard_sell_dt=None)`. `trace=True` returns the full per-tick trail. `hard_sell_dt` forces a sell at-or-before that moment. |
| `sell_ticks.py` | Writes the per-tick trail to `coin_sell_ticks` for executed trades (one row per tick: marketprice, profit, peak, floor, lock-price, rule-101 mult, stop, orderstatus). |
| `validate_sell.py` + `sell_rule101.py` | Sell-side oracle replay; winst-lock ON. Win/loss direction 95% vs oracle, exact selling_price 463/661, total P&L +1279% vs legacy +1102%. |
| `run_engine.py` / `populate_engine.py` | Replay the engine over candidates and write results to the brain DB. |
| `poc_rule_filter.py` / `harden_rule_filter.py` | ML PoC for the precision filter (meta-labeling). |

## Key concepts

- **The oracle.** `wp_trading_simulation_trades_indicator` stores, per datetime per subrule: the computed value, `result_ok`, and a `settings` JSON with the boundary that was active **at that time**. This is how we validate: rebuild the calc, compare to the oracle value, and check pass using the oracle's historical boundary.
- **Boundary drift.** Rule bands (`b_min`/`b_max`) were widened over time. Validating against the *current* band gives false mismatches; always use `oracle_bound(settings, key)` from the oracle row.
- **As-of alignment.** A subrule's value at time `T` uses the indicator value at/before `T` (newest-first window, index 0 = most recent ≤ T).
- **`futureprice` is backtest-only.** Legacy disables it live (look-ahead). We treat it as a live "PASS" sentinel (leak-free) — so live rules fire more often than the backtest, which is correct.
- **Sell model.** SL = `max(absolute floor min_sl1·buy, age/profit ladder, winst-lock ratchet, rule-101 multiplier·market)`, never lowered, trailing up from market/peak. `selling_price = stop * stoploss_multiplier` (0.9996 for DOGEAI). `profit_loss = round((selling_price - buy)/buy*100, 3)`. All knobs (`hp_setting1..8`, `array_profit`) configurable in `strategies.sl_settings`. Per-tick trail in `coin_sell_ticks`; overrides in `coin_moment_labels` (`best_sell_datetime`, `hard_sell_datetime`, `manual_klasse`). Heranalyse-log in `coin_fires_changelog`. Detail: [docs/sell-engine.md](sell-engine.md).

## Validation status

| Rule | Fire-agreement vs oracle |
|---|---|
| 20 | 99.6% |
| 21 | 99.96% |
| 22 | 98.8% |
| 23 | 99.7% |
| Sell-side (winst-lock ON) | win/loss direction 95% (530→630), exact selling_price 333→463, exact profit_loss 334→465. Total P&L +1279% vs legacy +1102%. Doorgevoerd op de live trades: 859→868 trades, 608→548 verlies (60 minder), +488→+579% totaal. |

## Reference data points

- **DOGEAI** = `trading_symbol_id` 2525, 5m. First slice: around 25 Feb 2025.
- **NOS** = `trading_symbol_id` 244, 5m (`stoploss_multiplier` 0.9996, `roundingup` 5). Second slice: 16 Nov 2023 → 14 Jan 2024 (data starts 16 Nov; high volatility; 152 good / 119 bad trades).
- The "20 lookbacks": per datetime × indicator, compute `window_metrics` for lookback 1..20 (legacy `save_cache_values`, `functions_br.php:9013`). The substrate for new-rule discovery + ML — see [methodology/feature-store.md](methodology/feature-store.md) and epics A & B.

## Methodology docs

- [methodology/rule-boundary-method.md](methodology/rule-boundary-method.md) — how buy rules are validated against the oracle's historical boundaries.
- [methodology/selling-process.md](methodology/selling-process.md) — the authoritative sell model.
- [methodology/feature-store.md](methodology/feature-store.md) — the precompute substrate (D).
