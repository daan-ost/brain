"""
split_2b — 2b OUTLIER-good split analysis (READ-ONLY, descriptive). Builds NOTHING.

The question (2b, owner): per buy rule, find the subrule BANDS that are stretched by ONE (or a few)
OUTLIER good trade(s) and therefore admit many SLECHT trades. Rank the split candidates by how much
slecht falls away when the band is tightened to the good CLUSTER (the outlier good is sacrificed and
then needs its OWN rule), and name the good trade that needs that own rule.

This is the mirror image of RQ1 (opt_lib.bad_edge_conditions): RQ1 keeps ALL good and places a
threshold at the bad edge, so it can NEVER drop a bad trade that sits *inside* the good band — i.e.
between the good cluster and an outlier good. 2b deliberately drops the outlier good (→ own rule) so
that exact in-band slecht can be removed.

NOTE ON FRAMING: in practice the winning metric is usually NOT one of the rule's existing subrules,
so the move is "ADD a splitting subrule whose band = the good cluster" (which excludes the outlier
good and the in-gap slecht), not "narrow an existing band". `is_existing_subrule_calc` distinguishes
the two. Either way the slecht-drop count is valid on the EXECUTED set (executed trades passed ALL
existing subrules; an AND-subrule is monotonic — it can only remove fires), but an ADDED subrule
needs the mandatory full-period engine re-fire even more, because it filters the whole history on a
dimension that was never a rule constraint.

Method (cache-only, both coins; NO new calculations, NO ML):
  Reuse opt_lib.load_long() (executed trades × indicator_metrics Parquet cache). For each rule R and
  each metric (indicator, lookback, calc), with pooled good values G and bad values B (both coins):

  UPPER tail (outlier good high):
    full_good_max = max(G).                    # current effective upper band edge
    For k = 1..K outliers removed from the top:
      cluster_max = (k+1)-th largest good      # the cluster edge after sacrificing k good
      bad_dropped_by_split = #{b in B : cluster_max < b <= full_good_max}
                           = #slecht sitting between the cluster and the outlier(s)
      baseline_rq1_drop    = #{b in B : b > full_good_max}   # what plain RQ1 already removes (0 good lost)
      The split's UNIQUE benefit is `bad_dropped_by_split` (RQ1 cannot touch these without losing good).
  LOWER tail is symmetric (outlier good low; drop slecht between the outlier and the cluster bottom).

  A candidate is recorded when bad_dropped_by_split > 0. We also record `isolation` = gap between the
  cluster edge and the nearest outlier, divided by the cluster IQR (how cleanly the outlier stands
  apart), the per-coin split of the dropped slecht (single-coin artefact guard), the SCALE-safety flag
  (opt_lib.scale_unsafe — a volumeud level metric derived from the relative-volume cache is inert in
  the engine), whether the sacrificed good trade is already COVERED by another rule (±3 min, same coin
  → it would still fire, so the split costs no real opportunity), and whether the metric maps onto an
  existing subrule of the rule.

Everything is descriptive. The cache-level drop is the proposal; a full engine re-fire
(persist_to_brain / rq2_refire_check) is the confirmation step and is NOT done here.

Run:  ../.venv/bin/python split_2b.py            # prints ranked tables + writes out/opt/split_2b.json
"""
import json
import os

import numpy as np
import pandas as pd

import opt_lib as o
from db import brain

K_MAX = 3              # max outlier good trades to consider sacrificing per tail
MIN_GOOD = 8          # need a real cluster to talk about
MIN_BAD = 5
MIN_CLUSTER = 6       # cluster left after removing k outliers
TOL_MIN = 3           # coverage tolerance (matches rule_overlap default)

# subrulename -> cache calc column, for the cases where a subrule directly constrains a cache metric
# (used to honestly flag whether a candidate metric is ALREADY a subrule of the rule, on the SAME calc —
# not merely the same indicator/lookback). Conservative: only direct, unambiguous correspondences.
SUBRULE_CALC = {
    "currentvalue": "current_value", "last_value": "last_value", "skewness": "skewness",
    "volatility": "volatility", "range_percentage": "range_percentage",
    "diff_number_prev_max": "diff_number_prev_max", "diff_number_prev_min": "diff_number_prev_min",
    "diff_percentage_prev_max": "diff_percentage_prev_max", "max_diff_number": "max_diff_number",
    "sum_average_positive_percentage": "sum_average_positive_percentage",
    "diff_previous_number": "diff_previous_number", "sideways_upper": "sideways_upper",
    # previous_value/futureprice*/missingdata/volume_check have no single clean cache-calc -> not mapped
}
ISO_CAP = 30.0         # above this, isolation is a near-constant-cluster artefact, not a clean gap

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, "..", "out", "opt")


