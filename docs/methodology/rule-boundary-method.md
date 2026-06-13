# Rule-Boundary Methodology — Faithful Rebuild Spec

> **Purpose.** This document is the implementable specification for rebuilding the legacy
> "rule-boundary" trading methodology. It is written to enable the owner's chosen build order:
>
> - **Step 1 — Faithful rebuild.** Reproduce the legacy calculations and rule-engine so the
>   new system makes the *same* buy/no-buy decisions as the old PHP system.
> - **Step 2 — Boundary-finding improvement.** Improve how `b_min`/`b_max` are derived from
>   good trades (a better boundary-finding method) *without* changing the feature math.
> - **Step 3 — ML (much later, out of scope here).**
>
> Every claim below is backed by a `file:line` citation into `/Users/daanvantongeren/Documents/Sites/bot/legacy/`.
> Where the legacy code was ambiguous or self-contradictory, the spec says so rather than inventing
> behaviour. See **§9 Open Questions** for everything that needs Daan's confirmation.

---

## 1. Overview

### 1.1 In Daan's terms

The bot looks at a moment in time and asks: *"Does this moment look like the moments just before
my known good trades?"* For each indicator (e.g. a volume oscillator, MFI, OBV) it computes a
**feature** over a recent **lookback window** — for example *"the skewness of the last 10 values"*,
*"the standard deviation of the last 5 minutes"*, *"how many reversals in the last 8 ticks"*.

For every known good trade, the bot computes that same feature and remembers its value. Across all
good trades, the feature lands in some range — say *between 1.25 and 1.35*. That range becomes a
**rule boundary** `[b_min, b_max]`. A new moment **passes** that subrule only if its feature value
lands inside the band. A real **rule** is a stack of such subrules ANDed together: a buy fires only
if *every* active subrule passes.

The boundary is found by collecting the feature value of every good trade and taking the tightest
band that still contains all of them (after trimming outliers). Then Daan tests the effect: *"if I
move this boundary, how many bad trades drop out and how many good trades survive?"* The system
generates a **huge amount of data** because it recomputes every feature, for every indicator, over
every lookback length 2..N, for every trade, both good and bad.

### 1.2 Faithful technical version

The legacy system has four layers:

1. **Feature library** — one central function `calc_abs_diff_percentage()`
   (`functions_br.php:7709`) consumes a window of indicator/price rows and returns ~25 derived
   metrics in one pass (skewness, std-dev, volatility, range %, reversals, consecutive runs,
   median, etc.). A harness maps each numbered "Test type" (1..30) to exactly one key of that
   result array (`trades_volume_analysis.php:1105`).

2. **Lookback** — the window is either the **last N rows** (count mode, `period_type='entries'`)
   or the **last X minutes** (time mode, `period_type='minutes'`), controlled by `def1_value`
   (and `def2_value` for an end bound). (`functions_br.php:6810`, `trades_volume_analysis.php:932`.)

3. **Good-trade enumeration & boundary derivation** — good trades live in `wp_trading_simulation`
   with a human-annotated buy window `[datetime_start, datetime_end]`. Feature values are collected
   across good trades and reduced to a `[min, max]` (optionally outlier-trimmed) envelope, or the
   tightest band `[max(lowest), min(highest)]` (`inc_advise.php:100`, `inc_analyse_values.php:482`).

4. **Rule engine** — `rule_engine()` (`functions_br.php:268`) loops a rule's active subrules in
   `sort` order, computes each subrule's feature, checks it against `[b_min, b_max]`, and ANDs the
   results into a single boolean `result` (true = BUY). First failing subrule short-circuits.

The fitness/effect test (`showEffect()`, `showEffect.php:12`) counts how many borderline bad trades
a *loosened* boundary would newly admit. The richer profit-based scoring exists only in dead
`if(1==2)` code and never runs.

---

## 2. The feature / calculation library

### 2.1 Central builder — `calc_abs_diff_percentage($rows, $lookback_period, $price)`

**File:** `functions_br.php:7709-7957`.

- `$rows` is the window of indicator rows, fetched **datetime DESC** so `$rows[0]` is the **most
  recent** value and the window runs **newest → oldest** (`functions.php:1585`).
- `$price=true` reads `$row->price`; otherwise reads `$row->value`.
- It loops `for ($i = 0; $i < $lookback_period && $i < count($rows); $i++)` (`:7791`), pushing each
  value into `$array_allvalues[]`. **All scalar metrics below operate on `$array_allvalues` in this
  newest→oldest order.** (Order matters for sequential metrics — see §9.)
- Per step it also computes previous-value diffs:
  - `diff_percentage_prev = calc_percentage(cur, prev, 2)` (signed %),
  - `diff_number_prev = calc_number(cur, prev, 2) = round(prev - ... )` (see §2.3),
  collected into `array_all_diffs_percentage[]` and `array_all_diffs_number[]`, and it tracks running
  `diff_percentage_prev_max/min`, `diff_number_prev_max/min` (`:7837-7872`).

**Key result-array fields** (`:7895-7951`):

