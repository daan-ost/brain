#!/usr/bin/env python3
"""
READ-ONLY parent-gate discovery: "de gemene deler".

Daan's method: take the first 3 consecutive good ticks (t0,t1,t2) of a rise, compute the FULL
indicator-metric set for each, and find what those rows share (the common denominator). A feature
is interesting only if it (a) is consistent within the group, (b) recurs across >=2 groups, and
(c) is RARE in the background + EXCLUDES the bad (no-labeled) ticks. That last contrast is what
separates a real signal from a trivially-common one ("all values positive").

Touches brain READ-ONLY (indicators + coin_moment_labels). Writes nothing.
"""
import bisect
import datetime as dt
from collections import defaultdict

import numpy as np

from db import brain
from calc import window_metrics

INDICATORS = ("volumeud", "obv-x-value", "vzo", "mfi", "phobos")
LOOKBACKS = (1, 2, 3, 5, 7, 10, 14, 20)


class Features:
    """Loads all indicator series for a coin; computes the full feature vector at any datetime."""

    def __init__(self, symbol):
        self.symbol = symbol
        self.series = {}
        with brain().cursor() as c:
            c.execute("SELECT indicator, datetime, value, price, brain_volume_found AS vf "
                      "FROM indicators WHERE trading_symbol_id=%s AND value IS NOT NULL "
                      "ORDER BY datetime", (symbol,))
            for r in c.fetchall():
                s = self.series.setdefault(r["indicator"], {"dt": [], "v": [], "p": [], "vf": []})
                s["dt"].append(r["datetime"]); s["v"].append(float(r["value"]))
                s["p"].append(float(r["price"]) if r["price"] is not None else None)
                s["vf"].append(int(r["vf"]))

    def vud_ticks(self, d0, d1):
        s = self.series["volumeud"]
        lo = bisect.bisect_left(s["dt"], d0); hi = bisect.bisect_left(s["dt"], d1)
        return [(s["dt"][i], s["p"][i], s["v"][i], s["vf"][i]) for i in range(lo, hi)]

    def _vals(self, ind, n, T):
        s = self.series.get(ind)
        if not s:
            return []
        i = bisect.bisect_right(s["dt"], T)
        lo = max(0, i - n)
        return s["v"][lo:i][::-1]

    def _prices(self, n, T):
        s = self.series["volumeud"]
        i = bisect.bisect_right(s["dt"], T)
        lo = max(0, i - n)
        return [p for p in s["p"][lo:i][::-1] if p is not None]

    def at(self, T):
        """Full feature vector at T: {f"{ind}|L{lb}|{metric}": value}."""
        feats = {}
        for ind in INDICATORS:
            for lb in LOOKBACKS:
                vals = self._vals(ind, lb, T)
                if len(vals) < 1:
                    continue
                m = window_metrics(vals)
                for k, v in m.items():
                    if v is not None and np.isfinite(v):
                        feats[f"{ind}|L{lb}|{k}"] = float(v)
        # price-action features (volumeud price series) — the G0 prijs-poort lever
        for lb in LOOKBACKS:
            pr = self._prices(lb, T)
            if len(pr) >= 1:
                m = window_metrics(pr)
                for k in ("diff_previous_value", "range_percentage", "diff_highest_value_period",
                          "diff_lowest_value_period", "consecutive_increases", "volatility"):
                    if k in m and np.isfinite(m[k]):
                        feats[f"price|L{lb}|{k}"] = float(m[k])
        return feats


def manual_labels(symbol, d0, d1):
    out = {}
    with brain().cursor() as c:
        c.execute("SELECT datetime, decision FROM coin_moment_labels WHERE trading_symbol_id=%s "
                  "AND datetime>=%s AND datetime<%s AND source='manual' AND rule=0 ORDER BY datetime",
                  (symbol, d0, d1))
        for r in c.fetchall():
            out[r["datetime"]] = r["decision"]
    return out


def first_n_yes_groups(symbol, d0, d1, n=3, gap_min=5):
    """Group the yes-moments into rises (gap>gap_min => new rise); return rises with >=n yes-ticks,
    each as its first n consecutive yes datetimes."""
    lab = manual_labels(symbol, d0, d1)
    yes = sorted(t for t, d in lab.items() if d == "yes")
    rises, cur = [], []
    for y in yes:
        if cur and (y - cur[-1]).total_seconds() > gap_min * 60:
            rises.append(cur); cur = []
        cur.append(y)
    if cur:
        rises.append(cur)
    return [g[:n] for g in rises if len(g) >= n], lab


def within_group_common(F, group, rel_tol=0.30):
    """For each feature, return its (lo,hi,mean,sign-agree) over the group ticks; keep features
    present at ALL ticks with sign agreement (the intuitive 'gemene deler')."""
    vecs = [F.at(t) for t in group]
    keys = set(vecs[0])
    for v in vecs[1:]:
        keys &= set(v)
    out = {}
    for k in keys:
        vals = [v[k] for v in vecs]
        lo, hi, mn = min(vals), max(vals), float(np.mean(vals))
        signs = {(1 if x > 1e-9 else (-1 if x < -1e-9 else 0)) for x in vals}
        sign_agree = len(signs) == 1
        out[k] = dict(lo=lo, hi=hi, mean=mn, sign_agree=sign_agree, vals=vals)
    return out


if __name__ == "__main__":
    import sys
    sym = int(sys.argv[1]) if len(sys.argv) > 1 else 2525
    d0 = dt.datetime(2025, 3, 1); d1 = dt.datetime(2025, 3, 2)
    F = Features(sym)
    groups, lab = first_n_yes_groups(sym, d0, d1, n=3)
    print(f"symbol {sym}: {len(groups)} groepen met >=3 opeenvolgende yes-ticks")
    for g in groups:
        print("  ", " , ".join(t.strftime("%H:%M:%S") for t in g))
