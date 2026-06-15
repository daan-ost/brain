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
        d = by_rule.setdefault(r["rule_number"], {"ind": set(), "ind_lb": set(), "subrules": []})
        d["ind"].add(r["indicator"])
        if r["def1_value"] is not None:
            d["ind_lb"].add((r["indicator"], int(r["def1_value"])))
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
        rmeta = subrules.get(int(rule), {"ind": set(), "ind_lb": set()})
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
                    isolation = round(gap / iqr(cluster), 3) if iqr(cluster) > 1e-9 else float("inf")

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
                        "n_good": int(len(gv)),
                        "n_bad": int(len(bv)),
                        "good_kept": int(len(gv) - k),
                        "good_sacrificed": k,
                        "cluster_edge": round(cluster_edge, 5),
                        "full_good_edge": round(full_edge, 5),
                        "nearest_outlier_value": round(nearest_outlier, 5),
                        "gap": round(gap, 5),
                        "isolation_gap_over_iqr": isolation,
                        "scale_unsafe": bool(o.scale_unsafe(ind, calc)),
                        "touches_existing_subrule_ind": ind in rmeta["ind"],
                        "touches_existing_subrule_ind_lb": (ind, int(lb)) in rmeta["ind_lb"],
                        "sacrificed_good": sac,
                        "n_sacrificed_covered_elsewhere": n_covered,
                    })
    return pd.DataFrame(cands)


def main():
    long = o.load_long()
    subrules = load_rule_subrules()
    fires = o.load_all_fires()
    cov = coverage_index(fires)
    df = analyse(long, subrules, cov)
    if df.empty:
        print("no split candidates")
        return

    df = df.sort_values(
        ["bad_dropped_by_split", "scale_unsafe", "good_sacrificed", "isolation_gap_over_iqr"],
        ascending=[False, True, True, False]).reset_index(drop=True)

    os.makedirs(OUT, exist_ok=True)
    path = os.path.join(OUT, "split_2b.json")
    with open(path, "w") as f:
        json.dump(df.to_dict("records"), f, indent=2, default=str)

    pd.set_option("display.width", 200, "display.max_columns", 30)
    cols = ["rule", "indicator", "lookback", "calc", "tail", "k_outliers",
            "bad_dropped_by_split", "baseline_rq1_drop", "per_coin_split_drop",
            "isolation_gap_over_iqr", "scale_unsafe", "n_sacrificed_covered_elsewhere",
            "touches_existing_subrule_ind_lb"]

    print("\n================= ALL split candidates (k=1, scale-safe), ranked =================")
    safe1 = df[(df["k_outliers"] == 1) & (~df["scale_unsafe"])]
    print(safe1[cols].head(40).to_string(index=False))

    print("\n================= per-rule TOP (k=1, scale-safe, ≥2 slecht weg, cross-coin) =================")
    for rule in (20, 21, 22, 23):
        r = safe1[(safe1["rule"] == rule) & (safe1["bad_dropped_by_split"] >= 2)]
        # prefer candidates whose dropped slecht is not single-coin
        print(f"\n--- rule {rule} ({len(r)} candidates ≥2) ---")
        if r.empty:
            print("  (none ≥2 slecht)")
            continue
        print(r[cols].head(8).to_string(index=False))

    print("\n================= SCALE-UNSAFE candidates that would look strong but are INERT =========")
    unsafe = df[(df["k_outliers"] == 1) & (df["scale_unsafe"]) & (df["bad_dropped_by_split"] >= 3)]
    print(unsafe[["rule", "indicator", "lookback", "calc", "tail",
                  "bad_dropped_by_split"]].head(15).to_string(index=False))

    # which good trades are outliers across MANY metrics -> structural split targets
    print("\n================= good trades that need their OWN rule (outlier across metrics) =======")
    rows = []
    for _, c in safe1[safe1["bad_dropped_by_split"] >= 2].iterrows():
        for s in c["sacrificed_good"]:
            rows.append({"rule": c["rule"], "coin": s["coin"], "datetime": s["datetime"],
                         "best_upside": s["best_upside"], "covered": bool(s["covered_by_other_rule"]),
                         "metric": f"{c['indicator']}/{c['calc']}/lb{c['lookback']}/{c['tail']}",
                         "bad_dropped": c["bad_dropped_by_split"]})
    g = pd.DataFrame(rows)
    if not g.empty:
        agg = (g.groupby(["rule", "coin", "datetime", "best_upside", "covered"])
                 .agg(n_metrics=("metric", "nunique"),
                      max_bad_dropped=("bad_dropped", "max"),
                      sum_bad_dropped=("bad_dropped", "sum"))
                 .reset_index().sort_values(["n_metrics", "max_bad_dropped"], ascending=False))
        print(agg.head(25).to_string(index=False))

    print(f"\nwrote {path}  ({len(df)} candidates, {len(safe1)} scale-safe k=1)")


if __name__ == "__main__":
    main()
