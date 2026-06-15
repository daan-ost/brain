"""
opt_lib — the VERIFIED numeric core for rule-set optimisation (read-only analysis).

Single source of truth for: loading executed trades + the indicator_metrics cache (duckdb over
Parquet), placing a subrule threshold at the BAD EDGE (brain-rule-tuning principle 2), and
validating a candidate OUT-OF-SAMPLE — both a per-coin time split (train 70 / test 30) and a
CROSS-COIN split (derive on DOGEAI, test on NOS and vice versa).

Everything here is descriptive/read-only. It creates and changes NOTHING. The rq_*.py scripts and
the workflow's verify agents call these functions so the math is identical everywhere.

GOOD  = executed trade, best_upside >= 3%   (the opportunity was real)
BAD   = executed trade, best_upside <  0.5% (slecht — to prevent)
MIDDEL= 0.5 .. 3%   (grey zone, ignored in good/bad separation)
"""
import glob
import os

import duckdb
import numpy as np
import pandas as pd

from db import brain
from calc import WINDOW_METRIC_KEYS

GOOD_UPSIDE = 3.0
BAD_UPSIDE = 0.5
DOGEAI = 2525
NOS = 244

HERE = os.path.dirname(os.path.abspath(__file__))
METRICS_GLOB = os.path.join(HERE, "..", "data", "metrics", "indicator_metrics_*.parquet")
CALC_COLS = list(WINDOW_METRIC_KEYS)


def _cls(u):
    return "goed" if u >= GOOD_UPSIDE else ("slecht" if u < BAD_UPSIDE else "middel")


def load_trades():
    """Executed trades (both coins) with best_upside class. One row per trade."""
    if not glob.glob(METRICS_GLOB):
        raise SystemExit("no indicator_metrics parquet — run build_indicator_metrics.py first")
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT trading_symbol_id AS sym, datetime, rule, best_upside "
                  "FROM coin_fires WHERE is_executed=1 AND best_upside IS NOT NULL")
        df = pd.DataFrame(c.fetchall())
    conn.close()
    df["cls"] = df["best_upside"].apply(_cls)
    # per-coin time split: first 70% train, last 30% test
    df["split"] = "train"
    for sym, g in df.groupby("sym"):
        cut = g["datetime"].sort_values().iloc[int(len(g) * 0.7)]
        df.loc[(df["sym"] == sym) & (df["datetime"] > cut), "split"] = "test"
    return df


def load_long(trades=None):
    """Long table: one row per (trade x indicator x lookback x calc) -> value, joined to the
    indicator_metrics Parquet cache. NaN values dropped. Columns:
    sym, datetime, rule, cls, split, best_upside, indicator, lookback, calc, value."""
    if trades is None:
        trades = load_trades()
    con = duckdb.connect()
    con.register("trades", trades)
    wide = con.execute(f"""
        SELECT t.sym, t.datetime, t.rule, t.cls, t.split, t.best_upside,
               m.indicator, m.lookback, {','.join('m.' + c for c in CALC_COLS)}
        FROM read_parquet('{METRICS_GLOB}') m
        JOIN trades t ON m.trading_symbol_id=t.sym AND m.datetime=t.datetime
    """).df()
    con.close()
    long = wide.melt(
        id_vars=["sym", "datetime", "rule", "cls", "split", "best_upside", "indicator", "lookback"],
        value_vars=CALC_COLS, var_name="calc", value_name="value").dropna(subset=["value"])
    return long


# ---------------------------------------------------------------------------
# Principle 2 — threshold at the BAD EDGE (in the gap), keeping ALL good by construction.
# ---------------------------------------------------------------------------
def bad_edge_conditions(good_vals, bad_vals):
    """Given good/bad value arrays for ONE feature, return candidate single conditions placed at
    the bad edge. A lower bound keeps value >= threshold; an upper bound keeps value <= threshold.

    lower: threshold = highest bad strictly below the good band (good.min). Drops bad < threshold,
           keeps the single borderline bad (buffer) AND all good (all good >= good.min > threshold).
    upper: threshold = lowest bad strictly above the good band (good.max). Symmetric.
    Returns list of dicts {bound, threshold, drop_insample}."""
    good = np.asarray(good_vals, dtype=float)
    bad = np.asarray(bad_vals, dtype=float)
    out = []
    if len(good) == 0 or len(bad) == 0:
        return out
    gmin, gmax = good.min(), good.max()
    bb = bad[bad < gmin]
    ba = bad[bad > gmax]
    if len(bb):
        thr = float(bb.max())
        drop = int((bad < thr).sum())
        if drop > 0:
            out.append({"bound": "lower", "threshold": round(thr, 5), "drop_insample": drop})
    if len(ba):
        thr = float(ba.min())
        drop = int((bad > thr).sum())
        if drop > 0:
            out.append({"bound": "upper", "threshold": round(thr, 5), "drop_insample": drop})
    return out


def _passes(values, bound, threshold):
    v = np.asarray(values, dtype=float)
    return (v >= threshold) if bound == "lower" else (v <= threshold)