| Key | Definition |
|-----|-----------|
| `first_value` | `$array_allvalues[0]` (most recent) |
| `last_value` | value at index `lookback-1` (or last available) |
| `diff_previous_value` | `calc_percentage(last_value, first_value, 1)` |
| `diff_previous_number` | `first_value - last_value` |
| `max_diff_percentage` / `max_diff_number` | largest abs diff vs `first_value` over the window |
| `diff_percentage_prev_max/min`, `diff_number_prev_max/min` | extremes of step-to-step diffs |
| `sum_average_positive_precentage` | sum of positive prev-% diffs / `i` |
| `lowest_value` / `highest_value` | `min/max(array_allvalues)` |
| `standard_deviation`, `volatility`, `range_percentage`, `consecutive_increases/decreases`, `reversal_count`, `average_reversal_size`, `median_value`, `skewness`, `max_same_value` | see §2.2 |

### 2.2 Exact formulas (statistics helpers)

All operate on `$array_allvalues` (newest→oldest).

| Metric | Function (file:line) | Formula |
|--------|---------------------|---------|
| **Standard deviation** | `standard_deviation` (`functions_br.php:8033`) | **Sample** std. `mean=Σx/n`; `variance = Σ(x-mean)² / (n-1)`; `return sqrt(variance)`. Returns 0 if `n<2`. |
| **Volatility** | inline (`:7917`) | `standard_deviation / first_value` if `first_value>0` and `std>0`, else 0. |
| **Range %** | inline (`:7924`) | `diff_range = abs(highest - lowest)`; if `diff_range>0` and `array_sum>0` → `(diff_range / Σ(array_allvalues)) * 100`, else 0. |
| **Consecutive increases/decreases** | `count_consecutive_changes($arr,$dir)` (`:8052`) | Longest run of strictly `>` (up) or strictly `<` (down) consecutive steps. Returns max streak length. |
| **Reversal count** | `count_reversals` (`:8000`) | Walk values tracking `$trend`; count a reversal each time direction flips (down→up or up→down). First direction does not count; only flips count. Equal consecutive values do not change trend. |
| **Average reversal size** | `calculate_average_reversal_size` (`:7960`) | Same reversal walk; at each counted flip add `abs(value - previous_value)` to `total_size`, `count++`; return `total_size/count` (0 if none). **NOTE:** size = magnitude of the *single step at the flip point*, NOT peak-to-trough. |
| **Median** | `calculate_median` (`:8088`) | Sort asc; even `n` → `(arr[n/2-1]+arr[n/2])/2`; odd → `arr[floor(n/2)]`. 0 if empty. |
| **Skewness** | `calculate_skewness` (`:8107`) | **Population** skew. `mean=Σx/n`; `variance = Σ(x-mean)² / n` (**/n**, not n-1); `std=sqrt(variance)`; if `std==0` return 0; `skewness = Σ( ((x-mean)/std)³ ) / n`. Returns 0 if `n<2`. |
| **Max same value (Occurance)** | `highest_occurrences_within_margin($arr, 0.01)` (`functions.php:4882`) | `margin/=100`; for each value count how many other values satisfy `abs(value-other) <= margin*value`; return the max count. Margin hardcoded `0.01` (1%) at `functions_br.php:7947`. |
| **Count positive / negative** | `count_positive_negative($arr)` (`functions.php:4783`) | `positive = count(value>0)`, `negative = count(value<0)`. Harness feeds it `array_all_diffs_percentage` or `array_all_diffs_number` (the **step diffs**), NOT raw values (see §9). |
| **Sideways band** | `checkSideWays($json,$pct,$remove_highlow,$num_or_pct)` (`functions_br.php:8154`) | Decode JSON; `first_value=values[0]`; `array_shift` drops the first (most recent) value; if `remove_highlow` strip all `==max` and `==min`; `upper=max(filtered)`, `lower=min(filtered)`; number mode → `round(first-upper)`, `round(first-lower)`; percentage mode → `calc_percentage(first,upper)` / `calc_percentage(lower,first)`. Harness calls it with `pct=2`, `remove_highlow=true` hardcoded (`trades_volume_analysis.php:1323`). |

### 2.3 Diff primitives

- `calc_percentage($from, $to, $round)` (`functions.php:453`): `diff=abs(from-to)`;
  `perc = diff/abs(from)*100`; sign negative if `from>to`; returns 0 if `from==0` or `diff==0`.
- `calc_number($from, $to)` (`functions.php:485`): `round(to - from)`.
- `calculate_absolute_difference($a,$b)` = `abs(a-b)`.

### 2.4 Test-type → result-key mapping (the 30 "Test types")

Harness switch at `trades_volume_analysis.php:1105-2507`; cache keymap at `:675-702`; UI radios at
`:432-495`. `test_number_or_percentage` selects between the paired `_number`/`_percentage` keys.