def load_rule_subrules():
    """Per rule: the set of (indicator) and (indicator, lookback) its active subrules use, for a soft
    'does this metric touch an existing subrule band' annotation. def1_value is the subrule lookback."""
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT rule_number, indicator, subrulename, def1_value, b_min, b_max "
                  "FROM rules WHERE active=1 AND rule_number IN (20,21,22,23)")
        rows = c.fetchall()
    conn.close()
    by_rule = {}
    for r in rows:
        d = by_rule.setdefault(r["rule_number"], {"ind": set(), "ind_lb": set(),
                                                  "ind_calc": set(), "subrules": []})
        d["ind"].add(r["indicator"])
        if r["def1_value"] is not None:
            d["ind_lb"].add((r["indicator"], int(r["def1_value"])))
        calc = SUBRULE_CALC.get(r["subrulename"])
        if calc:
            d["ind_calc"].add((r["indicator"], calc))   # the rule constrains this indicator ON this calc
        d["subrules"].append(r)
    return by_rule


def coverage_index(fires):
    """Group all fires (executed + shadow) by coin for fast ±tol coverage lookup."""
    return {sym: g.sort_values("datetime") for sym, g in fires.groupby("sym")}


def covered_by_other_rule(cov, sym, dt, rule):
    g = cov.get(sym)
    if g is None:
        return []
    tol = pd.Timedelta(minutes=TOL_MIN)
    m = g[(g["rule"] != rule) & (g["datetime"] >= dt - tol) & (g["datetime"] <= dt + tol)]
    out = []
    for _, r in m.iterrows():
        out.append({"rule": int(r["rule"]), "is_executed": int(r["is_executed"]),
                    "cls": r["cls"], "best_upside": round(float(r["best_upside"]), 2)})
    return out


def iqr(a):
    a = np.asarray(a, dtype=float)
    if len(a) < 4:
        return float(np.std(a)) if len(a) else 0.0
    q1, q3 = np.percentile(a, [25, 75])
    return float(q3 - q1)