def oos_metrics(tr_good, tr_bad, te_good, te_bad, bound, threshold):
    """Out-of-sample scoring of a single condition whose threshold is given (already derived on a
    train slice). Returns good_keep (fraction of held-out GOOD that survives — must be ~1.0) and
    bad_drop (fraction of held-out BAD dropped — the benefit)."""
    te_good = np.asarray(te_good, dtype=float)
    te_bad = np.asarray(te_bad, dtype=float)
    good_keep = float(_passes(te_good, bound, threshold).mean()) if len(te_good) else float("nan")
    bad_drop = float((~_passes(te_bad, bound, threshold)).mean()) if len(te_bad) else float("nan")
    return {"good_keep": round(good_keep, 3), "bad_drop": round(bad_drop, 3),
            "n_te_good": int(len(te_good)), "n_te_bad": int(len(te_bad))}


# ---------------------------------------------------------------------------
# Single-condition sweep over the full grid for one rule (pooled over both coins by default).
# ---------------------------------------------------------------------------
def sweep_single(long, rule, min_good=5, min_bad=5):
    """Every (indicator, lookback, calc) for `rule`: place a bad-edge condition (in-sample on the
    pooled good/bad) and report how many bad it drops at zero good loss. Descriptive — feed the
    survivors to validate_* for the out-of-sample gate."""
    sub = long[long["rule"] == rule]
    rows = []
    for (ind, lb, calc), g in sub.groupby(["indicator", "lookback", "calc"]):
        good = g[g["cls"] == "goed"]["value"]
        bad = g[g["cls"] == "slecht"]["value"]
        if len(good) < min_good or len(bad) < min_bad:
            continue
        for cond in bad_edge_conditions(good, bad):
            rows.append({"rule": rule, "indicator": ind, "lookback": int(lb), "calc": calc,
                         "n_good": int(len(good)), "n_bad": int(len(bad)), **cond})
    return pd.DataFrame(rows).sort_values("drop_insample", ascending=False) if rows else pd.DataFrame()


def validate_timesplit(long, rule, ind, lb, calc, bound,
                       min_tr_good=5, min_tr_bad=5, min_te_good=3, min_te_bad=3):
    """Per-coin TIME split (train 70 / test 30, pooled): derive the bad-edge threshold on train,
    score on test. Returns None if too little data. The OOS safety gate from validate_subrules.py."""
    g = long[(long["rule"] == rule) & (long["indicator"] == ind) &
             (long["lookback"] == lb) & (long["calc"] == calc)]
    tr_good = g[(g["split"] == "train") & (g["cls"] == "goed")]["value"]
    tr_bad = g[(g["split"] == "train") & (g["cls"] == "slecht")]["value"]
    te_good = g[(g["split"] == "test") & (g["cls"] == "goed")]["value"]
    te_bad = g[(g["split"] == "test") & (g["cls"] == "slecht")]["value"]
    if (len(tr_good) < min_tr_good or len(tr_bad) < min_tr_bad
            or len(te_good) < min_te_good or len(te_bad) < min_te_bad):
        return None
    conds = {c["bound"]: c for c in bad_edge_conditions(tr_good, tr_bad)}
    if bound not in conds:
        return None
    thr = conds[bound]["threshold"]
    m = oos_metrics(tr_good, tr_bad, te_good, te_bad, bound, thr)
    return {"split": "time", "threshold": thr, "train_drop": conds[bound]["drop_insample"], **m}


def validate_crosscoin(long, rule, ind, lb, calc, bound, train_sym, test_sym,
                       min_tr_good=5, min_tr_bad=5, min_te_good=3, min_te_bad=3):
    """CROSS-COIN split: derive the bad-edge threshold on ALL of train_sym, score on ALL of
    test_sym. The strongest robustness test — does a band fit on DOGEAI hold on NOS (and back)?"""
    g = long[(long["rule"] == rule) & (long["indicator"] == ind) &
             (long["lookback"] == lb) & (long["calc"] == calc)]
    tr_good = g[(g["sym"] == train_sym) & (g["cls"] == "goed")]["value"]
    tr_bad = g[(g["sym"] == train_sym) & (g["cls"] == "slecht")]["value"]
    te_good = g[(g["sym"] == test_sym) & (g["cls"] == "goed")]["value"]
    te_bad = g[(g["sym"] == test_sym) & (g["cls"] == "slecht")]["value"]
    if (len(tr_good) < min_tr_good or len(tr_bad) < min_tr_bad
            or len(te_good) < min_te_good or len(te_bad) < min_te_bad):
        return None
    conds = {c["bound"]: c for c in bad_edge_conditions(tr_good, tr_bad)}
    if bound not in conds:
        return None
    thr = conds[bound]["threshold"]
    m = oos_metrics(tr_good, tr_bad, te_good, te_bad, bound, thr)
    return {"split": f"{train_sym}->{test_sym}", "threshold": thr,
            "train_drop": conds[bound]["drop_insample"], **m}


