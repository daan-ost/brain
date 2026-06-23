#!/usr/bin/env python3
"""
feature_quality.py — meet per BEREKENING (indicator × window-metric × lookback) hoe goed hij goede van
slechte trades SCHEIDT, op TWEE bronnen, en legt dat vast in brain.feature_quality. De groeiende
kwaliteits-/toepasbaarheids-database: meet 'm opnieuw als er coins bijkomen en de cijfers verscherpen.

Twee bronnen (Daans wens — smal + breed):
  - 'rule30' / 'rule31' / ...  = de EXECUTED trades van die rule (smal, hard, weinig winnaars).
  - 'promising'                = ALLE promising momenten uit coin_moment_sells (breed; duizenden
                                 winnaars+verliezers -> intrinsieke bruikbaarheid van de berekening).

Berekeningen = de 31 uit calc.window_metrics + de 13 nieuwe uit extra_calcs (research 2026-06-23).
Maten per berekening:
  - auc  = Mann-Whitney kans dat een goede een hogere waarde heeft dan een slechte (0.5 = niets).
  - separation = |2·auc−1| (0..1) — de kern-kwaliteit, schaal-vrij, rule-loos.
  - perm_p = analytische toeval-p van diezelfde toets (lager = echtere scheiding).
  - best_side + bad_drop_pct/good_keep_pct = praktische kracht op een tijd-holdout (drempel uit vroege
    70% op behoud ~98% goede, gemeten op late 30%): hoeveel % verliezers vallen weg.
  - scale_safe = cross-coin bruikbaar (volumeud LEVEL-metrics = niet).

READ-ONLY op alle bestaande tabellen; schrijft ALLEEN naar feature_quality (eigen tabel).
Gebruik:  ../.venv/bin/python -u feature_quality.py promising 2525 244
          ../.venv/bin/python -u feature_quality.py rule 30 31
"""
import sys

import numpy as np
from scipy import stats

from db import brain
from rule_engine import RuleEngine
from calc import window_metrics, WINDOW_METRIC_KEYS
from extra_calcs import extra_metrics, EXTRA_CALCS
from cross_calcs import cross_metrics, CROSS_CALCS
from opt_lib import GOOD_PL, BAD_PL, scale_unsafe

COINS = {2525: "DOGEAI", 244: "NOS"}
INDICATORS = ["volumeud", "relvol", "phobos", "vzo", "mfi", "obv-x-value", "price", "volprice"]
LOOKBACKS = [5, 10, 20]
ALL_METRICS = list(WINDOW_METRIC_KEYS) + list(EXTRA_CALCS.keys()) + list(CROSS_CALCS.keys())
TRAIN_FRAC = 0.70
MIN_PER_CLASS = 20            # minder dan dit goed of slecht -> geen betrouwbare maat (sla key over)


def cls(pl):
    return "goed" if pl >= GOOD_PL else ("slecht" if pl < BAD_PL else "middel")


def metrics_at(eng, ind, lb, T):
    """31 window-metrics + extra (univariate), of cross-kanaal (volprice = volume x prijs), leak-vrij."""
    if ind == "volprice":
        vol, prices = eng._vals("volumeud", lb, T)
        if len(prices) < 4 or len(vol) < 4:
            return None
        return cross_metrics([float(x) for x in vol], [float(x) for x in prices])
    if ind == "price":
        _, vals = eng._vals("volumeud", lb, T)
    else:
        vals, _ = eng._vals(ind, lb, T)
    vals = [float(x) for x in vals]
    if len(vals) < 2:
        return None
    m = window_metrics(vals)
    m.update(extra_metrics(vals))
    return m


def load_moments(conn, source, sym):
    with conn.cursor() as c:
        if source == "promising":
            c.execute("SELECT datetime, profit_loss FROM coin_moment_sells WHERE trading_symbol_id=%s "
                      "AND profit_loss IS NOT NULL ORDER BY datetime", (sym,))
        else:
            rule = int(source.replace("rule", ""))
            c.execute("SELECT datetime, profit_loss FROM coin_fires WHERE trading_symbol_id=%s AND rule=%s "
                      "AND is_executed=1 AND profit_loss IS NOT NULL ORDER BY datetime", (sym, rule))
        return [(r["datetime"], float(r["profit_loss"])) for r in c.fetchall()]


def auc_sep(goed, slecht):
    """Mann-Whitney AUC (kans goed-waarde > slecht-waarde) + tweezijdige p. None bij te weinig data."""
    if len(goed) < MIN_PER_CLASS or len(slecht) < MIN_PER_CLASS:
        return None
    try:
        u, p = stats.mannwhitneyu(goed, slecht, alternative="two-sided")
    except ValueError:
        return None
    auc = u / (len(goed) * len(slecht))
    return auc, p


