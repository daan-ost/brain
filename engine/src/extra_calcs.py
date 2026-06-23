"""
extra_calcs — NIEUWE window-berekeningen uit het feature-research (2026-06-23), bovenop de 31 in
`calc.WINDOW_METRIC_KEYS`. Allemaal pure numpy/scipy (al geinstalleerd), leak-vrij (alleen het window
<= T), EEN scalar over een kort newest-first window, en schaal-vrij (ratio/rang/genormaliseerd) zodat
ze cross-coin bruikbaar zijn — net als een engine-subregel. Input `vals` = newest-first (vals[0] = T).

Bron: workflow feature-calc-research add_now + critic. Elke functie geeft None bij te kort/ontaard window.
"""
import numpy as np
from scipy import stats

EPS = 1e-9


def _oldnew(vals):
    """numpy array oud->nieuw (tijdsvolgorde) van een newest-first lijst."""
    return np.asarray(vals[::-1], dtype=float)


def kendall_tau(vals):
    """Rang-correlatie waarde<->tijd: monotone op/neergang, robuust + niet-lineair. [-1,1]."""
    if len(vals) < 4:
        return None
    y = _oldnew(vals)
    if np.all(y == y[0]):
        return 0.0
    t, _ = stats.kendalltau(np.arange(len(y)), y)
    return None if t is None or np.isnan(t) else float(t)


def linreg_r2(vals):
    """R^2 van een rechte-lijn-fit: hoe netjes de reeks een lijn volgt, ONGEACHT richting. [0,1]."""
    if len(vals) < 3:
        return None
    y = _oldnew(vals)
    if np.std(y) < EPS:
        return 0.0
    r = stats.linregress(np.arange(len(y)), y).rvalue
    return None if np.isnan(r) else float(r * r)


def acf_lag1(vals):
    """Autocorrelatie lag 1: + = momentum/persistentie, - = zigzag/mean-reversion. [-1,1]."""
    if len(vals) < 3:
        return None
    y = _oldnew(vals)
    if np.std(y) < EPS:
        return 0.0
    c = np.corrcoef(y[:-1], y[1:])
    v = c[0, 1]
    return None if np.isnan(v) else float(v)


def longest_monotone_run_fraction(vals):
    """Langste aaneengesloten stijgende reeks / (N-1) — de 'one clean push'. [0,1]."""
    if len(vals) < 3:
        return None
    d = np.diff(_oldnew(vals))
    best = cur = 0
    for up in (d > 0):
        cur = cur + 1 if up else 0
        best = max(best, cur)
    return float(best / len(d))


def theilsen_slope_normalized(vals):
    """Theil-Sen helling (mediaan van alle puntpaar-hellingen) / window-gemiddelde — outlier-robuust."""
    if len(vals) < 4:
        return None
    y = _oldnew(vals)
    m = float(np.mean(y))
    if abs(m) < EPS:
        return None
    s = stats.theilslopes(y, np.arange(len(y))).slope
    return None if np.isnan(s) else float(s / m)


def cumsum_position(vals):
    """Eindpositie van cumsum(x-mean) / max|cumsum|: +1 = momentum houdt aan tot T, -1 = al gedraaid."""
    if len(vals) < 3:
        return None
    y = _oldnew(vals)
    cs = np.cumsum(y - np.mean(y))
    denom = np.max(np.abs(cs))
    if denom < EPS:
        return 0.0
    return float(cs[-1] / denom)


def second_diff_max_norm(vals):
    """Max |tweede differentie| / std — scherpste acceleratie/knik in het window (change-point proxy)."""
    if len(vals) < 3:
        return None
    y = _oldnew(vals)
    sd = np.std(y)
    if sd < EPS:
        return 0.0
    return float(np.max(np.abs(np.diff(y, n=2))) / sd) if len(y) >= 3 else None


def sign_product_dir_level(vals):
    """Fractie stappen die STIJGEN EN boven de mediaan staan — richting x niveau. [0,1]."""
    if len(vals) < 3:
        return None
    y = _oldnew(vals)
    d = np.diff(y)
    med = np.median(y)
    return float(np.sum((d > 0) & (y[1:] > med)) / len(d))


def age_of_max_normalized(vals):
    """(N-1 - argmax)/(N-1): 0 = max op T (vers), 1 = max het oudste punt (uitgewerkt). [0,1]."""
    if len(vals) < 3:
        return None
    y = _oldnew(vals)
    return float((len(y) - 1 - int(np.argmax(y))) / (len(y) - 1))


def iqr_normalized(vals):
    """(Q75-Q25)/mediaan — robuuste relatieve spreiding (outlier-bestendig vs std/volatility)."""
    if len(vals) < 4:
        return None
    y = _oldnew(vals)
    q1, q3 = np.percentile(y, [25, 75])
    med = np.median(y)
    if abs(med) < EPS:
        return None
    return float((q3 - q1) / med)


def zero_crossing_rate_detrended(vals):
    """Tekenwisselingen van (x-mean) / (N-1): 0 = monotoon, 1 = alterneert elke stap. [0,1]."""
    if len(vals) < 3:
        return None
    xd = _oldnew(vals) - np.mean(vals)
    sg = np.sign(xd)
    sg[sg == 0] = 1
    return float(np.sum(np.diff(sg) != 0) / (len(xd) - 1))


def gini_coefficient(vals):
    """Concentratie/ongelijkheid [0,1]: ~0 = gelijk verdeeld over ticks, ~1 = een tick domineert.
    Zinvol op niet-negatieve grootheden (volume) — geeft None bij gemengde tekens."""
    if len(vals) < 3:
        return None
    a = np.sort(np.abs(_oldnew(vals)))
    n = len(a)
    s = np.sum(a)
    if s < EPS:
        return None
    return float(np.sum((2 * np.arange(1, n + 1) - n - 1) * a) / (n * s))


def path_efficiency(vals):
    """|laatste - eerste| / som(|stappen|): 1 = rechtlijnige beweging naar T, ~0 = veel heen-en-weer."""
    if len(vals) < 3:
        return None
    y = _oldnew(vals)
    tot = np.sum(np.abs(np.diff(y)))
    if tot < EPS:
        return None
    return float(abs(y[-1] - y[0]) / tot)


# registry: naam -> fn. Alle schaal-vrij => cross-coin bruikbaar (scale_safe=True).
EXTRA_CALCS = {
    "kendall_tau": kendall_tau,
    "linreg_r2": linreg_r2,
    "acf_lag1": acf_lag1,
    "longest_monotone_run_fraction": longest_monotone_run_fraction,
    "theilsen_slope_normalized": theilsen_slope_normalized,
    "cumsum_position": cumsum_position,
    "second_diff_max_norm": second_diff_max_norm,
    "sign_product_dir_level": sign_product_dir_level,
    "age_of_max_normalized": age_of_max_normalized,
    "iqr_normalized": iqr_normalized,
    "zero_crossing_rate_detrended": zero_crossing_rate_detrended,
    "gini_coefficient": gini_coefficient,
    "path_efficiency": path_efficiency,
}


def extra_metrics(vals):
    """Alle extra berekeningen over een newest-first window -> dict (None-waarden weggelaten)."""
    out = {}
    for name, fn in EXTRA_CALCS.items():
        try:
            v = fn(vals)
        except Exception:
            v = None
        if v is not None and not (isinstance(v, float) and (np.isnan(v) or np.isinf(v))):
            out[name] = v
    return out
