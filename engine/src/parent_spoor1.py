#!/usr/bin/env python3
"""
SPOOR 1: regime + greedy gestapelde EENZIJDIGE drempels (>x of <x, zoals 20-23) op een FIJNER
lookback-rooster en bredere metric-set. Eén rule (het oversold-regime) per munt, eerlijk holdout-
gevalideerd (selecteer op TRAIN, rapporteer op HOLDOUT). Alleen harde profit_loss; geen best_upside.
"""
import bisect
import datetime as dt
import random
import sys

import numpy as np
from sklearn.cluster import KMeans
from sklearn.preprocessing import StandardScaler

from calc import window_metrics, calc_percentage, _consecutive, _count_reversals
from parent_crossgroup import AsOf, OSC
from parent_fullperiod import rises
from parent_regimes import asof_arrays, project_and
from parent_eval import faithful_trades, trade_stats, fmt_cls, shape
from sell_engine import SellEngine

LB1 = (5, 10, 20)
METRICS1 = ("skewness", "range_percentage", "volatility", "standard_deviation",
            "consecutive_increases", "consecutive_decreases", "reversal_count",
            "diff_previous_value", "sum_average_positive_percentage",
            "sideways_upper", "sideways_lower", "diff_lowest_value_period")
INDS1 = ("volumeud", "phobos", "obv-x-value", "vzo", "mfi")


def lean_metrics(w):
    """Alleen de 12 METRICS1, snel (geen scipy, geen O(n²)) — formules identiek aan calc.window_metrics."""
    n = len(w)
    if n < 2:
        return {}
    a = np.asarray(w, dtype=float)
    first, last, lo, hi, sm = a[0], a[-1], a.min(), a.max(), a.sum()
    std = float(a.std(ddof=1)); sd0 = float(a.std()); mean = float(a.mean())
    skew = float((((a - mean) ** 3).mean()) / (sd0 ** 3)) if sd0 > 1e-12 else 0.0
    sp = 0.0
    for i in range(1, n):
        dp = calc_percentage(a[i], a[i - 1])
        if dp >= 0:
            sp += dp
    rest = a[1:]
    if len(rest) >= 3:
        mx, mn = rest.max(), rest.min(); filt = rest[(rest != mx) & (rest != mn)]
    else:
        filt = rest
    su = calc_percentage(first, float(filt.max())) if len(filt) else 0.0
    sl = calc_percentage(float(filt.min()), first) if len(filt) else 0.0
    return {
        "skewness": skew, "standard_deviation": std,
        "volatility": std / first if (first > 0 and std > 0) else 0.0,
        "range_percentage": (abs(hi - lo) / sm * 100) if (hi != lo and sm != 0) else 0.0,
        "diff_previous_value": calc_percentage(last, first),
        "consecutive_increases": _consecutive(list(w), "up"),
        "consecutive_decreases": _consecutive(list(w), "down"),
        "reversal_count": _count_reversals(list(w)),
        "diff_lowest_value_period": first - lo,
        "sum_average_positive_percentage": round(sp / (n - 1), 2),
        "sideways_upper": su, "sideways_lower": sl,
    }


def lshape(A, ind, lb, m, t):
    s = A.series[ind]; k = bisect.bisect_right(s["dt"], t); w = s["v"][max(0, k - lb):k][::-1]
    return lean_metrics(w).get(m) if len(w) >= 2 else None


def arrays1(A):
    cache = {}
    for ind in INDS1:
        s = A.series[ind]
        for lb in LB1:
            cols = {m: np.full(len(A.vdt), np.nan) for m in METRICS1}
            for n, T in enumerate(A.vdt):
                k = bisect.bisect_right(s["dt"], T); w = s["v"][max(0, k - lb):k][::-1]
                if len(w) >= 2:
                    mm = lean_metrics(w)
                    for m in METRICS1:
                        v = mm[m]
                        if np.isfinite(v):
                            cols[m][n] = v
            cache[(ind, lb)] = cols
    return cache