| # | Label | Extracts | Case line |
|---|-------|----------|-----------|
| 1 | Max absolute volatility vs first | `max_diff_percentage` / `max_diff_number` | 1124 |
| 2 | Occurance | `max_same_value` (number only) | 1165 |
| 3 | Previous entry | `diff_previous_value` / `diff_previous_number`; `volumeud` special-cased | 1230 |
| 4 | Sideways | `checkSideWays` upper/lower (picks smaller-magnitude bound) | 1301 |
| 5 | Highest prev volatility negative | `diff_percentage_prev_min` / `diff_number_prev_min` | 1374 |
| 6 | Highest prev volatility positive | `diff_percentage_prev_max` / `diff_number_prev_max` | 1405 |
| 7 | Current value | `rows[0]->value` (round 1); `volumeud` threshold logic | 1426 |
| 8 | Sum value | **TODO — keyname `sum_value`, no live case** | UI 453 / key 697 |
| 9 | Lowest value period | `lowest_value` | 1559 |
| 10 | Highest value period | `highest_value` | 1605 |
| 12 | Fast Increase | custom; consecutive 1st..6th % diffs + thresholds; sentinels `0.001/99/101` | 1884 |
| 13 | Missing data | max time gap (s) between rows where price_increase>0.3 | 1997 |
| 14 | Future price | forward walk to first higher price, −0.7% stop rules | 2077 |
| 15 | Increase all indicators | sum of `max_diff_number` of vzo + obv-x-value + mfi (signed) | 1829 |
| 16 | Trend up and down | count of value changes over rows (needs `trendabovehigh`) | 2160 |
| 17 | Count positive | `count_positive_negative(array_all_diffs_*)['positive']` | 2229 |
| 18 | Count negative | `...['negative']` | 2274 |
| 19 | Diff lowest value period | `calc_percentage(lowest,first)` / `(first-lowest)`; future variant | 1645 |
| 20 | Diff highest value period | `calc_percentage(last/first,highest)` / `(highest-first)`; future variant | 1727 |
| 21 | Profit change vs current | conditional on `profit_loss_highest>def2` | 1800 |
| 22 | Standard deviation | `standard_deviation` | 2310 |
| 23 | Volatility | `volatility` (round 4) | 2331 |
| 24 | Range percentage | `range_percentage` (round 5) | 2354 |
| 25 | Consecutive increases | `consecutive_increases` | 2375 |
| 26 | Consecutive decreases | `consecutive_decreases` | 2396 |
| 27 | Reversal count | `reversal_count` | 2417 |
| 28 | Average reversal size | `average_reversal_size` | 2438 |
| 29 | Median value | `median_value` | 2463 |
| 30 | Skewness | `skewness` (round 5) | 2504 |

> Test types **8, 13, 14, 15** are not in Daan's stated list — confirm scope (§9). Cases 12/15
> contain hand-tuned magic numbers — confirm verbatim port vs re-derive (§9).

### 2.5 Boundary test — `checkIndicatorBoundaries($value, $high, $low, ...)`

**File:** `functions_br.php:18-65`. `result_ok=1` only if
`(high=="" || value<=high) && (low=="" || value>=low)`. **Inclusive both ends** — a value exactly
on a boundary passes (helper uses `>`/`<` for failure). Empty/null boundary = unbounded that side.
This is the per-trade pass/fail that drives drop-out counting.

---

## 3. The lookback structure

### 3.1 Two modes, one flag

`period_type` selects the window semantics (`trades_volume_analysis.php:388`, default `'entries'`):

- **COUNT mode (`'entries'`)** — window = **last N indicator rows**, `N = def1_value`.
  - Direct SQL: `get_indicator_bydate_unix(..., 'desc', $period_test, ...)` with
    `LIMIT $period_test` (`functions.php:1582-1656`).
  - In-memory slice: `base_query_filter(..., $limit=round(def1), false)` →
    `array_slice($arr, 0, $limit)` on a DESC-sorted array (`functions.php:1659-1728`).
- **TIME mode (`'minutes'`)** — window = `[now − def1 minutes, now]`.
  `date_from = get_time_minutes_ago_date($period_test, $datetime)` (`functions.php:582`), then fetch
  with a large safety `LIMIT` (1000/500/100). `def2_value` (`lookback_until`) optionally sets the
  window **end** (`timeseries_value_data`, `functions_br.php:7111`). Negative `def2` flips the window
  into the **future** (`:7141`) — a look-ahead, flagged for review (§9).

The per-trade dispatcher `previous_value_data` (`functions_br.php:6810`) shows the branch explicitly:
`entries` → `base_query_filter(limit=$lookback)`; `minutes` → `get_time_minutes_ago_date($lookback)`
then filter. It then reads `$result[count-1]` = the **oldest** row (the value `def1` steps back).
Comment at `inc_save_values.php:160`: *"def1_value is the number of rows back"*.

### 3.2 The "huge data" combinatorics

The analysis harness (`trades_volume_analysis.php:581-845`) is **6 nested loops**:

```
L1  foreach test                 # {test_type, indicators[], period range}  (:581)
L2    foreach indicator          # (:642)
L3      foreach direction        # not-above / not-below                    (:743)
L4        for i = min..max       # SWEEPS the lookback length (def1) itself  (:778)
L5          foreach profit_sign  # 1=good trades, 2=bad trades              (:783)
L6            foreach trade       # every simulated trade                    (:845)
                 → fetch window (entries/minutes per L4) → calc_abs_diff_percentage → ~25 metrics
```

So total work ≈ `tests × indicators × 2 directions × (N−1) lookbacks × 2 profit-signs × trades ×
~25 metrics/window`. **L4 is the key multiplier**: the lookback length is itself a swept search
axis (`min_loop_period` default 2 → `loop_period_test`/`max_loop_period`, e.g. 10/15/20).

