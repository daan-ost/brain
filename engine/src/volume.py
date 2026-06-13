"""
Faithful rebuild of the legacy volume-cluster subrules:
  missingdata       (functions_br.php:918)
  volume_check / check_volumeud_3 (functions_br.php:5761)

These are stateful loops over the volumeud series (datetime + value + price),
unlike the simple window metrics in calc.py — so they live here.

Rows are passed newest-first (datetime DESC), matching the legacy fetch.
"""
from calc import calc_percentage


def missingdata(rows):
    """Max seconds-gap between consecutive volumeud rows where price rose >0.3%.
    rows: newest-first list of dicts {datetime, price}, already limited to def1 rows
    within the 300-min window. Returns the max gap (legacy starts at 1)."""
    max_diff = 1
    prev_time = prev_price = None
    for r in rows:
        if prev_price is not None:
            seconds_diff = abs((r["datetime"] - prev_time).total_seconds())
            price_increase = calc_percentage(float(r["price"]), float(prev_price))
            if seconds_diff > max_diff and price_increase > 0.3:
                max_diff = seconds_diff
        prev_time, prev_price = r["datetime"], r["price"]
    return float(max_diff)


# Rule-21 settings for check_volumeud_3 (volume_check case 2258-2321; min_volume from
# wp_trading_symbols_rule). Rule-21 OVERRIDES (functions_br.php:2316): multiplier_volume_sum_min=2.1
# and min_price_diff_percentage=0.03 (NOT the base 8 / 1).
RULE21_VOLUME_SETTINGS = dict(
    multiplier_volume_sum_min=2.1, multiplier_volume_sum_max=20,
    rows_to_analyse=30, minutes_to_analyse=60, minimal_rows_to_analyse=5,
    minimal_relative_volume=0.4, maximal_relative_volume=4,
    not_negative_before_x_values=2.8, trigger_minimal_volume_relative=0.03,
    max_price_diff_percentage=10, min_price_diff_percentage=0.03,
)


def check_volumeud_3(rows, min_volume, s=RULE21_VOLUME_SETTINGS):
    """Volume-spike-after-accumulation detector. rows: newest-first list of dicts
    {value, price} for the last `minutes_to_analyse` minutes. Returns True (buy) / False.
    Faithful port of functions_br.php:5761-5984."""
    if not rows or len(rows) < s["minimal_rows_to_analyse"]:
        return False
    value_0 = round(float(rows[0]["value"]))
    price_0 = float(rows[0]["price"])
    if value_0 < 1:
        return False
    rel0 = round(value_0 / min_volume, 2)
    if rel0 < s["minimal_relative_volume"] or rel0 > s["maximal_relative_volume"]:
        return False

    counter = 0
    sum_volume = 0.0
    for r in rows:
        val = float(r["value"])
        rel_vol = round(val / min_volume, 5) if (min_volume != 0 and val != 0) else 0.0001
        rel_sum = round(sum_volume / min_volume, 1) if sum_volume > 0 else 0.0

        if counter >= s["rows_to_analyse"]:
            return False
        # early low volume -> fail
        if rel_vol < s["trigger_minimal_volume_relative"] and counter < s["not_negative_before_x_values"]:
            return False
        # accumulate the first rows
        if rel_vol >= s["trigger_minimal_volume_relative"] and counter < s["not_negative_before_x_values"]:
            sum_volume += val
            counter += 1
            continue
        # trigger hit: volume dropped after accumulation
        if rel_vol < s["trigger_minimal_volume_relative"] and counter >= s["not_negative_before_x_values"]:
            if s["multiplier_volume_sum_min"] <= rel_sum <= s["multiplier_volume_sum_max"]:
                diff_pct = calc_percentage(float(r["price"]), price_0)
                if diff_pct > s["max_price_diff_percentage"] or diff_pct < s["min_price_diff_percentage"]:
                    return False
                return True
            return False
        # else: volume still high, keep accumulating
        sum_volume += val
        counter += 1
    return False
