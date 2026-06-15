---
name: brain-promising-labeler
description: How the per-moment Promising labeler works — labeling buy-moment quality (upside, sell-independent) vs sell-result (profit_loss), the coin_moment_labels store with natural key (coin+datetime+rule) that survives the re-fire, the legacy ok_trade/result import, and how labels tune the promising thresholds + feed the rule-success counts. Use when building or changing the labeler screen, the label store, the legacy import, or the label→threshold/rule-success feedback loop.
---

How we hand-label "promising" buy-moments and tune the auto-classification from those labels.
Build spec: `docs/epics/epic-L-promising-labeler.md`. Two invariants this protects:
**(1) buy-moment quality is NOT the sell result**, and **(2) manual labels must never live on
`coin_fires`** — the re-fire deletes that table.

## The two metrics — never conflate

- **Buy-moment quality** = `coin_fires.best_upside` (max favorable excursion over `FORWARD_MINUTES`,
  sell-INdependent) + the multi-horizon upsides (+5/+10/+15/+30/+45/+60 min) + `lowest_10` (early dip
  over the first ~10 ticks). Answers "was there money to make?". This is what `manual_klasse` labels
  and what `promising.py` tunes against.
- **Sell result** = `coin_fires.profit_loss` (what the sell-engine actually realised). A row with high
  upside but negative `profit_loss` is a **sell-engine defect**, NOT a bad buy-moment. Keep it in its
  own column, colour it red, never let it pollute the promising tuning. Sell-engine fixes = Epic S.

## Auto-classification (single source of truth)

- Upside klasse thresholds live in `engine/src/opt_lib.py` and are mirrored in `CoinFire::klasseKey()`:
  goed ≥ 3% / middel 0.5–3% / slecht < 0.5% (on `best_upside`).
- Promising verdict (`in_good_period`) gates in `engine/src/config.py` (verified): `FORWARD_MINUTES=60`,
  `MIN_UPSIDE_PCT=5.0`, `MAX_EARLY_DIP_PCT=-0.1`, `MIN_DURATION_MINUTES=10`, `DROP_BELOW_PCT=-0.3`,
  per-coin `UPSIDE_MINUTES_PER_COIN={2525:25, 244:45}` (DOGEAI fast, NOS slow; default 30).

## The label store — `coin_moment_labels` (NOT coin_fires)

Natural key `(trading_symbol_id, datetime, rule, source)`. `source` = 'manual' | 'legacy'.
Columns: `decision` (yes/no/no_volume = legacy ok_trade), `manual_klasse` (goed/middel/slecht,
overrides `klasseKey`), `category` + `comment` (`CoinAnnotation::CATEGORIES`), `legacy_result` (1/2/3).

**WHY a separate table — the overschrijf-risk:** `engine/src/persist_to_brain.py:45-46` does
`DELETE FROM coin_fires` + `DELETE FROM coin_periods` per symbol on EVERY re-fire and re-inserts
WITHOUT any manual label (the INSERT column list has no `manual_klasse`). Anything stored on
`coin_fires.manual_klasse` is gone the next time a routine runs. `coin_moment_labels` is never touched
by persist_to_brain → labels survive. (The old `coin_fires.manual_klasse` string column had no enum
constraint and was wiped on re-fire — it is superseded by this table.)

Override precedence in `CoinFire::klasseKey()`: **manual label > legacy label > computed best_upside**.
Eager-load the label relation in any list view to avoid N+1.

## Legacy import

Source: `bot_signals.wp_trading_simulation.result` (1=goed, 2=middel, 3=slecht, NULL=unlabeled).
Read-only via the `bot_signals` connection. Verified counts (Jun 2026): **4.161 labeled total**
(948/710/2503). For tracked coins on scope-rules 20–23: **DOGEAI (2525) 74/36/301**, **NOS (244)
11/8/169** — heavily slecht-skewed, which is exactly why success criterion 1 (#goed ≥ 2×#slecht)
fails and why per-moment precision matters.

`persist_to_brain.py` already joins `wp_trading_simulation` as `coin_fires.legacy_result` /
`legacy_profit_loss` (reference only). The import turns those into `coin_moment_labels` rows with
`source='legacy'`, idempotent `updateOrCreate` on the natural key: `{1:goed,2:middel,3:slecht}` →
`manual_klasse`, `{1:yes,3:no}` → `decision`, `result` → `legacy_result`. Show legacy vs manual side
by side in the labeler — divergence is the learning signal; it often comes down to a single datetime.

## Feedback loop (decision: visible-only first)

1. **Threshold tuning (advice-only)** — `promising._validate(symbol, order)` (promising.py:147) already
   validates the verdict against the labels; extend to a grid-search over `MIN_UPSIDE_PCT` /
   `MAX_EARLY_DIP_PCT` / `upside_minutes(symbol)` per coin → per-coin precision/recall → proposed
   config.py thresholds. A human edits config.py; never auto-apply.
2. **Rule-success counts** — `engine/src/daily_optimization.py:54` `current_ratios()` counts raw
   `SUM(best_upside>=3)` / `SUM(best_upside<0.5)` on coin_fires and **ignores labels**. Add a SECOND
   ratio that LEFT JOINs `coin_moment_labels` (source='manual') and uses the effective klasse
   (manual > best_upside). Show BOTH ratios (raw vs labelled) in the routine journal. Per the
   decision, labels do NOT yet drive tightening — visible-only until validated.

## The labeler screen

Route `/promising-labeler` (`trades.labeler`), Livewire `App\Livewire\Trades\PromisingLabeler`,
admin-only, fire-level (legacy `simulate_buy.php` was per buy-moment). Coin + day navigation like
`CoinExplorer`. Reuse CoinExplorer's `zoomChart()` / `coinChart()` chart stack verbatim (
`coin-explorer.blade.php:224-277`); add horizon-peak markers. Columns: time | rule | bought |
+5/+10/+15/+30/+45/+60m upside (tooltip: peak price+time) | best_upside | peak@ | early dip |
OUR sell profit_loss | auto-verdict | legacy label | my label. Red row = positive upside but
negative profit_loss = sell-engine left money on the table. Sidebar link in
`layouts/trading.blade.php` after "Coin explorer" (~line 35), using the `route()` + `routeIs()`
active-state pattern.

## Gotchas

- Validate `manual_klasse` against the enum on write — the old string column silently accepted typos.
- Horizon upsides use TRUE time windows (the legacy `simulate_buy` used count/12 index fractions —
  do NOT copy that). Early dip `lowest_10` is over the first ~10 TICKS (volumeud is event-driven), not
  10 minutes.
- `bot_signals` is read-only — the import SELECTs only; labels are written to the brain DB.
- After a re-fire, optionally re-fill `coin_fires.manual_klasse` from `coin_moment_labels` for
  backward-compat consumers, matched on `(trading_symbol_id, datetime, rule)`.

## Related skills

[[bot-signals-schema]] (legacy tables + the oracle), [[brain-engine]] (the pipeline + promising),
[[brain-routines]] (daily optimization chain), [[brain-rule-tuning]] (subrule thresholds),
[[brain-indicator-metrics]] (the calc cache).
