#!/usr/bin/env python3
"""
READ-ONLY: evalueer een kandidaat-rule in Daans rapportagevorm, ALLEEN op harde sell-engine-cijfers.
GEEN best_upside / potentiële 60-min-stijging — die zit nergens meer in. We meten:
  - trouwe single-position dedup via de ECHTE sell-engine (open_until = selling_date, zoals
    persist_to_brain.py:192) → echte trades, vergelijkbaar met 20-23;
  - gerealiseerde profit_loss: Σ, gemiddelde, verdeling goed≥3% / middel 0-3% / slecht<0%;
  - selectiviteit = trades / aantal volumeud-ticks;
  - best-sell-gap = beste HAALBARE exit (best_sell_in_window, begrensd tot de volgende koop) − onze
    gerealiseerde exit = hoeveel de sell-engine liet liggen (hard getal, geen best_upside);
  - baseline = dezelfde trouwe dedup op willekeurige ticks.
"""
import bisect
import random
from collections import Counter

import numpy as np

from sell_engine import SellEngine
from parent_crossgroup import AsOf, OSC
from parent_periodbase import regime_split
from parent_regimes import asof_arrays, project_and
from parent_fullperiod import rises
from calc import window_metrics


def cls_pl(pl):
    return "goed" if pl >= 3 else ("slecht" if pl < 0 else "middel")


def shape(A, ind, L, metric, t):
    s = A.series[ind]; i = bisect.bisect_right(s["dt"], t); w = s["v"][max(0, i - L):i][::-1]
    return window_metrics(w).get(metric) if len(w) >= 2 else None


PL_CAP = 200.0   # |profit_loss| > 200% in 1u = kapotte prijs-tick (data-artefact), geen echte trade


def faithful_trades(eng, A, survivors):
    """Single-position dedup via de ECHTE sell-engine: een fire opent een positie, sluit op de
    werkelijke selling_date; fires daarbinnen zijn shadows (geen trade). Identiek aan persist_to_brain.
    Geeft (trades, n_artefact): trades met |pl|>PL_CAP of ongeldige koopprijs zijn data-artefacten."""
    trades, open_until, art = [], None, 0
    for t in sorted(survivors):
        if open_until is not None and t <= open_until:
            continue
        i = bisect.bisect_right(A.vdt, t)
        if i == 0:
            continue
        buy = A.vpx[i - 1]
        if buy is None or buy <= 0:
            continue
        r = eng.sell(t, buy, 20)
        if r is None:
            open_until = t
            continue
        open_until = r["selling_date"]
        if abs(r["profit_loss"]) > PL_CAP:        # kapotte prijs-tick → geen echte trade
            art += 1
            continue
        trades.append(dict(buy_dt=t, buy=buy, pl=r["profit_loss"], sell_dt=r["selling_date"]))
    return trades, art


def trade_stats(eng, trades, with_gap=True):
    pls = np.array([t["pl"] for t in trades]) if trades else np.array([0.0])
    cls = Counter(cls_pl(p) for p in [t["pl"] for t in trades])
    gaps = []
    if with_gap:
        for k, tr in enumerate(trades):
            until = trades[k + 1]["buy_dt"] if k + 1 < len(trades) else None
            bs = eng.best_sell_in_window(tr["buy_dt"], tr["buy"], until_dt=until)
            if bs:
                gaps.append(bs["profit_pct"] - tr["pl"])
    return dict(n=len(trades), sigma=float(pls.sum()), mean=float(pls.mean()), cls=cls,
               gap=float(np.mean(gaps)) if gaps else 0.0)


def fmt_cls(c, n):
    n = max(n, 1)
    return (f"goed {c['goed']} ({100*c['goed']//n}%) / middel {c['middel']} ({100*c['middel']//n}%) / "
            f"slecht {c['slecht']} ({100*c['slecht']//n}%)")


def evaluate(symbol, name, cl, ref, rule_label):
    A = AsOf(symbol); eng = SellEngine(symbol)
    groups, lab = regime_split(A)
    idx = [i for i in range(len(groups)) if lab[i] == cl]
    dates = sorted({groups[i][0].date() for i in idx}); split = dates[len(dates) // 2]
    tr = [i for i in idx if groups[i][0].date() < split]
    bands = {o: (min(v), max(v)) for o in OSC
             for v in [[A.val(o, t) for i in tr for t in groups[i] if A.val(o, t) is not None]]}
    cols = asof_arrays(A)
    surv = project_and(cols, A.vdt, bands, k=3)
    ind, L, metric, lo, hi = ref
    surv = [t for t in surv if (lambda v: v is not None and lo <= v <= hi)(shape(A, ind, L, metric, t))]

    trades, art = faithful_trades(eng, A, surv)
    st = trade_stats(eng, trades)
    tot = len(A.vdt)

    allg, _ = rises(symbol); sset = sorted(surv); hit = 0
    import datetime as _dt
    for g in allg:
        j = bisect.bisect_left(sset, g[0] - _dt.timedelta(minutes=2))
        if j < len(sset) and sset[j] <= g[-1] + _dt.timedelta(minutes=2):
            hit += 1

    random.seed(1)
    bsurv = [A.vdt[i] for i in random.sample(range(tot), min(4000, tot))]
    btrades, _ = faithful_trades(eng, A, bsurv)
    bst = trade_stats(eng, btrades)

    print(f"\n========== {name}: {rule_label} ==========")
    print(f"  regime-band: " + "  ".join(f"{o.split('-')[0]}[{a:.0f},{b:.0f}]" for o, (a, b) in bands.items()))
    print(f"  parent-cascade: 3 opvolgende ticks in band + {ind}|L{L}|{metric}∈[{lo:.2f},{hi:.2f}]")
    print(f"  >> raakt {hit}/{len(allg)} promising groepjes")
    print(f"  >> {st['n']} trades ({100*st['n']/tot:.2f}% v.d. ticks) | Σ {st['sigma']:+.1f}% | gem {st['mean']:+.3f}%/trade")
    print(f"  >> verdeling: {fmt_cls(st['cls'], st['n'])}")
    print(f"  >> best-sell-gap: {st['gap']:+.2f}%/trade liet de sell-engine liggen (hard, geen best_upside)")
    print(f"  >> random-baseline: {bst['n']} trades | gem {bst['mean']:+.3f}%/trade | {fmt_cls(bst['cls'], bst['n'])}")


if __name__ == "__main__":
    evaluate(2525, "DOGEAI", 1, ("volumeud", 20, "skewness", 0.878, 1.366),
             "rule 30 (oversold-regime + volume-skew)")
    evaluate(244, "NOS", 2, ("phobos", 10, "skewness", 0.578, 1.175),
             "rule 31 (momentum-regime + phobos-skew)")
