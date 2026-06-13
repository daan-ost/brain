# Selling-Process Methodology — Faithful Rebuild + Validation Spec

> **Purpose.** This document is the implementable specification for rebuilding the legacy
> **SELL** side of the trading bot, the counterpart to `rule-boundary-method.md` (which
> covers the BUY side, already rebuilt and validated). The build order is the same:
>
> - **Step 1 — Faithful rebuild.** Given a buy `(datetime, price)`, reproduce the exact
>   `selling_price`, `selling_date`, `profit_loss`, `highest_profit_loss`, and
>   `lowest_profit_loss` that legacy wrote to `wp_trading_simulation`, by trailing the
>   price forward minute-by-minute and applying the time-based stop-loss (`SL_settings`)
>   plus sell rule 101.
> - **Step 2 — Improvement** (out of scope here): tune the exit logic once it is faithfully
>   reproduced.
>
> Every claim is backed by a `file:line` citation into
> `/Users/daanvantongeren/Documents/Sites/bot/legacy/`. Where the legacy code is ambiguous,
> self-contradictory, or contains a bug that shapes the stored data, the spec says so and
> tells you to **replicate the bug**, not fix it. See **§6 Open Questions** for everything
> that needs Daan's confirmation.
>
> **The single most important rule of this rebuild:** the goal is *byte-for-byte agreement
> with `wp_trading_simulation`*, not a "correct" trailing stop. Several legacy behaviours
> are bugs (a dead overrule path, a cast-on-LHS no-op that freezes the excursion columns).
> A faithful replica reproduces them.

---

## 1. Overview — how a position is closed

A buy produces a row in `wp_trading_simulation` with `price` (buy price) and `datetime`
(buy datetime). The sell side fills in the rest by walking the price series forward from
the buy and closing the position the **first** minute any exit condition fires.

The driver is **`process_sell_simulation_trade()`** (`functions_br.php:4944`). It:

1. Loads `SL_settings` JSON for the rule from `wp_trading_allrules` (`get_allrules`, lines 5019-5025).
2. Enumerates every **distinct `volumeud` indicator datetime** between the buy and buy+1500 min
   (`check_amounts_minutes=1500`), ascending (`get_distinct_indicator_bydate_date`, line 5029).
   This is the "minute-by-minute" trail — but note the spacing is the *real* indicator
   cadence, not a synthetic 60-second clock.