def analyse(long, subrules, cov):
    cands = []
    for rule, sub in long.groupby("rule"):
        rmeta = subrules.get(int(rule), {"ind": set(), "ind_lb": set(), "ind_calc": set()})
        for (ind, lb, calc), g in sub.groupby(["indicator", "lookback", "calc"]):
            good = g[g["cls"] == "goed"]
            bad = g[g["cls"] == "slecht"]
            if len(good) < MIN_GOOD or len(bad) < MIN_BAD:
                continue
            gv = good["value"].to_numpy(float)
            bv = bad["value"].to_numpy(float)
            full_min, full_max = gv.min(), gv.max()
            good_sorted = good.sort_values("value")

            for tail in ("upper", "lower"):
                if tail == "upper":
                    full_edge = full_max
                    baseline = int((bv > full_edge).sum())
                    order = good_sorted.iloc[::-1]            # highest first = outlier candidates
                else:
                    full_edge = full_min
                    baseline = int((bv < full_edge).sum())
                    order = good_sorted                       # lowest first

                for k in range(1, K_MAX + 1):
                    if len(gv) - k < MIN_CLUSTER:
                        break
                    outliers = order.iloc[:k]
                    cluster = order.iloc[k:]["value"].to_numpy(float)
                    if tail == "upper":
                        cluster_edge = float(cluster.max())
                        in_stretch = (bv > cluster_edge) & (bv <= full_edge)
                        dropped = bv > cluster_edge                 # tighten exactly to cluster edge
                        nearest_outlier = float(outliers["value"].min())
                        gap = nearest_outlier - cluster_edge
                    else:
                        cluster_edge = float(cluster.min())
                        in_stretch = (bv < cluster_edge) & (bv >= full_edge)
                        dropped = bv < cluster_edge
                        nearest_outlier = float(outliers["value"].max())
                        gap = cluster_edge - nearest_outlier

                    n_split = int(in_stretch.sum())             # slecht the outlier(s) shield (unique to split)
                    if n_split <= 0:
                        continue
                    n_total_drop = int(dropped.sum())           # all slecht beyond the cluster edge
                    # per-coin split of the slecht uniquely removed by the split
                    bad_df = bad.copy()
                    bad_df["_split"] = in_stretch
                    per_coin = {int(s): int(v["_split"].sum())
                                for s, v in bad_df.groupby("sym") if v["_split"].sum()}
                    # cross-coin strength: how much slecht drops on the WEAKER of the two coins
                    syms_with_bad = [int(s) for s, v in bad.groupby("sym")]
                    min_coin_drop = min(per_coin.get(s, 0) for s in syms_with_bad) if syms_with_bad else 0
                    n_coins_drop = len(per_coin)
                    cl_iqr = iqr(cluster)
                    cl_distinct = int(len(np.unique(np.round(cluster, 6))))
                    isolation = round(gap / cl_iqr, 3) if cl_iqr > 1e-9 else float("inf")

                    sac = []
                    for _, r in outliers.iterrows():
                        cv = covered_by_other_rule(cov, int(r["sym"]), r["datetime"], int(rule))
                        sac.append({
                            "sym": int(r["sym"]),
                            "coin": "DOGEAI" if int(r["sym"]) == o.DOGEAI else "NOS",
                            "datetime": str(r["datetime"]),
                            "best_upside": round(float(r["best_upside"]), 2),
                            "value": round(float(r["value"]), 4),
                            "covered_by_other_rule": cv,
                        })
                    n_covered = sum(1 for s in sac if s["covered_by_other_rule"])

                    cands.append({
                        "rule": int(rule),
                        "indicator": ind,
                        "lookback": int(lb),
                        "calc": calc,
                        "tail": tail,
                        "k_outliers": k,
                        "bad_dropped_by_split": n_split,         # HEADLINE: slecht removed only via the split
                        "bad_dropped_total_to_edge": n_total_drop,
                        "baseline_rq1_drop": baseline,           # slecht RQ1 already removes (0 good lost)
                        "per_coin_split_drop": per_coin,
                        "min_coin_drop": int(min_coin_drop),     # cross-coin floor (0 = single-coin only)
                        "n_coins_drop": n_coins_drop,
                        "n_good": int(len(gv)),
                        "n_bad": int(len(bv)),
                        "good_kept": int(len(gv) - k),
                        "good_sacrificed": k,
                        "cluster_edge": round(cluster_edge, 5),
                        "full_good_edge": round(full_edge, 5),
                        "nearest_outlier_value": round(nearest_outlier, 5),
                        "gap": round(gap, 5),
                        "isolation_gap_over_iqr": isolation,
                        "cluster_n_distinct": cl_distinct,
                        "degenerate_discrete": bool(cl_iqr <= 1e-9 or cl_distinct < 5),
                        "degenerate_iso": bool(np.isfinite(isolation) and isolation > ISO_CAP),
                        "balance_ratio": round(min_coin_drop / n_split, 3) if n_split else 0.0,
                        "scale_unsafe": bool(o.scale_unsafe(ind, calc)),
                        # rule already evaluates this indicator at this lookback (NOT necessarily same calc):
                        "rule_evaluates_indicator_at_lb": (ind, int(lb)) in rmeta["ind_lb"],
                        # the rule ALREADY constrains this exact (indicator, calc) -> it's a band-tighten, not a new subrule:
                        "is_existing_subrule_calc": (ind, calc) in rmeta["ind_calc"],
                        "sacrificed_good": sac,
                        "n_sacrificed_covered_elsewhere": n_covered,
                    })
    return pd.DataFrame(cands)


ISO_GENUINE = 2.0       # gap >= 2x cluster IQR -> a real outlier gap (not a continuous-tail trim)


def _edge(good_vals, tail, k=1):
    g = np.sort(np.asarray(good_vals, dtype=float))
    if len(g) <= k:
        return None
    return float(g[-(k + 1)]) if tail == "upper" else float(g[k])


def oos_2b(long, rule, ind, lb, calc, tail, k=1):
    """Out-of-sample check for a 2b split (cluster-edge threshold). Derive the cluster edge on TRAIN
    good (sacrificing train's k outliers) and score on held-out good/bad. Two protocols:
    - time: per-coin first 70% train / last 30% test (opt_lib's split column).
    - cross-coin: derive on coin A, test on coin B (both directions).
    good_keep = fraction of held-out GOOD that stays in the cluster (NOT sacrificed) — a clean rare
    outlier => ~1.0. bad_drop = fraction of held-out SLECHT removed. Returns dict of splits."""
    g = long[(long["rule"] == rule) & (long["indicator"] == ind) &
             (long["lookback"] == lb) & (long["calc"] == calc)]

    def score(tr, te):
        tr_good = tr[tr["cls"] == "goed"]["value"].to_numpy(float)
        te_good = te[te["cls"] == "goed"]["value"].to_numpy(float)
        te_bad = te[te["cls"] == "slecht"]["value"].to_numpy(float)
        if len(tr_good) < 5 or len(te_good) < 3 or len(te_bad) < 3:
            return None
        edge = _edge(tr_good, tail, k)
        if edge is None:
            return None
        if tail == "upper":
            good_keep = float((te_good <= edge).mean())
            bad_drop = float((te_bad > edge).mean())
            te_good_sacrificed = int((te_good > edge).sum())
        else:
            good_keep = float((te_good >= edge).mean())
            bad_drop = float((te_bad < edge).mean())
            te_good_sacrificed = int((te_good < edge).sum())
        return {"edge": round(edge, 4), "good_keep": round(good_keep, 3),
                "bad_drop": round(bad_drop, 3), "n_te_good": len(te_good),
                "n_te_bad": len(te_bad), "te_good_sacrificed": te_good_sacrificed}

    res = {}
    t = score(g[g["split"] == "train"], g[g["split"] == "test"])
    if t:
        res["time"] = t
    for a, b, name in ((o.DOGEAI, o.NOS, "2525->244"), (o.NOS, o.DOGEAI, "244->2525")):
        cc = score(g[g["sym"] == a], g[g["sym"] == b])
        if cc:
            res[name] = cc
    return res


