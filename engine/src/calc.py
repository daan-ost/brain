"""
Generalized, testable window-metric calculator — the rebuilt core of the legacy
`calc_abs_diff_percentage()` + the subrulename/value_condition selector.

Key insight from the legacy: each subrulename computes ONE shared metric-set over
a window, and `value_condition` selects which metric becomes the checked value.
So we compute the metric-set once, then select — clean and unit-testable.

Windows are newest-first (index 0 = most recent value at/before T), matching legacy
(rows fetched datetime DESC).
"""
import numpy as np
from scipy import stats


def calc_percentage(frm, to):
    """Legacy calc_percentage: signed % change from `frm` to `to` (negative if frm>to)."""
    diff = abs(frm - to)
    if frm == 0 or diff == 0:
        return 0.0
    perc = diff / abs(frm) * 100
    return -perc if frm > to else perc


# ---- helpers ported from legacy functions_br.php ----
def _count_reversals(arr):                                   # count_reversals (8000)
    count = 0; prev = None; trend = None
    for v in arr:
        if prev is None:
            prev = v; continue
        if v > prev:
            if trend == "down":
                count += 1
            trend = "up"
        elif v < prev:
            if trend == "up":
                count += 1
            trend = "down"
        prev = v
    return count


def _consecutive(arr, direction):                            # count_consecutive_changes (8052) — max run
    maxc = cur = 0; prev = None
    for v in arr:
        if prev is None:
            prev = v; continue
        if (direction == "up" and v > prev) or (direction == "down" and v < prev):
            cur += 1
        else:
            maxc = max(maxc, cur); cur = 0
        prev = v
    return max(maxc, cur)


def _avg_reversal_size(arr):                                 # calculate_average_reversal_size (7960)
    count = 0; total = 0.0; prev = None; trend = None
    for v in arr:
        if prev is None:
            prev = v; continue
        if v > prev:
            if trend == "down":
                count += 1; total += abs(v - prev)
            trend = "up"
        elif v < prev:
            if trend == "up":
                count += 1; total += abs(v - prev)
            trend = "down"
        prev = v
    return total / count if count else 0.0


def _max_same_value(arr, margin=0.01):                       # highest_occurrences_within_margin (1% margin)
    best = 0
    for a in arr:
        tol = abs(a) * margin if a else margin
        c = sum(1 for b in arr if abs(b - a) <= tol)
        best = max(best, c)
    return best


# the full per-window calculation set ("Test types"), ported from calc_abs_diff_percentage (7709).
# Excludes the cross-indicator/trend specials (sideways, fast_increase, increase_all_indicators,
# trend_up_and_down, profit_change_compared_to_current) — those need multi-series/trend logic.
WINDOW_METRIC_KEYS = (
    "current_value", "first_value", "last_value", "diff_previous_value", "diff_previous_number",
    "max_diff_number", "max_diff_percentage", "diff_number_prev_max", "diff_number_prev_min",
    "diff_percentage_prev_max", "diff_percentage_prev_min", "sum_average_positive_percentage",
    "lowest_value", "highest_value", "sum_value", "diff_lowest_value_period", "diff_highest_value_period",
    "standard_deviation", "volatility", "range_percentage", "consecutive_increases",
    "consecutive_decreases", "reversal_count", "average_reversal_size", "median_value", "skewness",
    "count_positive", "count_negative", "max_same_value",
    "sideways_upper", "sideways_lower",                   # checkSideWays band (extremes removed)
)


def fast_increase(prices):
    """Legacy 'fastincrease' classifier over the first ~7 price points (newest-first). Returns
    first_diff, or 0.001 (a 'too-fast/choppy' kill marker) — the 'te snelle stijging' detector.
    Price-only (volumeud), fixed window: NOT a per-lookback cache metric, used at rule-eval time."""
    if not prices or len(prices) < 2:
        return 0.0
    d = [calc_percentage(prices[i], prices[i - 1]) for i in range(1, min(7, len(prices)))]
    while len(d) < 6:
        d.append(0.0)
    first, second, third, fourth, fifth, sixth = d[:6]
    sap = window_metrics(prices[3:]).get("sum_average_positive_percentage", 0.0) if len(prices) > 3 else 0.0
    show = first
    if sap > 0.5 and 2.1 <= first < 5:
        show = 0.001
    elif second > 1 or third > 1 or fourth > 1 or fifth > 1 or sixth > 1:
        show = 0.001
    if sap > 0.04 and 0.5 < first < 2.1:
        show = 0.001
    if first > 5:
        show = first
    if first > 1 and second > 1 and (first + second) > 3.5:
        show = first
    return show