### 3.3 Precompute cache

`save_cache_values()` (`functions_br.php:9013`) fetches the **21 newest rows once** per
(trade, indicator), then loops `lookback = 1..20` calling `calc_abs_diff_percentage(rows, lookback,
false)` and JSON-stores **all 20 full result arrays** per indicator per trade. This materialised
cache *is* the "huge amount of data". The 21-row / 1..20 / 300-min depth is hardcoded — confirm new
limits (§9).

---

## 4. The good-trade loop & window

### 4.1 Source table & quality codes

Good trades = rows in `wp_trading_simulation`, fetched by
`get_simulation_trade($symbol, $rule, $from, $to, $action, $result, ...)` (`functions.php:868-924`).
The `$from/$to` args filter on the single `datetime` (buy point), **not** the per-trade window.
Quality `result` code (set from profit in `analysetrade.php:268-277`):

- `1` = **good** (`profit_loss > 2`)
- `2` = **ok/marginal** (`0 < profit_loss < 2`)
- `3` = **bad** (`profit_loss < 0`)
- `NULL` = unclassified (param `'0'`)

Throughout: `result==1` is the **good** class to retain; `result==3` is the **bad** class to
eliminate.

### 4.2 The buy window — `datetime_start` / `datetime_end` / `datetime_best`

These are **manually entered** columns on each trade ("Beste aankoopperiode van-tot",
`analysetrade.php:297`), persisted by `add_simulate_trade.php`. Defaults (`analysetrade.php:300`):
blank `datetime_start` → `datetime`; blank `datetime_end` → `datetime` (a **zero-width** window).
`datetime_best` = best buy point inside the window (the POST field `sell_datetime_best` maps to a
*different*, sell-side column `best_selling_datetime` — naming is inconsistent, §9).

### 4.3 Candidate-datetime enumeration (window-flagging loop)

Canonical in `inc_save_values.php:52-152` (and `indicator_gpt.php:35-98`):

1. Skip trades with incomplete/zero-width window (`start==end` guard).
2. `good_start = strtotime(datetime_start)`, `good_end = strtotime(datetime_end)` (`:53-54`).
3. **Padded fetch window**: good trades use `−30 min` before start, `+10 min` after end
   (`get_time_minutes_ago_date(30, start)` / `(-10, end)`, `:60-61`); bad trades use `±5 min`
   around the single `datetime` (`:57-58`).
4. **Candidate = each stored indicator tick** in the padded window
   (`get_distinct_indicator_bydate_date(...)` / `get_indicator_in_period(...)`,
   `functions.php:1454`, `LIMIT 10000`). Candidates are per **stored tick**, NOT a fixed per-minute
   grid — cadence depends on the ingestion job (§9).
5. Per tick: `is_within_period = (tick_time >= good_start && tick_time <= good_end) ? 1 : 0`
   (`:149`); forced `0` for bad trades (`:151`). Ticks in the pad are kept but flagged 0.

`is_within_period` + the boundary check together set the per-tick `result_ok` (1 only if inside the
window **and** the value satisfies `[boundary_low, boundary_high]`).

### 4.4 The alternate lookback loop (loop style B)

`trades_volume_analysis.php:845-1003` iterates trades by the **single buy `datetime`** and **ignores
the window entirely**, computing lookbacks backward from the buy point. The two loop styles disagree
about what "the window" is — confirm the canonical one for the rebuild (§9).

---

## 5. Boundary derivation

There are **three** derivation paths in the legacy. They are distinct and must not be conflated.

### 5.1 Path A — min/max envelope of good-trade values (advice only)

**File:** `inc_advise.php:100-147`.

1. Build a per-subrule map `$array_subrules[ID]` for every active subrule (copies ID, indicator,
   subrulename, b_min, b_max, def1_value, plus empty `values=>[]`). **Bug:** `def2_value` is set to
   `def1_value` in the equivalent array build (`inc_save_promissing_trades.php:404`).
2. Fetch good-but-not-bought trades (`ok_trade=1` and `result=0`) via
   `get_simulation_trades_result(...)`.
3. For each good-trade datetime, fetch all rule indicator values; push `round($row->value, 1)` into
   `values[]` (`:107-116`). One entry per good trade.
4. Reduce (`:132-147`):
   - `min_value = min(values)`, `max_value = max(values)` — raw envelope.
   - `value_extremes = removeExtremes(values, 1)`.
   - `min_value_second = min(value_extremes)`, `max_value_second = max(value_extremes)` — trimmed.
5. **Surfaced as advice text only** ("Advise between {min} and {max}" OR trimmed pair,
   `:248-258`). **Not auto-written** in this path.

### 5.2 Path B — tightest band containing every good trade

**File:** `inc_analyse_values.php:482-577` (`get_highest_lowest_goodtrades_datetime`). This is Daan's
**"1.25 and 1.35"** band.

- Reads the already-stored, lookback-computed `value` for good ticks where `is_within_period=1 AND
  trade_result=1` (`get_values_good_trade_datetime`, `:438-478`).