def run(symbol, name, K=3, seed=0, max_sub=4):
    random.seed(1); np.random.seed(seed)
    A = AsOf(symbol); eng = SellEngine(symbol)
    groups, _ = rises(symbol); groups.sort(key=lambda g: g[0])
    days = sorted({g[0].date() for g in groups}); split = days[len(days) // 2]
    vecs = [[float(np.mean([A.val(o, t) for t in g])) for o in OSC] for g in groups]
    lab = KMeans(n_clusters=K, n_init=10, random_state=seed).fit(StandardScaler().fit_transform(np.array(vecs))).labels_
    # oversold-regime = cluster met de laagste mfi-center
    cl = min(range(K), key=lambda c: np.mean([vecs[i][2] for i in range(len(vecs)) if lab[i] == c] or [99]))
    cols = asof_arrays(A); tot = len(A.vdt); idx_of = {T: i for i, T in enumerate(A.vdt)}

    bsamp = [A.vdt[i] for i in random.sample(range(tot), min(8000, tot)) if A.vdt[i].date() >= split]
    bt, _ = faithful_trades(eng, A, bsamp); base_mean = trade_stats(eng, bt, with_gap=False)["mean"]

    members = [i for i in range(len(groups)) if lab[i] == cl]
    tr = [i for i in members if groups[i][0].date() < split]
    bands = {o: (min(v), max(v)) for o in OSC
             for v in [[A.val(o, t) for i in tr for t in groups[i] if A.val(o, t) is not None]]}
    base_surv = project_and(cols, A.vdt, bands, k=3)
    base_trades, _ = faithful_trades(eng, A, base_surv)
    ctr = np.mean([vecs[i] for i in members], axis=0)
    print(f"\n################ {name} ({symbol}) — oversold-regime (center obv={ctr[0]:.0f} vzo={ctr[1]:.0f} mfi={ctr[2]:.0f} phobos={ctr[3]:.0f}) | holdout vanaf {split}")
    print(f"  baseline gem {base_mean:+.3f}%/trade | basis {len(base_trades)} trades ({100*len(base_trades)/tot:.2f}%)")
    if len(base_trades) < 40:
        print("  te weinig basis-trades."); return

    SH = arrays1(A)
    tr_ticks = [t for i in tr for t in groups[i]]
    bt_idx = np.array([idx_of[t["buy_dt"]] for t in base_trades])
    # vooraf-berekende numpy-arrays over de basis-trades (vectoriseert de greedy-scan)
    pls = np.array([t["pl"] for t in base_trades])
    dates = [t["buy_dt"] for t in base_trades]
    is_ho = np.array([d.date() >= split for d in dates]); is_tr = ~is_ho
    tr_windows = [(groups[i][0] - dt.timedelta(minutes=2), groups[i][-1] + dt.timedelta(minutes=2)) for i in tr]
    gid = np.full(len(base_trades), -1)
    for ti, d in enumerate(dates):
        if d.date() < split:
            for gi, (w0, w1) in enumerate(tr_windows):
                if w0 <= d <= w1:
                    gid[ti] = gi; break
    ng = len(tr)

    # eenzijdige kandidaten: per (ind,lb,metric) een ge (>=p10) en le (<=p90) drempel uit train-good-ticks
    cand = []
    for ind in INDS1:
        for lb in LB1:
            for m in METRICS1:
                gv = [lshape(A, ind, lb, m, t) for t in tr_ticks]
                gv = [x for x in gv if x is not None and np.isfinite(x)]
                if len(gv) < 8:
                    continue
                vals = SH[(ind, lb)][m][bt_idx]
                cand.append((ind, lb, m, "ge", float(np.percentile(gv, 10)), vals))
                cand.append((ind, lb, m, "le", float(np.percentile(gv, 90)), vals))

    chosen, mask = [], np.ones(len(base_trades), dtype=bool)
    for step in range(max_sub):
        cur_n = int(mask.sum()); best = None
        for (ind, lb, m, side, thr, vals) in cand:
            cm = mask & np.isfinite(vals) & ((vals >= thr) if side == "ge" else (vals <= thr))
            n = int(cm.sum())
            if n >= cur_n * 0.95 or n < 40:
                continue
            if int((cm & is_ho).sum()) < 15:
                continue
            if np.unique(gid[cm & (gid >= 0)]).size < 0.5 * ng:
                continue
            trp = pls[cm & is_tr]
            if trp.size == 0 or float(trp.mean()) < base_mean:
                continue
            if best is None or n < best[1]:
                best = ((ind, lb, m, side, thr), n, cm)
        if best is None:
            break
        chosen.append(best[0]); mask = best[2]
        if mask.sum() / tot < 0.0005:
            break

    if not chosen:
        print("  geen eenzijdige stapeling die indikt zonder groepjes/edge te verliezen."); return

    def passes(t):
        for (ind, lb, m, side, thr) in chosen:
            v = lshape(A, ind, lb, m, t)
            if v is None or (side == "ge" and v < thr) or (side == "le" and v > thr):
                return False
        return True
    ref_surv = [t for t in base_surv if passes(t)]
    rtrades, _ = faithful_trades(eng, A, ref_surv)
    ho = [t for t in rtrades if t["buy_dt"].date() >= split]
    st = trade_stats(eng, ho, with_gap=False)
    print(f"  RULE = oversold-regime + {len(chosen)} eenzijdige subregels:")
    for (ind, lb, m, side, thr) in chosen:
        print(f"     {ind}|L{lb}|{m} {'>=' if side == 'ge' else '<='} {thr:.3f}")
    edge = st["mean"] - base_mean
    tag = "  <== HOLDOUT-edge" if edge > 0.05 and st["n"] >= 20 else ""
    print(f"  HOLDOUT: {st['n']} trades ({100*st['n']/tot:.3f}% v.d. ticks) | Σ {st['sigma']:+.1f}% | gem {st['mean']:+.3f}%/trade (baseline {base_mean:+.3f}){tag}")
    print(f"  verdeling: {fmt_cls(st['cls'], st['n'])}")


if __name__ == "__main__":
    run(2525, "DOGEAI")
    run(244, "NOS")