def detail_gap(long, rule, ind, lb, calc, tail, k=1):
    """Show the actual sorted good values + the slecht that sit in the stretch zone, so a reviewer can
    SEE the gap (genuine outlier) vs a metric artefact. Returns a printable string."""
    g = long[(long["rule"] == rule) & (long["indicator"] == ind) &
             (long["lookback"] == lb) & (long["calc"] == calc)]
    good = g[g["cls"] == "goed"].sort_values("value")
    bad = g[g["cls"] == "slecht"].sort_values("value")
    gv = good["value"].to_numpy(float)
    if tail == "upper":
        cluster_edge = float(np.sort(gv)[-(k + 1)])
        outliers = good[good["value"] > cluster_edge]
        stretch = bad[(bad["value"] > cluster_edge) & (bad["value"] <= gv.max())]
        top_cluster = np.sort(gv)[-(k + 6):-(k)]
    else:
        cluster_edge = float(np.sort(gv)[k])
        outliers = good[good["value"] < cluster_edge]
        stretch = bad[(bad["value"] < cluster_edge) & (bad["value"] >= gv.min())]
        top_cluster = np.sort(gv)[k:k + 6]
    L = [f"  rule {rule} {ind}/{calc}/lb{lb} [{tail}]  cluster_edge={round(cluster_edge,4)}",
         f"    outlier good ({len(outliers)}): " +
         ", ".join(f"{round(float(r.value),3)}@{('DOGE' if int(r.sym)==o.DOGEAI else 'NOS')}"
                   f"(bu{round(float(r.best_upside),1)})" for _, r in outliers.iterrows()),
         f"    nearest cluster good: {[round(float(x),3) for x in top_cluster]}",
         f"    slecht IN the gap ({len(stretch)}): " +
         ", ".join(f"{round(float(r.value),3)}@{('DOGE' if int(r.sym)==o.DOGEAI else 'NOS')}"
                   for _, r in stretch.iterrows())]
    return "\n".join(L)