- Per good trade (per `simulation_ID`): find its **min** and **max** value across its window.
- Across all good trades: `lowest_highest_value = min(highest_values)` and
  `highest_lowest_value = max(lowest_values)` (`:571-574`) → the **tightest band still containing
  every good trade**. (Naming is reversed-sounding but: the upper edge is the *lowest of the
  per-trade highs*, the lower edge is the *highest of the per-trade lows*.)

> This is the most likely target for the **Step-2 improvement** — it is the principled "boundary that
> just barely contains all good trades" path. The min/max envelope (Path A) is its looser cousin.

### 5.3 Path C — incremental widen to each out-of-band good trade (the live auto-write)

**File:** `inc_save_promissing_trades.php:38-323` (live; lines `340-468` are a **DEAD** `if(1==2)`
copy of Path A — do **not** port).

- Per promising-trade datetime, per subrule value (`:112-114`):
  - if `b_min` set and `value < b_min` → call `showEffect($rule, $value, "", ...)` and stash
    `array_save_rule_ID[$dt][$rule]['min'] = $value` (`:148-151`),
  - if `b_max` set and `value > b_max` → `showEffect($rule, "", $value, ...)` and stash `['max']`
    (`:152-154`).
- **The proposed new edge is literally the good trade's own out-of-band value** — the boundary is
  widened *just enough* to admit this good trade.
- Commit (`:303-319`): `update_trading_rule_field($rule,'b_min'|'b_max',$value)` and reset
  `processed=0`. Writes via plain interpolated SQL (`functions_br.php:184-200`, unparameterized).

### 5.4 Outlier trimming — `removeExtremes($array, $howmany)`

**File:** `functions.php:4728-4742`. `sort` asc → `array_slice($a, $howmany)` (drop `$howmany`
lowest) → `array_slice($a, 0, count-$howmany)` (drop `$howmany` highest). **Count-based**, always
called with `howmany=1` in the boundary engine → drops exactly one min and one max. (NOT to be
confused with the percentage-based `getFilteredMinMaxofArray` at `functions.php:4751`, which is a
*runtime* operator for `filter_highest_lowest`, not derivation.)

### 5.5 Widening / tuning invariant

In Path A's advice and Path C's auto-write, the persisted band only ever **widens outward** to admit
good trades: `showEffect` enforces a new `b_min` must be **lower** than current and a new `b_max`
**higher** (`showEffect.php:85-95`). Tightening is what the *fitness* test (§6) measures against bad
trades. **The min/max-envelope vs incremental-widen contradiction is the single biggest open
question — see §9.**

---

## 6. Boundary-effect testing (fitness)

### 6.1 What the LIVE path actually does — `showEffect()`

**File:** `showEffect.php:12-198`. Entry `showEffect_ajax.php:10-90` then `die` (everything below
`//-----old code-----` at `:91`, guarded by `if(1==2)`, is dead).

1. Accept only a **loosening**: `value=b_min` only if `b_min < current` (`ajax:34`); `value=b_max`
   only if `b_max > current` (`:37`). Exactly one side may change (`showEffect.php:47`).
2. Candidate bad-trade pool (`:110-117`):
   `SELECT * FROM wp_trading_simulation_trades_result WHERE rule_number=? AND amount_bad='1' AND
   trading_symbol_id IN (...) ORDER BY datetime asc`. **`amount_bad='1'`** = trades that failed by
   **exactly one** indicator (near-misses; defined in `functions_br.php:6582-6594`). Trades failing
   by 2+ indicators are never tested.
3. Per bad trade, fetch its indicator value at that datetime (`get_simulation_trades_indicator`,
   `:135`).
4. **Band-membership test (`:146`)** — the core:
   ```php
   if ( ($b_min_updated && $value >= $b_min && $value < $b_min_current)
     || ($b_max_updated && $value <= $b_max && $value > $b_max_current) )
   ```
   i.e. the bad trade's value falls in the **newly-opened slice** between old and new edge
   (inclusive on the new edge, exclusive on the old).
5. **Overlap guard (`:148-155`)**: skip if another simulation trade was already active at that
   datetime (`check_simulation_trade_period(..., 'buy', ...)`).
6. Count: `counter_found++`; append `{date, trading_symbol_id, trade_result=result_sell}` to
   `array_found` (`:157-163`). If `result_sell` empty, lazily recompute the sell
   (`insert_simulation_trades_result`, `:166-189`) — but this only fills the *displayed* profit; it
   is **not** aggregated into any score.

**Output = the count and list of bad trades newly admitted** (`count(array_found)`). The UI renders
each found trade's `profit_loss/highest/lowest/selling_date` (`ajax:70-84`) for the human to judge.
There is **NO** automatic good-retained count, **NO** net-profit number, **NO** pass/fail in the live
path.

> **Direction nuance (§9).** Live `showEffect` answers *"how many extra bad trades would I accept by
> **relaxing** this boundary?"* — the inverse of Daan's "how many bad trades **drop out**". The
> tightening direction (bad trades removed) and the good-trade-retention count are handled elsewhere
> (`trades_data_analysis.php:331-388`: `result==3 && amount_ok_total==0` → "bad trade gone";
> `result==1 && amount_ok_total==0` → "good trade lost").

### 6.2 The dead 8-rule profit scoring

`showEffect.php:243-691` (and a second `if(1==2)` block at `:697`) contain a richer fitness engine
that **never runs**: a boundary sweep accumulating `total_added_profit`, counts of
positive/negative/turned trades, and **8 Business Rules** (`:576-599`):