3. For each timestamp it: reads `marketprice` (the `volumeud` row's `price`), computes the
   instantaneous `profit`, (attempts to) track running excursions, checks whether the
   **previous minute's** trailed stop was breached, then calls
   **`determine_stoploss_price()`** (`functions_br.php:4471`) to recompute the trailing stop
   and emit an `orderstatus`.
4. The **first** minute that produces a sell closes the trade and calls
   `update_simulation_trade()` (`functions.php:1014`).

There are exactly **three families of exit trigger**, evaluated each minute:

| # | Trigger | Where | Fires when |
|---|---------|-------|------------|
| A | **Prior-stop breach** (the trail) | driver, line 5089-5094 AND `determine_stoploss_price` CHECK 3 (4590-4601) | `marketprice < stoploss_price_prev` (the stop carried from last minute) |
| B | **Time-based stop-loss** | `determine_stoploss_price` CHECK 1 + CHECK 2 + `lock_profit` floor | absolute floor breached, or age/profit ladder tripped, or the (re)computed stop ≥ marketprice |
| C | **Sell signal — rule 101** | `rule_engine(101,...)` via `determine_stoploss_price` (line 4642) | a rule-101 subrule sets `orderstatus='sell'` / `'overrule'`, or its returned stop multiplier × marketprice ends up above marketprice |

In practice (validated on DOGEAI, §4.6) the dominant exits are: the **0.988 floor** sell
(buy_price × min_sl1, profit ≈ −1.2%), the **0.99 floor** sell after ~15 min, and **profitable
trailing-lock** sells (ratio > 1). Crucially, **you cannot tell from the stored row alone
which trigger fired** — validation must *replay*, not infer.

---

## 2. The time-based stop-loss (`SL_settings` + `lock_profit`)

This is trigger family **B**. The stop floor does **not** decay linearly with age. It is a
**step function** that tightens through early-age gates, then (in theory) ratchets up with
locked profit. It is computed every minute in `determine_stoploss_price()`, which delegates
the trailing floor to `lock_profit()`.

### 2.1 Age and profit primitives

```
minutes_in_trade = round( diff_seconds_twodates(buy_price_date, check_date) / 60 , 1 )
profit           = round( (marketprice - buy_price) / buy_price * 100 , 2 )       # percent
```

`diff_seconds_twodates(from,to) = to.timestamp - from.timestamp` (`functions.php:623-636`),
so `minutes_in_trade` is positive and grows. (Cited in buy/sell analysis.)

### 2.2 `SL_settings` parse (`determine_stoploss_price:4509-4522`)

Keys read (with defaults if absent):

| Key | Default | Used by | Meaning |
|-----|---------|---------|---------|
| `min_sl1` | `0.985` | both | loose floor multiplier **and** the absolute hard floor `minimum_price = round(min_sl1 × buy_price, rounding)` |
| `minutes_in_trade1` | `2` | `lock_profit` | age (min) below which `min_sl1` floor applies (while profit < `minimal_profit`) |
| `min_sl2` | `0.99` | `lock_profit` | tighter floor for the second age phase |
| `minutes_in_trade2` | `3` (lock) / `15` (the local copy in `determine_stoploss_price` is read but unused) | `lock_profit` | age below which `min_sl2` applies |
| `minimal_profit` | `1` (=1%) | `lock_profit` | only below this profit do the loose age-gates tighten |
| `hp_setting1..8` | see below | `lock_profit` | highest-profit ratchet tiers |

**Verified DOGEAI strategy values** (`wp_trading_allrules` IDs 10,11,12,20,21,22,23 share):

```json
{ "min_sl1": 0.988, "minutes_in_trade1": 6, "min_sl2": 0.99, "minutes_in_trade2": 15,
  "minimal_profit": 0.8,
  "hp_setting1": -0.003, "hp_setting2": -0.003, "hp_setting3": -0.003, "hp_setting4": -0.003,
  "hp_setting5": 0.002, "hp_setting8": -0.1 }
```

> **The singular keys `min_sl` / `minutes_in_trade` are NOT read by this legacy path.** Only
> the numbered variants are consumed. (Older schema ID 18 uses singular keys; not the
> DOGEAI strategy.) Confirm production rows are numbered (§6).

### 2.3 `determine_stoploss_price` — the three CHECKS, in order

The function runs four things sequentially **before** trailing. The first that matches sells.

**CHECK 1 — absolute floor** (`4530-4541`):
```
minimum_price = round(min_sl1 * buy_price, rounding)
if marketprice < minimum_price:
    SELL  →  stoploss_price = marketprice
             selling_price  = marketprice * stoploss_multiplier_selling
```
This is the time-independent hard stop. It produces the sub-0.988 "market-gap" sells observed
in DOGEAI (price gapped through the floor; sells below 0.988).

**CHECK 2 — hardcoded age-vs-profit ladder** (`4548-4579`) — **NOT from `SL_settings`**:
```
array_profit = [[5,-0.4],[7,-0.1],[8,0],[20,0.5]]
if minutes_in_trade < 5: skip
else for [minutes, targetProfit] in array_profit:
    if minutes_in_trade > minutes and profit < targetProfit:
        SELL  →  stoploss_price = marketprice
                 selling_price  = marketprice * stoploss_multiplier_selling
```
Semantics: after 5 min require profit ≥ −0.4%; after 7 min ≥ −0.1%; after 8 min ≥ 0%; after
20 min ≥ 0.5%. **Port this array verbatim.** It is independent of the JSON.

**CHECK 3 — trailing-stop hit** (`4590-4601`):
```
if stoploss_price (from prev minute) is set and stoploss_price > marketprice:
    SELL  →  stoploss_price = marketprice
             selling_price  = marketprice * stoploss_multiplier_selling
```

**NEW-STOP COMPUTATION** (only if no CHECK fired; `4609-4736`):
```
lock_price       = lock_profit(profit, minutes_in_trade, highest_profit_loss,
                               buy_price, marketprice, false, SL_settings)   # see §2.4
lock_profit_stoploss_overrule = false        # HARD-RESET at line 4623 → overrule path is DEAD
result_stoploss  = rule_engine(101, check_date, symbol, buy_price_date, ...)  # §3
stoploss_mult    = result_stoploss['stoploss']        # a MULTIPLIER or '' (empty)

if stoploss_mult != '':
    rule_engine_stoploss_price = stoploss_mult * marketprice
    new_stoploss_price = rule_engine_stoploss_price
    if lock_price > rule_engine_stoploss_price and orderstatus != 'overrule':
        new_stoploss_price = lock_price        # take the HIGHER (tighter) floor
else:
    new_stoploss_price = lock_price

new_stoploss_price = round(new_stoploss_price, rounding)
if new_stoploss_price > marketprice:           # implied stop is already above price → exit
    orderstatus = 'sell'
    new_stoploss_price = marketprice
selling_price = round(new_stoploss_price * stoploss_multiplier_selling, rounding)
if new_stoploss_price < minimum_price:         # floor clamp
    return stoploss_price = minimum_price, selling_price = minimum_price * mult
return stoploss_price = new_stoploss_price, selling_price, orderstatus   # usually 'hold'
```

> `stoploss_multiplier_selling = symbol.stoploss_multiplier` (per-symbol, ~0.999, from
> `wp_trading_symbols`). `rounding = symbol.roundingup`. **Both must be read from
> `wp_trading_symbols WHERE id=2525`** — they are not in `SL_settings`. This is the source of
> the synthetic `selling_price` (§4.2).

### 2.4 `lock_profit()` — the trailing floor (`functions_br.php:4744`)

Defaults overridden from `SL_settings`. **`hp_setting7` is read (4802) then unconditionally
overwritten to 15 (4805)** — the JSON value has no effect; replicate the hard 15.

Logic, in order (first match wins):

```
1. (4810) if marketprice < buy_price*min_sl1:
        lock_price = marketprice*0.9999 ; overrule = true       # (overrule ignored downstream)
2. (4821) if profit empty or highest_profit_loss empty/non-numeric:
        lock_price = buy_price*min_sl1
3. (4831) TIME GATE 1: if minutes_in_trade < minutes_in_trade1 and profit < minimal_profit:
        lock_price = buy_price*min_sl1 ; overrule = true        # loosest stop while young
4. (4842) TIME GATE 2: if minutes_in_trade < minutes_in_trade2 and profit < minimal_profit:
        lock_price = buy_price*min_sl2 ; overrule = true        # tighter as it ages
5. (4895) HIGHEST-PROFIT RATCHET (only if highest_profit_loss >= 0.15):     # fractions = percent
        0.15..0.21 -> lock = buy_price + hp_setting1*buy_price
        0.21..0.30 -> hp_setting2
        0.30..0.40 -> hp_setting3
        0.40..0.50 -> hp_setting4
        0.50..0.70 -> hp_setting5
        0.70..5    -> lock = buy_price + ((highest_profit_loss/hp_setting6)/100)*buy_price   # hp6=4
        >=5        -> lock = buy_price + ((highest_profit_loss-hp_setting7)/100)*buy_price    # hp7=15
6. (4928) fallback: lock_price = buy_price*min_sl1
return { lock_price, overrule, information_lock }
```

> **Two dead behaviours to replicate, not fix:**
> 1. `lock_profit`'s `overrule` is hard-reset to `false` at `determine_stoploss_price:4623`,
>    so the overrule early-return (`4624-4636`) is **dead code**. `lock_profit` can never
>    force an early sell; only its `lock_price` is used.
> 2. The HP ratchet (step 5) keys on `highest_profit_loss`, which the driver **fails to
>    update** due to a PHP cast-on-LHS no-op (§4.3). So `highest_profit_loss` is passed in as
>    `0` for essentially every minute, the ratchet **never fires**, and `lock_price` always
>    falls through to the fallback `buy_price*min_sl1` (or `min_sl2` via the age gates).
>    **This means the live simulation's trailing floor is effectively just the
>    `min_sl1`/`min_sl2` step function plus CHECK 1/2 and rule 101 — the profit ratchet is
>    inert.** Verify against the DB before trusting any ratchet behaviour (§6).

### 2.5 How the floor tightens over `minutes_in_trade` (effective behaviour)

Combining §2.3–§2.4 with the excursion bug (§4.3), the *effective* floor for DOGEAI is:

| Phase | Condition | Floor |
|-------|-----------|-------|
| Young | `minutes_in_trade < 6` and `profit < 0.8%` | `0.988 × buy_price` (min_sl1) |
| Mid | `6 ≤ minutes_in_trade < 15` and `profit < 0.8%` | `0.99 × buy_price` (min_sl2) |
| Mature / in-profit | otherwise | fallback `0.988 × buy_price`, then trail via CHECK 3 / rule 101 |

Plus the always-on absolute floor (CHECK 1) at `0.988 × buy_price` and the hardcoded CHECK 2
profit ladder. **DB-confirmed:** lt-6-min DOGEAI sells cluster at ratio `0.988` (and a tail
below it from CHECK 1 gaps); ge-6-min sells never go below `0.988`, with a `0.99` cluster —
exactly the 0.988→0.99 transition.

---

## 3. The sell rule 101 (`rule_engine(101,...)`)

This is trigger family **C**. Rule 101 is invoked once per minute from inside
`determine_stoploss_price` (line 4642). It does **not** decide the sell directly; it returns
two scalars that the caller consumes:

- `orderstatus` ∈ `'hold' | 'sell' | 'overrule'` (default `'hold'`)
- `stoploss` = a **price MULTIPLIER** (e.g. `0.999`, `1.1`) or `''` (default empty)

The caller does `rule_engine_stoploss_price = stoploss * marketprice` (line 4652). **Treat
`result_values['stoploss']` uniformly as a multiplier of marketprice** — this is the single
most important porting invariant for rule 101 (see §3.5 ambiguity).

### 3.1 Engine structure (`functions_br.php:268`)

- Loads subrules of `rule_number=101` via `get_trading_symbol_rules`.
- Sets `result_values['orderstatus']='hold'`, `result_values['stoploss']=''` (346-347).
- Because `rule==101`, forces `$test_all=1` (345): **every subrule body executes** regardless
  of its own boundary result (each guarded by `if($result_br || $test_all==1)`).
- Loops subrules (`foreach`, 363), switching on `$subrulename`.
- Returns array exposing `orderstatus` (4430) and `stoploss` (4431).
- **Accumulation rule:** every writer raises the stop only if higher —
  `if(result_values['stoploss']=='' || result_values['stoploss'] < value_condition)`. The
  engine keeps the **HIGHEST** multiplier across subrules and never lowers it.

Per-row fields (406-417): `indicator`, `b_min`(=`boundary_low`), `b_max`(=`boundary_high`),
`def1_value`, `def2_value`, `condition_rule`, `operator`, `value_condition`.

### 3.2 Verified DOGEAI rule-101 subrules (`wp_trading_rules`, rule_number=101, active=1)

7 active subrules, all `indicator=volumeud`, `processed=1`, `level=1`:

| ID | subrulename | def1 | b_min | b_max | operator | value_condition | condition_rule | sort |
|----|-------------|------|-------|-------|----------|-----------------|----------------|------|
| 572 | `sell_x_below` | 4 | — | 4 | `SL` | `0.999` | 1 | 10 |
| 769 | `sell_negative_volume` | — | — | — | `''` | `0.98` | 1 | 20 |
| 998 | `previous_value` | 3 | −0.5 | — | `SL` | `{"diff_price":1}` | 2 | 1012 |
| 999 | `previous_value` | 2 | −0.6 | — | `SL` | `{"diff_price":1}` | 2 | ... |
| 1000 | `previous_value` | 4 | −0.5 | — | `SL` | `{"diff_price":1}` | 2 | ... |
| 1001 | `previous_value` | 5 | −0.1 | — | `SL` | `{"diff_price":1}` | 2 | ... |
| 1002 | `previous_value` | 7 | −0.01 | — | `SL` | `{"diff_price":1}` | 2 | ... |

### 3.3 Subrule type A — `sell_negative_volume` (`functions_br.php:2662`)

*Goal: tighten the SL when `volumeud` just turned negative, anchoring the new stop to the
highest price since buy; and immediately sell once that implied stop exceeds the current price.*

```
1. min_volume = settings JSON (default 0)                               # 2682-2688
2. fetch last 3 volumeud values; current=[0], prev=[1]; current dt/price # 2693-2714
3. if current_indicator_value > 0:  break       # volume positive, do nothing  (2719-2722)
4. if (check_date - current_negative_dt) > 10 seconds:  break    # stale reading (2724-2729)
5. (found_max_price, found_max_datetime) = max price of volumeud in [buy_date .. check_date]   # 2736-2748
6. price_diff_perc = calc_percentage(current_price, found_max_price, 4)  # 0 if same dt   (2755)
7. if price_diff_perc == 0:        # price IS the post-buy max
        raise stoploss to value_condition (if higher) ; break           # 2762-2769
   else (max > current):
        new_stop = found_max_price * value_condition                    # 2781
        value_condition = calc_percentage(current_price, new_stop, 4)
        value_condition = 1 + (value_condition/100)                     # → multiplier vs current  (2784-2786)
        raise stoploss to value_condition (if higher)                   # 2788-2794
8. FINALLY: if result_values['stoploss'] > 1:                           # implied stop ABOVE price
        orderstatus = 'sell'                                            # 2802-2807
```
So this subrule trails the stop up to a fraction of the post-buy peak AND triggers an immediate
sell once that implied stop exceeds the current price.

### 3.4 Subrule type B — `sell_x_below` (`functions_br.php:2818`)

Fields: `def1_value` = number of rows to check (limit); `b_max` = required count of negatives;
`value_condition` = SL multiplier applied when triggered.

```
limit = (int) def1_value
fetch limit+1 newest volumeud rows                                      # 2825
count_negative = 0
for i in 0..limit:
    round value to 2 dp; store value/price                             # 2837-2841
    if row.datetime < buy_price_date:  break_case = true; break        # window predates buy (2847-2852)
    if i == 0:
        if value < 0: count_negative++                                 # 2855-2857
    else:  # i > 0
        if value < 0 and row.price < rows[i+1].price:  count_negative++ # negative + falling price (2858-2860)
# after loop:
if count_negative >= b_max:                                            # 2872
    orderstatus = 'sell'                                               # 2877
    raise stoploss to value_condition (if higher)                     # 2878-2884
else:
    leave orderstatus 'hold', stoploss unchanged
```

> **Boundary care:** at the last iteration `rows[i+1]` may be an undefined index when `i+1`
> exceeds the `limit+1` fetch. PHP yields `null` → the `<` comparison is false → that row is
> not counted. **Replicate this:** out-of-range "next row" must make the falling-price
> condition false.

### 3.5 Subrule type C — `previous_value` with `operator=='SL'` (`functions_br.php:1603`, SL branch 1642-1748)

Fields: `value_condition` = JSON bounds object (`{"diff_price":1}` etc.); `def1_value` =
lookback period (exact row count); `b_min`/`b_max` bound the computed diff; `condition_rule==2`
enables the `'overrule'` path.

```
1. buy_price_date_new = get_time_seconds_ago_date(20, buy_price_date)   # refetch from 20s pre-buy (1645-1647)
   require buy_price_date set (else break)
   require returned-row-count == def1_value (else break)                # exact-count gate (1659-1671)
2. result_previous = calc_abs_diff_percentage(values, def1_value, price?)# (1674-1678)
   relevant output: diff_previous_value = calc_percentage(last_value, first_value, 1)
                  = % change OLDEST→NEWEST over the lookback              # functions_br.php:7778
3. result_value = chosen from the diff per which bounds key is present, round to 1 dp  # 1695-1715
4. bounds check:
   if result_value < round(b_min,1):  result_br = false                 # 1718-1721
   if result_value > round(b_max,1):  result_br = false                 # 1727-1730
5. THE SELL/SL TRIGGER (1742-1748):
   if condition_rule==2 and operator=='SL' and not result_br:
        result_values['stoploss']    = 1.1            # multiplier > 1 → stop above price
        result_values['orderstatus'] = 'overrule'
```
A multiplier of `1.1` × marketprice puts the implied stop above marketprice, so downstream
(`determine_stoploss_price:4691`) forces `orderstatus='sell'`. So `'overrule'` here is
effectively a **forced sell when the price-trend over the lookback breaches the configured
band**. The 5 `previous_value` subrules (def1 = 2,3,4,5,7) each test a different lookback with
its own `b_min` floor on the oldest→newest % move.

### 3.6 Generic `operator=='SL'` branch (`functions_br.php:2136`)

For a generic indicator subrule with `operator=='SL'` and `value_condition` set, if
`current_value_indicator` is strictly between `b_min` and `b_max` (2138):
- if `def1_value == 1` → `orderstatus='sell'` (2139)
- if `condition_rule == 2` → `stoploss = value_condition`, `orderstatus='overrule'` (2141-2146)
- else → raise `stoploss` to `value_condition` only if higher (2148-2157)

Similar SL-raising/overrule logic appears in `wait_on_buy` (2476-2491), `sell_after_value`
(2929-2946), `sell_highvolume` (2541-2552). The DOGEAI rule-101 set (§3.2) only uses
`sell_x_below`, `sell_negative_volume`, and `previous_value`, so these siblings are not on the
critical path for DOGEAI but must exist in the port for generality.

---

## 4. The backtest computation — exact formulas

### 4.1 The per-minute driver loop (`process_sell_simulation_trade:4944-5183`)

```
check_amounts_minutes = 1500
check_date_sell       = buy_price_date (if empty)
date_to               = buy_price_date + 1500 min
timestamps            = get_distinct_indicator_bydate_date(symbol,'volumeud',date_to,check_date_sell,'ASC')
new_highest_profit_loss = '0' ; new_lowest_profit_loss = '0'    # strings, BEFORE loop  (4975-4976)
stoploss_price_prev   = '' ; i = 2                              # NB i starts at 2

for each timestamp T (ascending):
    marketprice = get_current_indicator_value_bydate(symbol,'volumeud',T,1)[0].price    # 5055-5062
    profit      = round((marketprice - buy_price)/buy_price*100, 2)                      # 5068
    # --- excursion tracking (BUGGY — see §4.3) ---
    if i == 1:  new_lowest_profit_loss = profit          # NEVER fires (i starts at 2)   (5069)
    if (float)profit < (float)new_lowest_profit_loss:  (float)new_lowest_profit_loss = profit   # NO-OP (5073-5077)
    if (float)profit > (float)new_highest_profit_loss: (float)new_highest_profit_loss = profit  # NO-OP (5078-5082)
    # --- prior-stop breach pre-check (trigger A) ---
    sell = false
    if stoploss_price_prev set and marketprice < stoploss_price_prev:  sell = true        # 5089-5094
    # --- recompute stop + rule 101 ---
    (stoploss_price, selling_price, orderstatus) =
        determine_stoploss_price(T, buy_price, buy_price_date, marketprice,
                                 stoploss_price_prev, new_highest_profit_loss, SL_settings)  # 5100-5107
    if orderstatus == 'sell' or sell:
        # ---- FINALIZE ----
        if not selling_price:  selling_price = marketprice                                # 5113
        if sell:                                                                          # 5120-5127
            if stoploss_price > marketprice:  stoploss_price = marketprice
            else:                             stoploss_price = stoploss_price_prev
            selling_price = stoploss_price
        profit = round((selling_price - buy_price)/buy_price*100, 2)                      # 5128
        update_simulation_trade(simulation_ID, selling_price, profit, simulation_ID,
                                false, new_highest_profit_loss, new_lowest_profit_loss, T) # 5146
        return result_sell
    stoploss_price_prev = stoploss_price        # TRAIL into next minute                  (5174)
    i++
# loop exhausted 1500 min with no sell → return false (trade left open)                   (5179-5182)
```

### 4.2 `selling_price` — synthetic, NOT a market tick (verified)

`selling_price` is built two ways depending on which branch fired:

- **`determine_stoploss_price` path** (`orderstatus=='sell'`):
  `selling_price = round(stoploss_price * stoploss_multiplier_selling, rounding)` where
  `stoploss_multiplier_selling ≈ 0.999` per-symbol. (`4707`.)
- **Driver prior-stop-breach path** (`sell==true`, lines 5120-5127):
  `selling_price = stoploss_price` where `stoploss_price = min(marketprice, stoploss_price_prev)`
  effectively (clamped to marketprice if the trailed stop was above it). **No extra multiplier
  here** — match this branch exactly, because it differs slightly from the
  `*stoploss_multiplier_selling` path.

The DB sell-ratio (`selling_price/price`) clusters confirm this: most common ratio is exactly
`0.98800` (n=31, `min_sl1` floor sells → `profit_loss` −1.200), then `0.99000` (n=4,
`min_sl2`), then ratios > 1 from trailing-lock sells.

### 4.3 Excursion columns — the cast-on-LHS no-op (REPLICATE THE BUG)

`new_highest_profit_loss` and `new_lowest_profit_loss` are both seeded to the **string `'0'`**
before the loop. The intended updates at lines 5073-5082 are:
```php
if ((float)$profit < (float)$new_lowest_profit_loss) { (float)$new_lowest_profit_loss = $profit; }
if ((float)$profit > (float)$new_highest_profit_loss){ (float)$new_highest_profit_loss = $profit; }
```
`(float)$var = $profit` is a **cast on the left-hand side**, which in PHP assigns to a throwaway
temporary — `$new_*_profit_loss` is **never actually updated**. Combined with the `i==1` seed
never firing (`i` starts at 2), **both columns are written as the initial `0`** for essentially
all trades.

> **Action:** the Python port must emit `highest_profit_loss = lowest_profit_loss = 0` to match
> legacy — UNLESS the DB check (§4.6) shows real excursion values, in which case a different/older
> code path wrote them and you must investigate before porting. **§6 Q1.**
>
> *Caveat — conflicting evidence:* the `sell-db-validation` aspect reports the live
> `bot_process_selling.php` path (lines 262-268) computed running extremes *correctly* and the
> DOGEAI sample rows contain **non-zero** `highest_profit_loss` (e.g. 6.320, 4.220) and
> `lowest_profit_loss` (e.g. −1.480, −0.480). That contradicts the simulation-loop bug above.
> **This must be resolved empirically (§6 Q1): which code path produced the `wp_trading_simulation`
> rows you are validating against — `process_sell_simulation_trade` (buggy, zeros) or
> `bot_process_selling` (correct extremes)?** The validator should test both hypotheses.

### 4.4 `profit_loss` — the final formula (VERIFIED against the DB)

The persisted `profit_loss` (`decimal(6,3)`) is derived from the **synthetic `selling_price`**,
not from a market tick, and rounded to **3 decimals**:

```
profit_loss = round( (selling_price - price) / price * 100 , 3 )
```

**Verification (DOGEAI rows, diff within ±0.005 = pure decimal rounding):**

| sim ID | price | selling_price | stored | calc |
|--------|-------|---------------|--------|------|
| 17400 | 0.0198650000 | 0.0198040752 | −0.310 | −0.307 |
| 17274 | 0.0126540000 | 0.0125021520 | −1.200 | −1.200 |
| 17285 | 0.0237020000 | 0.0246960114 | 4.190 | 4.194 |

(The driver computes a 2-decimal `profit` at line 5128 for the *return value*, but the column
stored in `wp_trading_simulation` is the 3-decimal form above — confirmed by the DB. Reconcile
this 2-vs-3-decimal discrepancy during validation; **§6 Q2.**)

### 4.5 `selling_date`

`selling_date = check_date_sell` = the timestamp `T` of the minute that triggered the sell
(`update_simulation_trade` arg, line 5146).

### 4.6 DB write semantics (`update_simulation_trade`, `functions.php:1014-1056`)

Columns are **conditionally** written to `wp_trading_simulation`:
- `selling_price` — only if truthy
- `profit_loss` — only if truthy (**a genuine `0.000` profit is NOT written** — keeps prior/NULL)
- `highest_profit_loss` — if truthy **OR == 0**
- `lowest_profit_loss` — if truthy **OR == 0**
- `selling_date` — if non-empty and `!= 'NULL'`

> Edge case: a trade with exactly 0.000% profit_loss leaves the column untouched. Confirm
> against data whether any such rows exist (**§6 Q2**).

### 4.7 Validation targets (verified)

DOGEAI `trading_symbol_id = 2525` has **306 closed sims** with `selling_price` set. Time-in-trade
is heavily short-skewed (peak at 5 min, n=36). Concrete labelled trades to anchor the validator:

| sim ID | buy dt | buy price | sell dt | selling_price | profit_loss | hi | lo | duration | result |
|--------|--------|-----------|---------|---------------|-------------|----|----|----------|--------|
| 17400 | 2025-07-10 09:17:55 | 0.0198650000 | 09:23:28 | 0.0198040752 | −0.310 | 0.000 | −0.270 | 5m33s | 3 |
| 17285 | 2025-06-04 17:39:20 | 0.0237020000 | 18:23:38 | 0.0246960114 | 4.190 | 6.320 | 0.000 | 44m | 1 |
| 17274 | (—) | 0.0126540000 | (—) | 0.0125021520 | −1.200 | 0.000 | −1.480 | 2m43s | 3 |
| 17263 | (—) | 0.0162690000 | (—) | 0.0163983451 | 0.800 | 2.850 | (—) | 24m43s | (—) |
| 17265 | (—) | 0.0123960000 | (—) | 0.0125267778 | 1.050 | 4.220 | −0.480 | 4m44s | 1 |

---

## 5. Rebuild plan (Python, `brain/engine`) + validation approach

### 5.1 New modules (mirror the buy-side `calc.py` / `validate_period.py` style)

| File | Role |
|------|------|
| `engine/src/sell_lock.py` | `lock_profit()` — pure function of `(profit, minutes_in_trade, highest_profit_loss, buy_price, marketprice, sl_settings)` → `lock_price`. Replicate the `hp7=15` override, the inert ratchet, the dead `overrule`. |
| `engine/src/sell_rule101.py` | `rule_engine_101(T, symbol, buy_price_date, series, subrules, symbol_settings)` → `(orderstatus, stoploss_multiplier)`. Implements `sell_negative_volume`, `sell_x_below`, `previous_value` (SL), plus the generic-SL/sibling cases. `stoploss` is always a multiplier of marketprice. Keep the HIGHEST-multiplier accumulation. |
| `engine/src/sell_stop.py` | `determine_stoploss_price(...)` → `(stoploss_price, selling_price, orderstatus)`. CHECK 1/2/3 in order, the hardcoded `[[5,-0.4],[7,-0.1],[8,0],[20,0.5]]` ladder, reconcile lock vs rule-101 (higher wins), floor clamp, `selling_price = stop * stoploss_multiplier_selling`. Hard-reset overrule to false (line 4623). |
| `engine/src/sell_sim.py` | `process_sell_simulation_trade(buy_dt, buy_price, series, symbol_consts, sl_settings, subrules)` → the driver loop. Iterate the **same distinct `volumeud` datetimes**, trail `stoploss_price_prev`, both sell triggers, finalize per §4.1, return `(selling_price, selling_date, profit_loss, highest, lowest)`. Reproduce the excursion bug behind a flag (`replicate_excursion_bug=True`) so both hypotheses (§4.3) can be tested. |
| `engine/src/validate_sell.py` | The oracle comparison — analogous to `validate_period.py`. |

### 5.2 Per-symbol constants to load (from `wp_trading_symbols WHERE id=2525`)

`stoploss_multiplier` (selling mult ~0.999), `roundingup` (price decimals), `rounding_quantity`.
These are **not** in `SL_settings` and are required to reproduce `selling_price` to the 10th
decimal. **Query and pin them before building (§6 Q3).**

### 5.3 Price series source

`marketprice` per minute = the `price` field of the `volumeud` indicator row at `T`. The Python
port must walk the **same `wp_trading_indicator WHERE indicator='volumeud'`** distinct datetimes,
ascending, **not** a synthetic 60s clock (spacing is irregular). This matches the in-memory
`series` pattern already in `validate_period.py` (load `wp_trading_indicator` once, bisect on
datetime).

### 5.4 Validation approach (like the buy-side `validate_period`)

READ-ONLY on `bot_signals`. For DOGEAI (`SYM=2525`):

1. Pull the 306 closed sims (`SELECT ID, datetime AS buy_dt, price AS buy_price, selling_price,
   selling_date, profit_loss, highest_profit_loss, lowest_profit_loss FROM wp_trading_simulation
   WHERE trading_symbol_ID=2525 AND selling_price IS NOT NULL`).
2. For each, load the `volumeud` series from `buy_dt` to `buy_dt+1500min` (+margin) into memory.
3. Replay `process_sell_simulation_trade` and compare the five outputs:
   - `selling_date` — exact timestamp match (the trigger minute).
   - `selling_price` — match to `roundingup` decimals.
   - `profit_loss` — match within ±0.005 (rounding tolerance) using §4.4.
   - `highest_profit_loss` / `lowest_profit_loss` — match exactly (this is the test that
     resolves §4.3 / §6 Q1: if legacy is all-0, the buggy path produced them; if they equal the
     true running extremes, the correct path did).
4. Report a per-output agreement count and a per-trade pass/fail table, mirroring
   `validate_period.py`'s aggregate-and-flag style (e.g. `selling_date 298/306`,
   `selling_price 295/306`, with the diverging trades listed for inspection).
