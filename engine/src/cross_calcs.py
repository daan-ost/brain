"""
cross_calcs — CROSS-KANAAL berekeningen (volume x prijs) + rang-features, bovenop de univariate
extra_calcs. Uit de feature-research-criticus (2026-06-23): de 13 add_now zijn allemaal univariate;
de rijkste niet-verkende as is prijs x volume samen + rang-positie.

Interface = die van calc.subrule_value: elke functie neemt (vals, prices) newest-first, beide van het
volumeud-window (vals = volume, prices = prijs), en geeft EEN schaal-vrije scalar of None. Zo passen ze
direct in de engine (een subregel met indicator='volumeud' levert vals=volume + prices=prijs) EN in de
meting. Allemaal rang/ratio-gebaseerd -> cross-coin bruikbaar.
"""
import numpy as np
from scipy import stats

EPS = 1e-9


def _pair(vals, prices):
    """Gelijk-lengte numpy-paren (oud->nieuw) van volume + prijs; None als te kort/ongelijk."""
    n = min(len(vals), len(prices))
    if n < 4:
        return None, None
    v = np.asarray(vals[:n][::-1], float)
    p = np.asarray(prices[:n][::-1], float)
    return v, p


def vol_price_rank_corr(vals, prices):
    """Spearman rang-correlatie volume<->prijs over het window. + = prijs stijgt MET volume (echte
    koopdruk), - = prijs beweegt tegen het volume in. Schaal-vrij (rang). [-1,1]. De criticus' #1."""
    v, p = _pair(vals, prices)
    if v is None:
        return None
    if np.std(v) < EPS or np.std(p) < EPS:
        return 0.0
    r, _ = stats.spearmanr(v, p)
    return None if r is None or np.isnan(r) else float(r)


def price_rank_in_window(vals, prices):
    """Percentiel-rang van de HUIDIGE prijs in het prijs-window: 1 = hoogste (top/laat), 0 = laagste
    (dip). Robuuste pos-in-range (rang i.p.v. min/max -> ongevoelig voor 1 outlier-tick)."""
    if len(prices) < 3:
        return None
    cur = prices[0]
    rest = prices
    below = sum(1 for x in rest if x < cur)
    return float(below / (len(rest) - 1))


def updown_asymmetry(vals, prices):
    """(som stijg-stappen - som daal-stappen) / som |stappen| over de prijs. [-1,1]: +1 = alleen omhoog,
    -1 = alleen omlaag, 0 = symmetrisch. Netto-richting genormaliseerd op padlengte (schaal-vrij)."""
    _, p = _pair(vals, prices)
    if p is None:
        return None
    d = np.diff(p)
    up = float(np.sum(d[d > 0]))
    down = float(-np.sum(d[d < 0]))
    tot = up + down
    return None if tot < EPS else (up - down) / tot


def vol_concentration_at_high(vals, prices):
    """Zat het grootste volume op de hoogste prijs? volume-aandeel van de top-prijs-tick t.o.v. totaal
    volume. ~hoog = de spike kwam op de piek (laat/uitgeput), ~laag = volume vooraf (opbouw). [0,1]."""
    v, p = _pair(vals, prices)
    if v is None or np.sum(np.abs(v)) < EPS:
        return None
    i_hi = int(np.argmax(p))
    return float(abs(v[i_hi]) / np.sum(np.abs(v)))


CROSS_CALCS = {
    "vol_price_rank_corr": vol_price_rank_corr,
    "price_rank_in_window": price_rank_in_window,
    "updown_asymmetry": updown_asymmetry,
    "vol_concentration_at_high": vol_concentration_at_high,
}


def cross_metrics(vals, prices):
    """Alle cross-kanaal berekeningen over een volumeud-window (vals=volume, prices=prijs)."""
    out = {}
    for name, fn in CROSS_CALCS.items():
        try:
            x = fn(vals, prices)
        except Exception:
            x = None
        if x is not None and not (isinstance(x, float) and (np.isnan(x) or np.isinf(x))):
            out[name] = x
    return out