| Rule | Condition |
|------|-----------|
| 1 | `count_positive >= count_negative` |
| 2 | `counter_affected > 0` |
| 3 | `total_added_profit > 10` |
| 4 | `count_negative <= 2` |
| 5 | `amount_new_positive > -amount_new_negative` |
| 6 | `turned_to_positive >= 0` |
| 7 | `turned_to_negative < 2` |
| 8 | `count_positive/count_higher_profit <= 2.7` with `count_higher_profit >= 2` |

`all_rules_passed` ANDs them; results sort by `total_added_profit` desc. This looks like the
*intended* fitness — confirm whether Step 2 should resurrect it (§9).

---

## 7. The rule-engine evaluation (for the Step-1 rebuild)

### 7.1 Flow — `rule_engine($rule, $check_date, $symbol, $buy_price_date, $showlog, $save_indicator, $only_subrule_number, $test_all)`

**File:** `functions_br.php:268-4467`.

1. `$result_br = true` (`:316`) — the **single AND-accumulator** for the whole rule.
2. Subrules via `get_trading_rules($rule)` →
   `SELECT * FROM wp_trading_rules WHERE active=1 AND rule_number=? ORDER BY sort ASC` (`:202-224`).
   Only `active=1` rows, evaluated strictly ascending `sort`.
3. Base data pre-fetched once over a 300-min window (`get_time_minutes_ago_date(300, check_date)` →
   `check_date`, `:326-330`); subrules read from these arrays via `base_query_filter` instead of
   re-querying.
4. Per subrule: copy columns to locals — `boundary_low=b_min`, `boundary_high=b_max`,
   `boundary_low_alt=b_min_alt`, `boundary_high_alt=b_max_alt`, `def1_value`, `def2_value`,
   `condition_rule`, `operator`, `value_condition` — then `switch($subrulename)` (`:406-460`).
5. **Combiner (`:4382-4388`)**: after the switch,
   `if ($result_br==false && $test_all==false) break;` → first failing subrule **short-circuits the
   whole rule** (production AND). `default` case is a **no-op** (unknown subrulename does not change
   the verdict, `:4373`).
6. **Return (`:4409-4467`)**: array with `result => $result_br` (true = BUY) plus diagnostic value
   fields, and sell-side `orderstatus`/`stoploss`. Caller treats `result===true` as BUY.

### 7.2 Boundary check conventions (two coexisting styles)

- **Helper** `checkIndicatorBoundaries` (§2.5): supports **one-sided** bounds (empty side skipped),
  inclusive.
- **Inline** (most cases, e.g. `lowest:3629`, `highest:3817`, `diff:4062`): gated on
  `if ($boundary_low && $boundary_high)` — **both must be truthy** or the check is skipped entirely
  (so a bound of `0` disables the check). `currentvalue` (`:2167-2181`) is the exception (does a
  low-only check when `boundary_high` empty).

> The rebuild must standardize one convention and a single "no bound" sentinel (§9).

### 7.3 `def1`/`def2` overloading and cross-subrule references

`def1_value`/`def2_value` are **dual-purpose** per `subrulename`:

- **Lookback** — minutes or row-count for value-producing cases (`lowest`, `highest`, `skewness`,…).
- **Subrule-ID reference** — comparison cases use them as **keys** into `$result_rule_number[]`
  (the in-memory map `subrule_ID → computed value`). E.g. `diff` (`:4051`):
  `from = $result_rule_number[$def1]`, `to = $result_rule_number[$def2]`. Same pattern in
  `diff_number`, `higherthan`, `substract_subrules`; `lowestfromdate`/`higherthandate` key into
  `$result_rule_number_datetime[]`. **Referenced subrules must have a lower `sort`** so their value
  already exists (nothing validates this — §9).
- **Direction selector** in `action` (`:3313`): `def1==1` fails if current action is `down`,
  `def1==2` fails if `up`; `def2` checks the previous action.

### 7.4 Alt-boundary override

`currentvalue` (`:2114-2134`): `condition_rule` holds another subrule's ID;
`$result_rule_number[$condition_rule]` is that subrule's value. If `operator=='lowerthan'` and
`value_condition!=''` and that value `< value_condition` → swap `boundary_low/high` to
`boundary_low_alt/high_alt`. Symmetric for `'higherthan'` with `>`. So alt-bounds replace primary
bounds when a referenced subrule crosses a threshold.

### 7.5 Operator sub-branches

`operator` is a string discriminator inside a case: `'SL'` = stop-loss/sell branch (sets
`orderstatus`/`stoploss`, not `result_br`); `'time_ago'`; `'filter_highest_lowest'` (percentile
bounds via `getFilteredMinMaxofArray`); `'highest_value'`; `'lowerthan'`/`'higherthan'`; etc.
JSON-driven cases (`skewness:984`, `previous_value:1603`, `sideways:553`, `range_percentage:1074`,
`volatility:1319`) `json_decode($value_condition)` for multi-key specs; **malformed JSON triggers
`echo … die`** (hard request stop) rather than a graceful failure (§9).

### 7.6 Representative cases documented in full