5. **Acceptance (Step 1 faithful):** ≥ the same agreement rate the buy side reached, with every
   divergence explained (gap-sell, rounding edge, or a confirmed legacy bug). Do not "fix"
   divergences that stem from replicated bugs.

### 5.5 Recommended build order

1. `sell_lock.py` + unit tests on the floor/age gates (no rule engine yet).
2. `sell_stop.py` with rule-101 stubbed (returns empty multiplier) — validates CHECK 1/2/3 +
   lock floor alone; this alone should reproduce the `0.988`/`0.99` floor sells.
3. `sell_rule101.py` — add the three subrule types; re-validate the profitable/trailing sells.
4. `sell_sim.py` driver + `validate_sell.py`; run the full 306-trade comparison.
5. Resolve §6 questions against the DB; lock the excursion-bug flag to whichever matches.

---

## 6. Open questions for Daan

1. **Excursion columns (CRITICAL — blocks the rebuild).** Are `highest_profit_loss` /
   `lowest_profit_loss` in the `wp_trading_simulation` rows we validate against **mostly 0**
   (which would confirm the cast-on-LHS bug in `process_sell_simulation_trade`), or do they
   carry **real running extremes** (e.g. 6.320 / −1.480 in the sample rows)? The two analyses
   disagree. The answer decides which legacy code path produced the data —
   `process_sell_simulation_trade` (buggy, zeros) or `bot_process_selling` (correct) — and
   therefore which behaviour the port must replicate. **The sample rows already show non-zero
   values, so the buggy-loop hypothesis may be wrong for this dataset.**