def full_validation(long, rule, ind, lb, calc, bound):
    """Run ALL out-of-sample splits for one candidate: time split + both cross-coin directions.
    A candidate is SAFE only if good_keep ~ 1.0 on every split that has enough data."""
    res = {}
    t = validate_timesplit(long, rule, ind, lb, calc, bound)
    if t:
        res["time"] = t
    for tr, te in ((DOGEAI, NOS), (NOS, DOGEAI)):
        cc = validate_crosscoin(long, rule, ind, lb, calc, bound, tr, te)
        if cc:
            res[f"{tr}->{te}"] = cc
    return res


def load_all_fires():
    """ALL fires (executed AND shadow), both coins, with rule + best_upside class. Shadows matter
    for redundancy: single-position dedup means only one rule executes at a time, so executed-only
    overlap is always ~0 (an artefact). A shadow records that another rule ALSO fired there."""
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT trading_symbol_id AS sym, datetime, rule, is_executed, best_upside "
                  "FROM coin_fires WHERE best_upside IS NOT NULL")
        df = pd.DataFrame(c.fetchall())
    conn.close()
    df["cls"] = df["best_upside"].apply(_cls)
    return df


def rule_overlap(tol_minutes=3, fires=None):
    """RQ3 — redundancy. Population per rule X = its EXECUTED GOOD trades. Coverage by rule Y = Y
    has ANY fire (executed or shadow) within +/- tol_minutes (same coin) — i.e. if X were removed,
    Y would have fired there too. union = ANY other rule fires there. A rule whose executed-good
    trades are ~fully covered by others is a drop candidate. Uses all fires (shadows included)."""
    if fires is None:
        fires = load_all_fires()
    tol = pd.Timedelta(minutes=tol_minutes)
    rules = sorted(fires["rule"].unique())
    exec_good = fires[(fires["is_executed"] == 1) & (fires["cls"] == "goed")]
    pair_rows, union_rows = [], []
    for X in rules:
        gx = exec_good[exec_good["rule"] == X]
        if gx.empty:
            continue
        covered_union = 0
        for _, r in gx.iterrows():
            others = fires[(fires["rule"] != X) & (fires["sym"] == r["sym"]) &
                           (fires["datetime"] >= r["datetime"] - tol) &
                           (fires["datetime"] <= r["datetime"] + tol)]
            if not others.empty:
                covered_union += 1
        union_rows.append({"rule": X, "n_exec_good": len(gx), "covered_by_others": covered_union,
                           "pct_covered": round(covered_union / len(gx) * 100, 1)})
        for Y in rules:
            if Y == X:
                continue
            fy = fires[fires["rule"] == Y]
            cov = 0
            for _, r in gx.iterrows():
                m = fy[(fy["sym"] == r["sym"]) & (fy["datetime"] >= r["datetime"] - tol) &
                       (fy["datetime"] <= r["datetime"] + tol)]
                if not m.empty:
                    cov += 1
            pair_rows.append({"X": X, "Y": Y, "n_exec_good_X": len(gx), "covered_by_Y": cov,
                              "pct": round(cov / len(gx) * 100, 1)})
    return pd.DataFrame(pair_rows), pd.DataFrame(union_rows)


if __name__ == "__main__":
    # 1) unit-test the bad-edge placement against the brain-rule-tuning worked example:
    #    good lower bound -20; the highest bad below it is -30 -> lower bound at -30 (not -20),
    #    dropping the bad strictly below -30, keeping the -30 buffer bad and all good.
    good = [-20, -10, 0, 5, 12, 30]
    bad = [-45, -30, 8]                          # highest bad below -20 is -30; -45 dropped, -30 kept
    conds = bad_edge_conditions(good, bad)
    low = [c for c in conds if c["bound"] == "lower"]
    assert low and abs(low[0]["threshold"] - (-30)) < 1e-9 and low[0]["drop_insample"] == 1, conds
    # upper: good max 30; lowest bad above none -> no upper condition
    assert not [c for c in conds if c["bound"] == "upper"], conds
    print("unit-test bad_edge_conditions: PASS", low[0])

    # 2) smoke-test the cache join + sweep on real data
    long = load_long()
    print("trades loaded:", long[["sym", "datetime"]].drop_duplicates().shape[0],
          "| executed good/bad pooled:",
          long.drop_duplicates(["sym", "datetime"]).cls.value_counts().to_dict())
    for rule in (20, 21, 22, 23):
        sw = sweep_single(long, rule)
        n = 0 if sw.empty else len(sw)
        top = "" if sw.empty else (f" | top: {sw.iloc[0]['indicator']}/{sw.iloc[0]['calc']}/lb"
                                   f"{sw.iloc[0]['lookback']} {sw.iloc[0]['bound']}<= "
                                   f"{sw.iloc[0]['threshold']} drops {sw.iloc[0]['drop_insample']}")
        print(f"rule {rule}: {n} single bad-edge candidates{top}")