def holdout_drop(vals, klass, side):
    """Drempel uit vroege 70% goede op behoud ~98% (percentiel), gemeten op late 30%.
    Return (bad_drop_pct, good_keep_pct, n_test_slecht) of None."""
    n = len(vals)
    s = int(n * TRAIN_FRAC)
    gtr = [vals[i] for i in range(s) if klass[i] == "goed" and vals[i] is not None]
    te_bad = [vals[i] for i in range(s, n) if klass[i] == "slecht" and vals[i] is not None]
    te_good = [vals[i] for i in range(s, n) if klass[i] == "goed" and vals[i] is not None]
    if len(gtr) < MIN_PER_CLASS or len(te_bad) < MIN_PER_CLASS or len(te_good) < 10:
        return None
    if side == "b_min":
        thr = float(np.percentile(gtr, 2))          # 98% goede liggen >= thr
        bad_drop = sum(1 for v in te_bad if v < thr)
        good_keep = sum(1 for v in te_good if v >= thr)
    else:
        thr = float(np.percentile(gtr, 98))
        bad_drop = sum(1 for v in te_bad if v > thr)
        good_keep = sum(1 for v in te_good if v <= thr)
    return 100.0 * bad_drop / len(te_bad), 100.0 * good_keep / len(te_good), len(te_bad)


def measure(conn, source, sym):
    name = COINS[sym]
    moments = load_moments(conn, source, sym)
    klass = [cls(pl) for _, pl in moments]
    ng, nb = klass.count("goed"), klass.count("slecht")
    print(f"  {name} [{source}]: {len(moments)} momenten = {ng} goed / {nb} slecht", flush=True)
    if ng < MIN_PER_CLASS or nb < MIN_PER_CLASS:
        print("    -> te weinig goed/slecht; overslaan"); return 0
    # feature-cache: per (ind,metric,lb) een waarde-array over de momenten (tijdsvolgorde)
    store = {}
    eng = RuleEngine(sym)
    for ti, (T, _pl) in enumerate(moments):
        for ind in INDICATORS:
            for lb in LOOKBACKS:
                m = metrics_at(eng, ind, lb, T)
                if m is None:
                    continue
                for metric in ALL_METRICS:
                    v = m.get(metric)
                    if v is None:
                        continue
                    store.setdefault((ind, metric, lb), [None] * len(moments))[ti] = v
    eng.close()

    rows = []
    for (ind, metric, lb), vals in store.items():
        goed = [vals[i] for i in range(len(vals)) if klass[i] == "goed" and vals[i] is not None]
        slecht = [vals[i] for i in range(len(vals)) if klass[i] == "slecht" and vals[i] is not None]
        a = auc_sep(goed, slecht)
        if a is None:
            continue
        auc, p = a
        sep = abs(2 * auc - 1)
        side = "b_min" if auc >= 0.5 else "b_max"     # goede hoger -> slecht zit laag -> drop onder
        hd = holdout_drop(vals, klass, side)
        bad_drop = good_keep = wf_med = None
        if hd:
            bad_drop, good_keep, n_test_bad = hd
            wf_med = round(bad_drop / 100.0 * n_test_bad, 1)
        unsafe = scale_unsafe(ind, metric)            # extra_calcs zijn nooit unsafe (schaal-vrij)
        rows.append((sym, source, ind, metric, lb, len(goed) + len(slecht), len(goed), len(slecht),
                     round(auc, 4), round(sep, 4), side,
                     round(bad_drop, 1) if bad_drop is not None else None,
                     round(good_keep, 1) if good_keep is not None else None,
                     None, None, wf_med, float(p), 0 if unsafe else 1))
    with conn.cursor() as c:
        c.executemany(
            "INSERT INTO feature_quality (trading_symbol_id, source, indicator, metric, lookback, n, "
            "n_goed, n_slecht, auc, separation, best_side, bad_drop_pct, good_keep_pct, wf_clean, "
            "wf_valid, wf_med_drop, perm_p, scale_safe) VALUES "
            "(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s) "
            "ON DUPLICATE KEY UPDATE n=VALUES(n), n_goed=VALUES(n_goed), n_slecht=VALUES(n_slecht), "
            "auc=VALUES(auc), separation=VALUES(separation), best_side=VALUES(best_side), "
            "bad_drop_pct=VALUES(bad_drop_pct), good_keep_pct=VALUES(good_keep_pct), "
            "wf_med_drop=VALUES(wf_med_drop), perm_p=VALUES(perm_p), scale_safe=VALUES(scale_safe), "
            "measured_at=CURRENT_TIMESTAMP", rows)
    conn.commit()
    print(f"    -> {len(rows)} berekening-cellen weggeschreven", flush=True)
    return len(rows)


def main():
    args = sys.argv[1:]
    if not args:
        print("gebruik: feature_quality.py promising [coins...]  |  feature_quality.py rule 30 31 [...]")
        return
    conn = brain()
    if args[0] == "rule":
        rules = [f"rule{r}" for r in args[1:]] or ["rule30", "rule31"]
        sources, syms = rules, list(COINS)
    else:
        sources = ["promising"]
        syms = [int(a) for a in args[1:]] if len(args) > 1 else list(COINS)
    total = 0
    for source in sources:
        for sym in syms:
            total += measure(conn, source, sym)
    conn.close()
    print(f"\nklaar: {total} cellen in brain.feature_quality")


if __name__ == "__main__":
    main()