2. **`profit_loss` precision and zero-handling.** Stored `profit_loss` is 3-decimal and derived
   from `selling_price` (verified), but the driver's `round(...,2)` at line 5128 is 2-decimal.
   Which value actually lands in the column? And does any trade with exactly `0.000` profit
   exist (it would be silently *not* written per the truthy guard)?

3. **Per-symbol constants.** Confirm `wp_trading_symbols WHERE id=2525` values:
   `stoploss_multiplier` (expected ~0.999) and `roundingup` (price decimals). The ±0.005
   `profit_loss` diffs are consistent with `mult≈0.999` + rounding, but we need the exact
   numbers to reproduce `selling_price` to the 10th decimal.

4. **Which rule governs DOGEAI sells — 20 or 21?** The buy side uses **rule 21** for symbol 2525
   (`validate_period.py`, `run_engine.py`). The sell analysis says DOGEAI sells under
   `SL_settings` **ID 20**. Confirm the strategy/rule mapping so we load the correct
   `wp_trading_allrules.SL_settings` row. (The candidate strategy rows 10–23 share the same
   `SL_settings` JSON, so this may not change the numbers — but confirm.)

5. **`SL_settings` key schema in production.** Confirm production rows use the **numbered** keys
   (`min_sl1/2`, `minutes_in_trade1/2`) and not the singular legacy schema (ID 18). The singular
   keys are silently ignored by this code path, so a misconfigured row would run on defaults.