def main():
    long = o.load_long()
    subrules = load_rule_subrules()
    fires = o.load_all_fires()
    cov = coverage_index(fires)
    df = analyse(long, subrules, cov)
    if df.empty:
        print("no split candidates")
        return

    df["genuine"] = ((df["isolation_gap_over_iqr"] >= ISO_GENUINE)
                     & (df["isolation_gap_over_iqr"] <= ISO_CAP)
                     & np.isfinite(df["isolation_gap_over_iqr"])
                     & (~df["degenerate_discrete"]) & (~df["scale_unsafe"]))
    # cross-coin BALANCED = the weaker coin still carries a real share of the drop (not just 1)
    df["cross_coin_balanced"] = (df["balance_ratio"] >= 0.3) & (df["min_coin_drop"] >= 2)
    df = df.sort_values(
        ["bad_dropped_by_split", "balance_ratio", "isolation_gap_over_iqr", "good_sacrificed"],
        ascending=[False, False, False, True]).reset_index(drop=True)

    os.makedirs(OUT, exist_ok=True)
    path = os.path.join(OUT, "split_2b.json")
    with open(path, "w") as f:
        json.dump(df.to_dict("records"), f, indent=2, default=str)

    pd.set_option("display.width", 230, "display.max_columns", 30)
    cols = ["rule", "indicator", "lookback", "calc", "tail",
            "bad_dropped_by_split", "min_coin_drop", "balance_ratio", "per_coin_split_drop",
            "baseline_rq1_drop", "isolation_gap_over_iqr", "n_sacrificed_covered_elsewhere",
            "is_existing_subrule_calc"]

    # GENUINE = real bounded gap (2<=iso<=30), scale-safe, k=1, slecht drops on BOTH coins
    gen = df[df["genuine"] & (df["k_outliers"] == 1) & (df["min_coin_drop"] >= 1)]
    print("\n===== GENUINE split candidates — bounded gap (2..30 IQR), scale-safe, cross-coin (k=1) =====")
    print(gen[cols].head(35).to_string(index=False))

    print("\n========== per-rule BEST split candidate (gap + OOS-gated good_keep>=0.90) ==========")
    OOS_GK = 0.90
    for rule in (20, 21, 22, 23):
        r = gen[gen["rule"] == rule]
        print(f"\n--- rule {rule}: {len(r)} genuine cross-coin candidates ---")
        if r.empty:
            print("  (no genuine-gap split candidate)")
            continue
        # compute OOS for the top-by-drop genuine candidates and keep those whose held-out good survive
        passed = []
        for _, c in r.head(25).iterrows():
            oos = oos_2b(long, int(c["rule"]), c["indicator"], int(c["lookback"]),
                         c["calc"], c["tail"], int(c["k_outliers"]))
            gks = [v["good_keep"] for v in oos.values()]
            min_gk = min(gks) if gks else float("nan")
            passed.append((min_gk, c, oos))
        ok = [p for p in passed if not np.isnan(p[0]) and p[0] >= OOS_GK]
        pool = ok if ok else passed
        # rank survivors by in-sample drop, then OOS good_keep
        pool.sort(key=lambda p: (p[1]["bad_dropped_by_split"], p[0]), reverse=True)
        min_gk, top, oos = pool[0]
        flag = "" if ok else "  [NONE pass OOS gk>=0.90 — strongest shown, treat as weak]"
        print(f"  {top['indicator']}/{top['calc']}/lb{top['lookback']} [{top['tail']}] "
              f"drops {top['bad_dropped_by_split']} slecht (per coin {top['per_coin_split_drop']}, "
              f"balance {top['balance_ratio']}), iso {top['isolation_gap_over_iqr']}, "
              f"OOS min good_keep={round(min_gk,3)}, baseline RQ1 {top['baseline_rq1_drop']}, "
              f"existing-subrule-calc={top['is_existing_subrule_calc']}, "
              f"sac-good-covered={bool(top['n_sacrificed_covered_elsewhere'])}{flag}")
        print(detail_gap(long, int(top["rule"]), top["indicator"], int(top["lookback"]),
                         top["calc"], top["tail"], int(top["k_outliers"])))
        oos_s = " | ".join(f"{kk}: gk={vv['good_keep']} bad_drop={vv['bad_drop']} "
                           f"(te {vv['n_te_good']}g/{vv['n_te_bad']}b, sac {vv['te_good_sacrificed']})"
                           for kk, vv in oos.items()) or "insufficient data"
        print(f"    OOS  {oos_s}")

    print("\n========== SCALE-UNSAFE 'strong' candidates that are INERT in the engine (excluded) ==========")
    unsafe = df[(df["k_outliers"] == 1) & (df["scale_unsafe"]) & (df["bad_dropped_by_split"] >= 4)]
    print(unsafe[["rule", "indicator", "lookback", "calc", "tail",
                  "bad_dropped_by_split"]].head(12).to_string(index=False))

    # which good trades are outliers across many GENUINE candidates -> structural split targets
    print("\n========== good trades that need their OWN rule (genuine-gap outlier across metrics) ==========")
    rows = []
    for _, c in df[df["genuine"] & (df["k_outliers"] == 1)].iterrows():
        for s in c["sacrificed_good"]:
            rows.append({"rule": c["rule"], "coin": s["coin"], "datetime": s["datetime"],
                         "best_upside": s["best_upside"], "covered": bool(s["covered_by_other_rule"]),
                         "ind": c["indicator"], "bad_dropped": c["bad_dropped_by_split"],
                         "min_coin": c["min_coin_drop"]})
    g = pd.DataFrame(rows)
    if not g.empty:
        agg = (g.groupby(["rule", "coin", "datetime", "best_upside", "covered"])
                 .agg(n_genuine_metrics=("ind", "size"), n_indicators=("ind", "nunique"),
                      max_bad_dropped=("bad_dropped", "max"),
                      best_cross_coin=("min_coin", "max"))
                 .reset_index().sort_values(["n_indicators", "max_bad_dropped"], ascending=False))
        print(agg.head(20).to_string(index=False))

    print(f"\nwrote {path}  ({len(df)} candidates; {int(df['genuine'].sum())} genuine-gap scale-safe)")


if __name__ == "__main__":
    main()
