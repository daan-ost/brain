---
name: bot-signals-schema
description: The legacy bot_signals (read-only) MySQL schema — tables, key columns, the oracle table, and which IDs matter. Use when querying or mapping the legacy trading DB.
---

`bot_signals` is the legacy crypto-bot MySQL DB and the **read-only source of truth** for nobrainersbot. SELECT only — never write. Client:

```
/Applications/MAMP/Library/bin/mysql80/bin/mysql -u root -proot -P 8889 -h 127.0.0.1 bot_signals
```

## Core tables

| Table | What it holds |
|---|---|
| `wp_trading_indicator` | Raw indicator series. ~101M rows. Columns: `trading_symbol_id`, `indicator`, `datetime`, `value`, `price`. `indicator='volumeud'` carries **price + value** (from TradingView webhook); a `volume_found` flag marks candidate rows. Indicators: `vzo`, `phobos`, `obv-x-value`, `mfi`, `volumeud`. |
| `wp_trading_simulation` | The found trades. Buy `datetime` + `price`, `selling_price`, `selling_date`, `profit_loss`, `highest_profit_loss`, `lowest_profit_loss`, `rule`, `result` (manual label: 1=goed, 2=middel, 3=slecht; NULL=unlabeled). |
| `wp_trading_simulation_trades_indicator` | **THE ORACLE.** Per datetime per subrule: `value` (computed), `result_ok` (0/1), `settings` JSON with the **boundary active at eval time** (`boundary_low`/`boundary_high`). Keys: `trading_symbol_ID`, `rule_number`, `rule_ID`, `datetime`. This is how rebuilt calcs are validated. |
| `wp_trading_rules` | Subrules. `rule_number` (the rule, e.g. 20), `ID` (subrule), `sort` (eval order, NOT groups), `indicator`, `subrulename`, `def1_value` (window length), `b_min`/`b_max` (band), `value_condition` (JSON), `operator`, `condition_rule`, `active`. |
| `wp_trading_allrules` | Strategies. `ID` (== rule_number), `SL_settings` JSON (stop-loss params: min_sl1, minutes_in_trade1, etc.). |
| `wp_trading_symbols` | Coins. `stoploss_multiplier` (0.9996 DOGEAI), `roundingup` (16). |
| `wp_trading_symbols_rule` | Per-symbol per-rule settings. `settings` JSON → `min_volume` (drives `check_volumeud_3`). |

## Key IDs & values

- **DOGEAI** = `trading_symbol_id` 2525, 5-minute candles (slice 1).
- **NOS** = `trading_symbol_id` 244, 5m, `stoploss_multiplier` 0.9996, `roundingup` 5 (slice 2, window 16 Nov 2023 → 14 Jan 2024; data starts 16 Nov).
- Buy rules in scope: **20, 21, 22, 23**. Deferred: 10, 11, 12, 18.
- **Good-moment / "interesting" routine:** `find_promising_trades()` at `legacy/managesignal/functions_br.php:8719`; tuned settings at `simulate_buy.php:1550`; auto-labeler (`result=1`) at `save_subrule.php`. See brain docs/findings/good-moment-defaults.md.
- Sell rule: **101** (`wp_trading_rules WHERE rule_number=101`).
- `stoploss_multiplier` 0.9996, `roundingup` 16 (DOGEAI).

## Labels

`wp_trading_simulation.result`: 1=goed, 2=middel, 3=slecht, NULL=unlabeled (~10k). Training target is good(1) vs bad(3); middel(2) flows through passively.

## Gotchas

- **+5s buy-time offset (CRITICAL when mapping legacy → brain).** In the LIVE system the rule-engine
  ran continuously on incoming indicators; after a positive signal it waited **exactly 5 seconds** (to
  see if another indicator arrived) and only then recorded the buy. So `wp_trading_simulation.datetime`
  (and the manual `result` labels on it) = the signal tick **+ 5 seconds**. Our brain fires/ticks are at
  the raw indicator datetimes, so an EXACT-datetime join misses ~100% (verified: 0/822 DOGEAI, 0/697 NOS).
  **When importing or joining legacy trades, SUBTRACT 5 seconds** (then snap to the nearest tick for
  jitter). Encoded in `engine/src/align.py` (`LIVE_SIGNAL_DELAY = 5s`, `align_legacy_dt()`), used by
  `import_legacy_labels.py` and `persist_to_brain.py`. Example: legacy `16:24:01` = signal tick `16:23:56`.
- **Boundary drift**: bands widened over time. To validate a rule at datetime T, use the boundary in the oracle row's `settings`, NOT the current `b_min`/`b_max`.
- **As-of**: a subrule value at T uses the indicator value at/before T (`value_condition` `{diff_number:1}`).
- Column casing is inconsistent (`trading_symbol_id` in indicator vs `trading_symbol_ID` in the oracle table) — match exactly.
- 101M rows: always filter by `trading_symbol_id` + datetime window; never full-scan.