`currentvalue`, `action`, `lowest`, `highest`, `diff`, `diff_number`, `higherthan`,
`substract_subrules`, `compare_prev_value`, `previous_value`. **~40 other cases exist** (skewness,
volatility, range_percentage, correlation, timeseries, technical_analysis, sell_* …). A faithful
Step-1 rebuild that needs every case must extract each remaining case's exact formula individually
(§9).

---

## 8. Rebuild notes (Step 1 then Step 2)

Target stack: **Python feature-store** (computes features over windows; replaces
`calc_abs_diff_percentage` + helpers) + **Laravel** (rules, boundaries, evaluation, persistence;
replaces the PHP harness + `rule_engine`).

### 8.1 Mapping

| Legacy piece | New home | Reproduce exactly first (Step 1) |
|--------------|----------|----------------------------------|
| `calc_abs_diff_percentage` + stats helpers (`functions_br.php:7709`, `8033`, `8088`, `8107`, …) | **Python feature-store**, one function per metric | YES — bit-for-bit: sample-std (n−1), population-skew (/n), newest→oldest order, inclusive boundaries, `removeExtremes(howmany=1)`. |
| Test-type → key map (`trades_volume_analysis.php:1105`, `675`) | Python feature registry (test_type 1..30 → feature key) | YES — same keys, same `_number`/`_percentage` selection. |
| Lookback windows (`period_type`, `def1`/`def2`; `functions_br.php:6810`, `functions.php:1582`) | Feature-store window builder (count vs time) | YES — same DESC ordering, same count-slice / minutes-window, same `array_shift` off-by-ones per case. |
| 6-loop precompute (`trades_volume_analysis.php:581`, `save_cache_values:9013`) | Python batch job → feature table | Reproduce outputs; the *loop structure* itself is just throughput, not semantics. |
| Good-trade window flagging (`inc_save_values.php:52`, `inc_advise.php:100`) | Laravel: trade model + `is_within_period` flag | YES — same window semantics, same pads (30/−10, ±5), same zero-width skip. |
| Boundary derivation (Paths A/B/C, §5) | Laravel boundary service | Step 1: reproduce whichever path Daan confirms is production. Step 2: improve (likely on Path B, §5.2). |
| `showEffect` fitness (`showEffect.php:12`) | Laravel fitness service | Step 1: reproduce live near-miss count. Step 2: consider resurrecting 8-rule profit scoring (§6.2). |
| `rule_engine` (`functions_br.php:268`) | Laravel rule evaluator | YES — `sort`-order AND, short-circuit, `def1`/`def2` overload, alt-bounds, cross-subrule refs. |
| `update_trading_rule_field` (`functions_br.php:184`) | Laravel Eloquent (parameterized) | Behaviour same; **fix the unparameterized SQL** in the rebuild. |

### 8.2 Step-1 acceptance (faithful reproduction)

The new system reproduces legacy behaviour when, for a frozen dataset:

- Each test-type feature value matches the legacy value for the same (trade, indicator, lookback).
- `is_within_period`/`result_ok` flags match per tick.
- `rule_engine` returns the same buy/no-buy verdict per `check_date`.
- `showEffect` returns the same `array_found` (count + dates) for a given loosened boundary.

Lock these as golden-file tests **before** any Step-2 change.

### 8.3 Step-2 boundary improvement

Only after §8.2 passes: replace the boundary-*derivation* (not the features) with a better method.
Path B (§5.2) is the natural baseline ("tightest band containing all good trades"); Step 2 can add
principled trimming, per-side outlier control, or a fitness-driven search that maximizes
bad-eliminated while holding good-retained — i.e. promote the dead 8-rule scoring (§6.2) into a real
objective. **The feature math and the rule-engine evaluation must stay frozen** so Step 2 is a clean
A/B against Step 1.

---

## 9. Open questions for Daan

**Boundary derivation**

1. **Which derivation is production?** Three paths exist: (A) min/max envelope of all good trades —
   *advice only, not written* (`inc_advise.php`); (B) tightest band `[max(lowest), min(highest)]` —
   the "1.25/1.35" band (`inc_analyse_values.php:482`); (C) incremental widen to each out-of-band
   good trade's own value — *the only automatic write path* (`inc_save_promissing_trades.php`). Which
   is the intended Step-1 behaviour, and which is the Step-2 baseline to improve?
2. **Outlier trimming** is always `removeExtremes(howmany=1)` (one min + one max). Keep
   count-based-1-per-side, or move to percentage/configurable trimming in Step 2?

**Feature math**

3. **Sequence order.** All sequential metrics (reversal_count, consecutive runs,
   average_reversal_size, count_positive/negative, skewness sign) run **newest→oldest**. Keep, or
   flip to chronological? (Changes signs/directions.)
4. **Std vs skewness denominator.** `standard_deviation` uses **sample (n−1)**; `calculate_skewness`
   uses **population (n)** internally. Intended, or standardize?
5. **`average_reversal_size`** measures the single step at the flip point, NOT peak-to-trough.
   Confirm intended.
6. **Count positive/negative (17/18)** operate on the **step-diff** arrays
   (`array_all_diffs_*`), not raw values. Confirm.
