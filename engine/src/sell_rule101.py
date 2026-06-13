"""
Rule 101 sell-engine — the trailing sell logic that lets winners run until a sell
signal fires. Faithful to docs/methodology/selling-process.md §3.

rule_engine_101() runs every minute and returns (orderstatus, stoploss_multiplier),
where stoploss is a MULTIPLIER of the current marketprice (or '' = empty). The engine
keeps the HIGHEST multiplier across subrules (never lowers it).

Subrule types (DOGEAI rule 101): sell_negative_volume, sell_x_below, previous_value(SL).
Series arrays DT/PX/VV are volumeud datetime/price/value, ascending; i = index of the
current minute T.
"""
import bisect
import json
from datetime import timedelta


def calc_percentage(frm, to):
    """Legacy calc_percentage: signed % (negative if frm>to)."""
    if frm == 0:
        return 0.0
    diff = abs(frm - to)
    if diff == 0:
        return 0.0
    p = diff / abs(frm) * 100
    return -p if frm > to else p


def rule_engine_101(DT, PX, VV, i, buy_dt, buy, market, subrules, max_price_since_buy):
    orderstatus = "hold"
    stoploss = None  # None == legacy '' (empty)

    def raise_stop(v):
        nonlocal stoploss
        if stoploss is None or stoploss < v:
            stoploss = v

    for sr in subrules:
        name = sr["subrulename"]

        # --- A: sell_negative_volume (trail to a fraction of the post-buy peak, sell if above price) ---
        if name == "sell_negative_volume":
            if VV[i] > 0:                       # volume positive → do nothing
                continue
            vc = float(sr["value_condition"])   # 0.98
            mx = max_price_since_buy
            pdp = calc_percentage(market, mx)
            if pdp == 0:                        # current price IS the post-buy max
                raise_stop(vc)
            else:
                new_stop = mx * vc
                mult = 1 + calc_percentage(market, new_stop) / 100   # multiplier vs current
                raise_stop(mult)
            if stoploss is not None and stoploss > 1:
                orderstatus = "sell"

        # --- B: sell_x_below (sell if >= b_max of the last def1 rows are negative + falling) ---
        elif name == "sell_x_below":
            limit = int(float(sr["def1_value"]))
            need = int(float(sr["b_max"]))
            vc = float(sr["value_condition"])   # 0.999
            cnt = 0
            for k in range(0, limit + 1):
                idx = i - k
                if idx < 0 or DT[idx] < buy_dt:
                    break
                val = round(VV[idx], 2)
                if k == 0:
                    if val < 0:
                        cnt += 1
                else:
                    nxt = idx - 1                # next-older row; out-of-range → condition false
                    if val < 0 and nxt >= 0 and PX[idx] < PX[nxt]:
                        cnt += 1
            if cnt >= need:
                orderstatus = "sell"
                raise_stop(vc)

        # --- C: previous_value (SL) — forced sell when the price-trend since buy breaches b_min ---
        elif name == "previous_value":
            vc = json.loads(sr["value_condition"]) if sr["value_condition"] else {}
            def1 = int(float(sr["def1_value"]))
            start = bisect.bisect_left(DT, buy_dt - timedelta(seconds=20))
            if (i - start + 1) != def1:         # exact-count gate (fires only early in the trade)
                continue
            arr = (PX if vc.get("diff_price") else VV)[start:i + 1]
            if len(arr) < 2:
                continue
            result_value = round(calc_percentage(arr[0], arr[-1]), 1)   # % change oldest→newest
            result_br = True
            if sr["b_min"] is not None and result_value < round(float(sr["b_min"]), 1):
                result_br = False
            if int(sr["condition_rule"] or 0) == 2 and sr["operator"] == "SL" and not result_br:
                stoploss = 1.1
                orderstatus = "overrule"

    return orderstatus, ("" if stoploss is None else stoploss)
