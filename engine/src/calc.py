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


def window_metrics(vals):
    """Compute the shared metric-set over a newest-first list of floats."""
    if not vals:
        return {}
    first, last = vals[0], vals[-1]          # first = newest, last = oldest
    m = {
        "first_value": first,
        "last_value": last,
        "diff_previous_number": first - last,                 # change over the window (newest - oldest)
        "max_diff_number": max(abs(v - first) for v in vals),  # largest abs deviation from newest
        "lowest_value": min(vals),
        "highest_value": max(vals),
    }
    if len(vals) >= 2:
        std = float(np.std(vals, ddof=1))                      # sample std (n-1)
        m["standard_deviation"] = std
        m["volatility"] = std / first if first > 0 and std > 0 else 0.0
        # population skew (/n); 0 when values are (near-)identical to avoid NaN/precision noise
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

    return None  # missingdata, volume_check — TODO