7. **Fast Increase (12) / increase_all_indicators (15)** contain hand-tuned magic thresholds and
   sentinels (`0.001/99/101`, `2.1/3.5/0.7%`, `count>=3 vs >=5`). Port verbatim or re-derive?
8. **Test type 8 (Sum value)** is TODO with keyname `sum_value` and no live case. Define it
   (e.g. `array_sum(array_allvalues)`) or drop?
9. **Test types 13 (missing data), 14 (future price), 15 (increase all indicators)** are not in your
   stated list. In scope for the rebuild?
10. **`checkSideWays`** is called with hardcoded `percentage=2`, `remove_highlow=true`. Make
    configurable?

**Lookback**

11. **Two skewness implementations** with different window semantics: count-only via
    `calc_abs_diff_percentage` (ignores def2) vs time-based `timeseries_value_data` (uses def1+def2).
    Which maps to the real skewness rule (test_type 30 in the config table)?
12. **300-min count-mode backstop.** In `entries` mode a fixed 300-min `date_from` caps the fetch;
    if fewer than `def1` rows exist in 300 min the window is silently shorter. Keep the ceiling or
    treat count purely?
13. **`previous_value_data` minutes branch** has a `die;` at `functions_br.php:6886` — the
    time-based "previous value" looks abandoned. Was it ever used, or is `timeseries_value_data` the
    only real time path?
14. **`array_shift` off-by-one.** `timeseries_value_data` drops the most recent value only when
    `lookback_until` is empty. Should the current candle be included or excluded per calc type?
15. **Negative `def2` = future window** (look-ahead). Intentional (e.g. outcome labeling) or must it
    be excluded from rule conditions to avoid leakage?
16. **Precompute depth.** `save_cache_values` hardcodes 21 rows / lookback 1..20 / 300-min window.
    What max lookback / cache depth does the new system need?

**Good-trade loop**

17. **Two loop styles disagree** on what "the window" is: window-flagging (`inc_save_values.php`,
    uses `datetime_start..end`) vs lookback-from-buy-point (`trades_volume_analysis.php`, ignores the
    window). Which is canonical, or are both needed?
18. **Indicator sampling cadence.** Candidates = stored ticks, not a fixed grid. What is the actual
    `wp_trading_indicator` sampling interval (seconds? per-minute?) so candidate density is
    reproduced?
19. **Window is manual.** `datetime_start/end/best` are hand-entered; no auto-derivation exists.
    Keep manual annotation, or auto-compute the window from the price path in the rebuild?
20. **Column naming.** Buy-side best = `datetime_best`, but the form field `sell_datetime_best` maps
    to the *sell-side* `best_selling_datetime`. Which column should the new schema's "datetime_best"
    map to?
21. **Fetch pads** (good: −30/+10 min; bad: ±5 min) are hardcoded. Intentional parameters or
    incidental? Configurable?
22. **Zero-width window skip.** `start==end` trades are silently excluded. Many trades may default to
    zero-width and be dropped. Intended?

**Fitness**

23. **Good-trade retention** is NOT measured in live `showEffect` (only bad-trades-newly-admitted).
    Was retention meant to live here, or only in `trades_data_analysis.php`?
24. **Direction.** Live `showEffect` tests *loosening* (bad trades **added**). Your description is
    *tightening* (bad trades **drop out**). Which direction should the new fitness use?
25. **Near-miss-only scope.** Fitness only considers `amount_bad=1` trades (failed by exactly one
    indicator). Keep this scope?
26. **Resurrect the 8-rule profit scoring?** The richer `total_added_profit` + 8 Business Rules
    engine is entirely dead. Is this the intended Step-2 fitness, or keep the minimal count?
27. **Auto-aggregate profit** of admitted trades into a single fitness number (currently only shown
    to the human)?

**Rule engine**

28. **Inclusive boundaries** (`value==boundary` PASSES). Keep inclusive?
29. **Two gating conventions** coexist: inline `if (b_min && b_max)` (both must be truthy; `0`
    disables) vs `checkIndicatorBoundaries` (one-sided OK). Standardize which, and what sentinel
    represents "no bound" (empty string vs null vs 0)?
30. **`def1`/`def2` overload** (minutes / row-count / subrule-ID / up-down). Needs an explicit
    per-`subrulename` schema documenting each role.
31. **Cross-subrule ordering** relies on referenced subrules having a lower `sort`; nothing
    validates it (mis-sort silently reads null). Enforce dependency ordering in the rebuild?
32. **`test_all`** suppresses short-circuit (all subrules run, verdict unchanged). Confirm production
    is always `test_all=0` except during calibration/saving.
33. **Malformed JSON** in `value_condition` triggers `echo … die` (hard request stop). Fail the
    subrule/rule cleanly instead?
34. **SL/sell branches** (`operator=='SL'`, `orderstatus`/`stoploss`) are mixed into the same engine.
    For Step-1 BUY calibration, in scope or excluded?
35. **Hardcoded special-cases**: `action` flips down→up when price+value both rose for all indicators
    except `phobos` (`:3350`); some cases hardcode `mfi` and date cutoffs (e.g. before 2023-02-17).
    Carry over or drop?
36. **~40 remaining subrule cases** not yet fully extracted. If Step 1 needs every case's exact
    formula, each must be documented individually — confirm the full required set.
