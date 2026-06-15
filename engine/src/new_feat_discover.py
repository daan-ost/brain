#!/usr/bin/env python3
"""
new_feat_discover — READ-ONLY discovery of NEW features that separate GOOD from BAD trades for a rule.

For every executed trade of both coins it computes the new_feat_lib battery at the trade datetime
(leak-free as-of, RAW engine series), then for the TARGET rule ranks each feature by:
  - in-sample bad-edge drop (principle 2: threshold in the gap at the bad edge, keeps ALL good),
  - Cohen's d (good vs bad separation),
  - OUT-OF-SAMPLE good_keep / bad_drop on THREE splits (time 70/30, DOGEAI->NOS, NOS->DOGEAI).

This is the CHEAP cache-style filter (it OVERSTATES — most survivors are OOS no-ops; the binding test
is the engine gate, new_feat_gate.py). A candidate worth gating: in-sample drop>0, good_keep~1.0 on
every split with data, bad_drop>0 cross-coin. Mirrors opt_lib's bad-edge + OOS math exactly so the
numbers are comparable to the existing-feature study.

Creates NOTHING. Usage:
    new_feat_discover.py [rule] [family] [top_n]
    family: interaction | shape | context | sequence | all   (default all)
Writes a ranked JSON to ../out/opt/new_feat_<rule>_<family>.json and prints the top.
"""
import json
import math
import os
import sys

import numpy as np
import pandas as pd

import opt_lib as o
from new_feat_lib import REGISTRY, features_for_family
from rule_engine import RuleEngine

_RA = sys.argv[1] if len(sys.argv) > 1 else "21"          # rule number, or "all" for every rule
FAMILY = sys.argv[2] if len(sys.argv) > 2 else "all"
TOPN = int(sys.argv[3]) if len(sys.argv) > 3 else 30
HERE = os.path.dirname(os.path.abspath(__file__))
ALL_RULES = (20, 21, 22, 23)


def _cohens_d(good, bad):
    g, b = np.asarray(good, float), np.asarray(bad, float)
    if len(g) < 2 or len(b) < 2:
        return 0.0
    sg, sb = g.std(ddof=1), b.std(ddof=1)
    pooled = math.sqrt(((len(g) - 1) * sg ** 2 + (len(b) - 1) * sb ** 2) / (len(g) + len(b) - 2))
    return abs(g.mean() - b.mean()) / pooled if pooled else 0.0


def build_long():
    """One row per (trade x feature) -> value, with sym/rule/cls/split. Computes features at each
    executed trade's datetime via the engine (raw as-of)."""
    trades = o.load_trades()                       # sym, datetime, rule, cls, split, best_upside
    feats = features_for_family(FAMILY)
    rows = []
    for sym, g in trades.groupby("sym"):
        eng = RuleEngine(int(sym))
        for _, tr in g.iterrows():
            T = tr["datetime"]
            for name, fn in feats.items():
                v = fn(eng, T)
                if v is None:
                    continue
                v = float(v)
                if math.isnan(v) or math.isinf(v):
                    continue
                rows.append((int(sym), T, int(tr["rule"]), tr["cls"], tr["split"], name, v))
        eng.close()
    return pd.DataFrame(rows, columns=["sym", "datetime", "rule", "cls", "split", "feat", "value"])


def evaluate_feature(sub):
    """sub = long rows for ONE feature, ONE rule. Returns the best bad-edge candidate + OOS across
    all three splits, or None if no in-sample bad-edge drop exists."""
    good = sub[sub["cls"] == "goed"]["value"].values
    bad = sub[sub["cls"] == "slecht"]["value"].values
    if len(good) < 5 or len(bad) < 5:
        return None
    conds = o.bad_edge_conditions(good, bad)
    if not conds:
        return None
    best = max(conds, key=lambda c: c["drop_insample"])
    bound, thr = best["bound"], best["threshold"]

    out = {"n_good": int(len(good)), "n_bad": int(len(bad)),
           "bound": bound, "threshold": thr, "drop_insample": best["drop_insample"],
           "cohens_d": round(_cohens_d(good, bad), 3),
           "mean_good": round(float(np.mean(good)), 4), "mean_bad": round(float(np.mean(bad)), 4),
           "splits": {}}

    # per-coin n (one-coin flag)
    for sym in (o.DOGEAI, o.NOS):
        sg = sub[(sub["sym"] == sym) & (sub["cls"] == "goed")]
        sb = sub[(sub["sym"] == sym) & (sub["cls"] == "slecht")]
        out[f"n_{sym}"] = [int(len(sg)), int(len(sb))]

    # time split: derive threshold on train, score on test
    tr_g = sub[(sub["split"] == "train") & (sub["cls"] == "goed")]["value"].values
    tr_b = sub[(sub["split"] == "train") & (sub["cls"] == "slecht")]["value"].values
    te_g = sub[(sub["split"] == "test") & (sub["cls"] == "goed")]["value"].values
    te_b = sub[(sub["split"] == "test") & (sub["cls"] == "slecht")]["value"].values
    if len(tr_g) >= 5 and len(tr_b) >= 5 and len(te_g) >= 3 and len(te_b) >= 3:
        tc = {c["bound"]: c for c in o.bad_edge_conditions(tr_g, tr_b)}
        if bound in tc:
            out["splits"]["time"] = o.oos_metrics(tr_g, tr_b, te_g, te_b, bound, tc[bound]["threshold"])

    # cross-coin both directions
    for train, test in ((o.DOGEAI, o.NOS), (o.NOS, o.DOGEAI)):
        tg = sub[(sub["sym"] == train) & (sub["cls"] == "goed")]["value"].values
        tb = sub[(sub["sym"] == train) & (sub["cls"] == "slecht")]["value"].values
        eg = sub[(sub["sym"] == test) & (sub["cls"] == "goed")]["value"].values
        eb = sub[(sub["sym"] == test) & (sub["cls"] == "slecht")]["value"].values
        if len(tg) >= 5 and len(tb) >= 5 and len(eg) >= 3 and len(eb) >= 3:
            tc = {c["bound"]: c for c in o.bad_edge_conditions(tg, tb)}
            if bound in tc:
                out["splits"][f"{train}->{test}"] = o.oos_metrics(tg, tb, eg, eb, bound, tc[bound]["threshold"])
    return out