6. **`overrule` is dead — was it always?** `lock_profit`'s `overrule` is hard-reset to false at
   `determine_stoploss_price:4623`, making its early-sell path dead code in the simulation. Was
   the *live* trader ever built with overrule active (a different build), or is the simulation
   data we validate against guaranteed to have run with overrule disabled? A faithful replica
   keeps it disabled.

7. **`result` column legend.** Sample rows show `result=1` on a 44-min winner and `result=3` on
   small losses. Is `result` a win/loss/breakeven classification we can trust as a validation
   key, or derived after the fact? (Needed only if we cross-check by `result`.)

8. **HP-ratchet big-winner tiers untested.** The `≥0.70` and `≥5` highest-profit tiers
   (`hp_setting6=4`, `hp_setting7` forced 15) never fire in the DOGEAI sample (and are inert if
   the excursion bug holds, per Q1). If Q1 resolves to "real extremes", these tiers become live
   and must be validated on the big-winner trades before trusting them.

---

## Appendix — legacy file:line index

| Symbol | File | Lines |
|--------|------|-------|
| `rule_engine` entry; `test_all=1`; defaults | `legacy/managesignal/functions_br.php` | 268-347 |
| subrule loop + field extraction | same | 363-417 |
| `previous_value` (SL branch) | same | 1603-1764 |
| generic `operator=='SL'` | same | 2136-2165 |
| `sell_negative_volume` | same | 2662-2813 |
| `sell_x_below` | same | 2818-2897 |
| `rule_engine` return (orderstatus/stoploss) | same | 4409-4441 |
| `determine_stoploss_price` (CHECK 1/2/3 + new-stop) | same | 4471-4742 |
| `lock_profit` | same | 4744-4936 |
| `process_sell_simulation_trade` (driver) | same | 4944-5183 |
| excursion cast-bug | same | 5068-5082 |
| final `profit_loss` + DB write | same | 5128-5159 |
| `calc_abs_diff_percentage` | same | 7709-7783 |
| `diff_seconds_twodates` | `legacy/functions.php` | 623-636 |
| `update_simulation_trade` (SQL) | `legacy/functions.php` | 1014-1056 |
| `get_distinct_indicator_bydate_date` | `legacy/functions.php` | 1732-1749 |
| live (non-sim) sell path | `legacy/bot_process_selling.php` | 234-268 |
