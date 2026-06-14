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
| `validate_sell.py` + `sell_rule101.py` | Sell-side: stop-loss (max-of-mechanisms, never lowered) + rule 101 sell-signals. Replays closed trades and compares against the oracle (currently ~87% of total P&L). |
| `run_engine.py` / `populate_engine.py` | Replay the engine over candidates and write results to the brain DB. |
| `poc_rule_filter.py` / `harden_rule_filter.py` | ML PoC for the precision filter (meta-labeling). |

## Key concepts

- **The oracle.** `wp_trading_simulation_trades_indicator` stores, per datetime per subrule: the computed value, `result_ok`, and a `settings` JSON with the boundary that was active **at that time**. This is how we validate: rebuild the calc, compare to the oracle value, and check pass using the oracle's historical boundary.
- **Boundary drift.** Rule bands (`b_min`/`b_max`) were widened over time. Validating against the *current* band gives false mismatches; always use `oracle_bound(settings, key)` from the oracle row.
- **As-of alignment.** A subrule's value at time `T` uses the indicator value at/before `T` (newest-first window, index 0 = most recent ≤ T).
- **`futureprice` is backtest-only.** Legacy disables it live (look-ahead). We treat it as a live "PASS" sentinel (leak-free) — so live rules fire more often than the backtest, which is correct.
- **Sell model.** SL = `max(hard floor ~1% below buy, time-based rising stop, rule-101 indicator-drop stop)`, never lowered, trailing up from market/peak. `selling_price = stop * stoploss_multiplier` (0.9996 for DOGEAI). `profit_loss = round((selling_price - buy)/buy*100, 3)`.

## Validation status

| Rule | Fire-agreement vs oracle |
|---|---|
| 20 | 99.6% |
| 21 | 99.96% |
| 22 | 98.8% |
| 23 | 99.7% |
| Sell-side | ~87% of total P&L (mine +954.7% vs legacy +1102.0%) |

## Reference data points

- **DOGEAI** = `trading_symbol_id` 2525, 5m. First slice: around 25 Feb 2025.
- The "20 lookbacks": per datetime × indicator, compute `window_metrics` for lookback 1..20 (legacy `save_cache_values`, `functions_br.php:9013`). The substrate for new-rule discovery + ML — see [methodology/feature-store.md](methodology/feature-store.md) and epics A & B.

## Methodology docs

- [methodology/rule-boundary-method.md](methodology/rule-boundary-method.md) — how buy rules are validated against the oracle's historical boundaries.
- [methodology/selling-process.md](methodology/selling-process.md) — the authoritative sell model.
- [methodology/feature-store.md](methodology/feature-store.md) — the precompute substrate (D).