def rank_rule(long, rule):
    sub_rule = long[long["rule"] == rule]
    results = {}
    for feat, g in sub_rule.groupby("feat"):
        r = evaluate_feature(g)
        if r:
            results[feat] = r

    def safe_min_keep(r):
        ks = [s["good_keep"] for s in r["splits"].values() if not math.isnan(s["good_keep"])]
        return min(ks) if ks else float("nan")

    def cross_bad_drop(r):
        cc = [r["splits"][k]["bad_drop"] for k in (f"{o.DOGEAI}->{o.NOS}", f"{o.NOS}->{o.DOGEAI}")
              if k in r["splits"] and not math.isnan(r["splits"][k]["bad_drop"])]
        return min(cc) if cc else float("nan")

    ranked = sorted(results.items(),
                    key=lambda kv: (kv[1]["drop_insample"], kv[1]["cohens_d"]), reverse=True)
    cand_list = [{"feat": k, **v, "min_good_keep": round(safe_min_keep(v), 3),
                  "min_crosscoin_bad_drop": round(cross_bad_drop(v), 3)} for k, v in ranked]
    return results, ranked, cand_list, safe_min_keep, cross_bad_drop


def _print_rule(rule, ranked, safe_min_keep, cross_bad_drop, n_eval):
    print(f"\n=== rule {rule}, family {FAMILY} — {n_eval} features with an in-sample bad-edge drop "
          f"(of {len(features_for_family(FAMILY))} in family) ===")
    print(f"{'feature':34} {'bnd':5} {'thr':>10} {'drop':>4} {'d':>5} {'minGK':>6} {'ccBD':>5}  per-coin g/b (D|N)")
    for k, v in ranked[:TOPN]:
        gk = safe_min_keep(v); cb = cross_bad_drop(v)
        gk_s = f"{gk:.2f}" if not math.isnan(gk) else "  - "
        cb_s = f"{cb:.2f}" if not math.isnan(cb) else "  - "
        pc = f"{v['n_2525'][0]}/{v['n_2525'][1]} | {v['n_244'][0]}/{v['n_244'][1]}"
        print(f"{k:34} {v['bound']:5} {v['threshold']:>10.3f} {v['drop_insample']:>4} "
              f"{v['cohens_d']:>5.2f} {gk_s:>6} {cb_s:>5}  {pc}")


def main():
    long = build_long()
    rules = ALL_RULES if _RA == "all" else (int(_RA),)
    big = {}
    for rule in rules:
        results, ranked, cand_list, smk, cbd = rank_rule(long, rule)
        _print_rule(rule, ranked, smk, cbd, len(results))
        outp = os.path.join(HERE, "..", "out", "opt", f"new_feat_{rule}_{FAMILY}.json")
        with open(outp, "w") as f:
            json.dump({"rule": rule, "family": FAMILY, "n_features_evaluated": len(results),
                       "candidates": cand_list}, f, indent=2, default=str)
        big[rule] = outp
    print(f"\n-> wrote {', '.join(os.path.relpath(p, HERE) for p in big.values())}")
    print("Read: drop=in-sample bad dropped (0 good lost by construction); d=Cohen's d; "
          "minGK=min OOS good_keep over splits (~1.0=good); ccBD=min cross-coin bad_drop (robustness).")


if __name__ == "__main__":
    main()