def window_metrics(vals):
    """All per-window calculations over a newest-first list of floats (vals[0] = current)."""
    if not vals:
        return {}
    first, last = vals[0], vals[-1]                          # first = newest/current, last = oldest
    lowv, highv, sumv = min(vals), max(vals), sum(vals)

    # consecutive previous-value diffs (legacy loops newest -> oldest)
    dnum_max = dnum_min = dpct_max = dpct_min = 0.0
    sum_pos_pct = 0.0; n_pairs = 0
    for i in range(1, len(vals)):
        cur, prev = vals[i], vals[i - 1]
        dn = cur - prev
        dp = calc_percentage(cur, prev)
        n_pairs += 1
        if dn > dnum_max:
            dnum_max = dn
        if dn < dnum_min:
            dnum_min = dn
        if dp >= 0:
            sum_pos_pct += dp
            if dp > dpct_max:
                dpct_max = dp
        elif dp < dpct_min:
            dpct_min = dp

    m = {
        "current_value": first,
        "first_value": first,
        "last_value": last,
        "diff_previous_value": calc_percentage(last, first),  # % change oldest -> newest
        "diff_previous_number": first - last,
        "max_diff_number": max(abs(v - first) for v in vals),
        "max_diff_percentage": max(abs(calc_percentage(first, v)) for v in vals) if first else 0.0,
        "diff_number_prev_max": dnum_max,
        "diff_number_prev_min": dnum_min,
        "diff_percentage_prev_max": dpct_max,
        "diff_percentage_prev_min": dpct_min,
        "sum_average_positive_percentage": round(sum_pos_pct / n_pairs, 2) if n_pairs else 0.0,
        "lowest_value": lowv,
        "highest_value": highv,
        "sum_value": sumv,
        "diff_lowest_value_period": first - lowv,
        "diff_highest_value_period": first - highv,
        "range_percentage": (abs(highv - lowv) / sumv * 100) if (highv != lowv and sumv != 0) else 0.0,
        "consecutive_increases": _consecutive(vals, "up"),
        "consecutive_decreases": _consecutive(vals, "down"),
        "reversal_count": _count_reversals(vals),
        "average_reversal_size": _avg_reversal_size(vals),
        "median_value": float(np.median(vals)),
        "count_positive": sum(1 for v in vals if v > 0),
        "count_negative": sum(1 for v in vals if v < 0),
        "max_same_value": _max_same_value(vals),
    }
    # sideways band (checkSideWays): drop current + the single max & min, then % band vs current
    rest = vals[1:]
    if len(rest) >= 3:
        mx, mn = max(rest), min(rest)
        filt = [v for v in rest if v != mx and v != mn]
    else:
        filt = rest
    if filt:
        m["sideways_upper"] = calc_percentage(first, max(filt))
        m["sideways_lower"] = calc_percentage(min(filt), first)
    else:
        m["sideways_upper"] = 0.0
        m["sideways_lower"] = 0.0
    if len(vals) >= 2:
        std = float(np.std(vals, ddof=1))                     # sample std (n-1)
        m["standard_deviation"] = std
        m["volatility"] = std / first if first > 0 and std > 0 else 0.0
        m["skewness"] = float(stats.skew(vals, bias=True)) if float(np.std(vals)) > 1e-12 else 0.0
    else:
        m.update(standard_deviation=0.0, volatility=0.0, skewness=0.0)
    return m


def subrule_value(subrulename, value_condition, vals, prices):
    """Return the value a subrule checks against [b_min, b_max], or None if not yet handled.

    vals/prices are newest-first lists for the subrule's (indicator, def1) window.
    value_condition is the decoded JSON dict (or {}).
    """
    vc = value_condition or {}

    if subrulename == "currentvalue":
        return round(vals[0], 2) if vals else None

    if subrulename == "previous_value":
        # diff_price -> PERCENTAGE change of price (diff_previous_value); diff_number -> raw value diff.
        # Legacy rounds to 1 decimal (calc_percentage round=1 / round at functions_br.php:1715).
        if vc.get("diff_price"):
            if len(prices) < 1:
                return None
            return round(calc_percentage(prices[-1], prices[0]), 1)   # last(oldest) -> first(newest)
        if len(vals) < 1:
            return None
        return round(vals[0] - vals[-1], 1)                            # newest - oldest

    if subrulename == "volatility":
        m = window_metrics(vals)
        if vc.get("number_absolute"):
            return round(m.get("max_diff_number", 0.0), 4)
        if vc.get("percentage_absolute"):
            return round(m.get("max_diff_percentage", 0.0), 4)
        return round(m.get("volatility", 0.0), 4)

    if subrulename == "skewness":
        return round(window_metrics(vals).get("skewness", 0.0), 5)

    if subrulename == "range_percentage":
        return round(window_metrics(vals).get("range_percentage", 0.0), 5)

    # futureprice / futureprice_x_rows: backtest-only look-ahead; legacy SKIPS it live
    # (functions_br.php:782-785) → always pass. Leak-free for live/ML.
    if subrulename in ("futureprice", "futureprice_x_rows"):
        return "PASS"

    # any of the 31 window calculations by name (the new tuned subrules from indicator_metrics)
    if subrulename in WINDOW_METRIC_KEYS:
        v = window_metrics(vals).get(subrulename)
        return round(float(v), 5) if v is not None else None

    return None  # missingdata, volume_check — handled by the caller via volume.py
