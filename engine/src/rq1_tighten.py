#!/usr/bin/env python3
"""
RQ1 — AANSCHERPEN zonder meer slecht. Per rule: which EXTRA subrule (indicator x calc x lookback,
threshold at the BAD EDGE) drops the most BAD trades while keeping 100% of GOOD — AND survives
out-of-sample (time split + both cross-coin directions)? For rule 20 (no safe single found) it
also searches PAIRS of two bad-edge conditions (AND).

Deterministic, read-only, creates only a JSON report under engine/out/opt/. Repeatable daily.
Usage: rq1_tighten.py [rule|all] [min_drop] [--pairs]
"""
import itertools
import json
import os
import sys

import numpy as np
import pandas as pd

import opt_lib as o

RULE = sys.argv[1] if len(sys.argv) > 1 else "all"
MIN_DROP = int([a for a in sys.argv[2:] if a.isdigit()][0]) if any(a.isdigit() for a in sys.argv[2:]) else 5
PAIRS = "--pairs" in sys.argv
RULES = [20, 21, 22, 23] if RULE == "all" else [int(RULE)]
SAFE_KEEP = 0.98          # good_keep threshold to call a split "safe"

OUT = os.path.join(o.HERE, "..", "out", "opt")
os.makedirs(OUT, exist_ok=True)


def classify(splits):
    """A candidate is SAFE only if every split that has data keeps ~all good (good_keep>=SAFE_KEEP)."""
    keeps = [s["good_keep"] for s in splits.values() if not np.isnan(s["good_keep"])]
    if not keeps:
        return "no_oos_data", keeps
    return ("SAFE" if min(keeps) >= SAFE_KEEP else "UNSAFE"), keeps


def singles(long, rule):
    sw = o.sweep_single(long, rule)
    if sw.empty:
        return []
    sw = sw[sw["drop_insample"] >= MIN_DROP]
    out = []
    for _, r in sw.iterrows():
        splits = o.full_validation(long, rule, r["indicator"], int(r["lookback"]), r["calc"], r["bound"])
        verdict, keeps = classify(splits)
        if o.scale_unsafe(r["indicator"], r["calc"]):
            verdict = "SCALE_UNSAFE"   # cache threshold invalid in the engine (volumeud level-metric)
        out.append({"kind": "single", "rule": rule, "indicator": r["indicator"],
                    "calc": r["calc"], "lookback": int(r["lookback"]), "bound": r["bound"],
                    "threshold": float(r["threshold"]), "drop_insample": int(r["drop_insample"]),
                    "n_good": int(r["n_good"]), "n_bad": int(r["n_bad"]),
                    "oos": splits, "min_good_keep": (round(min(keeps), 3) if keeps else None),
                    "verdict": verdict})
    out.sort(key=lambda c: (c["verdict"] != "SAFE", -c["drop_insample"]))
    return out


def _cond_mask(values, bound, thr):
    v = np.asarray(values, dtype=float)
    return (v >= thr) if bound == "lower" else (v <= thr)


def pairs(long, rule, top_k=14):
    """Search AND-pairs of two bad-edge single conditions for `rule`. Each single keeps all good
    in-sample by construction, so the pair does too; the pair's benefit = bad failing EITHER. OOS:
    good_keep = test-good passing BOTH; only keep pairs safe on time + both cross-coin splits."""
    sub = long[long["rule"] == rule]
    sw = o.sweep_single(long, rule)
    if sw.empty:
        return []
    # keep the strongest distinct (indicator,calc,lookback,bound) singles as building blocks
    blocks = sw.head(top_k).to_dict("records")

    # pre-extract per-block value series keyed by split/coin/class for speed
    def series(b, mask):
        g = sub[(sub["indicator"] == b["indicator"]) & (sub["lookback"] == int(b["lookback"])) &
                (sub["calc"] == b["calc"])]
        return g[mask(g)]

    rows = []
    for a, b in itertools.combinations(blocks, 2):
        if (a["indicator"], a["calc"], a["lookback"]) == (b["indicator"], b["calc"], b["lookback"]):
            continue
        # need both features present on the same trades -> join on (sym, datetime)
        ga = sub[(sub.indicator == a["indicator"]) & (sub.lookback == int(a["lookback"])) & (sub.calc == a["calc"])]
        gb = sub[(sub.indicator == b["indicator"]) & (sub.lookback == int(b["lookback"])) & (sub.calc == b["calc"])]
        m = ga.merge(gb, on=["sym", "datetime", "cls", "split"], suffixes=("_a", "_b"))
        if m.empty:
            continue

        def eval_split(df_):
            good = df_[df_.cls == "goed"]; bad = df_[df_.cls == "slecht"]
            if len(good) < 3 or len(bad) < 3:
                return None
            pa = _cond_mask(good.value_a, a["bound"], a["threshold"]) & _cond_mask(good.value_b, b["bound"], b["threshold"])
            ba_drop = ~(_cond_mask(bad.value_a, a["bound"], a["threshold"]) & _cond_mask(bad.value_b, b["bound"], b["threshold"]))
            return float(pa.mean()), float(ba_drop.mean()), int(ba_drop.sum()), len(good), len(bad)

        # in-sample drop (pooled)
        ins = eval_split(m)
        if not ins:
            continue
        drop_in = ins[2]
        if drop_in < MIN_DROP:
            continue
        splits = {}
        te = m[m.split == "test"]
        r = eval_split(te)
        if r:
            splits["time"] = {"good_keep": round(r[0], 3), "bad_drop": round(r[1], 3), "n_te_good": r[3], "n_te_bad": r[4]}
        for tr_s, te_s in ((o.DOGEAI, o.NOS), (o.NOS, o.DOGEAI)):
            r = eval_split(m[m.sym == te_s])
            if r:
                splits[f"{tr_s}->{te_s}"] = {"good_keep": round(r[0], 3), "bad_drop": round(r[1], 3),
                                             "n_te_good": r[3], "n_te_bad": r[4]}
        verdict, keeps = classify(splits)
        if o.scale_unsafe(a["indicator"], a["calc"]) or o.scale_unsafe(b["indicator"], b["calc"]):
            verdict = "SCALE_UNSAFE"   # either leg's cache threshold is invalid in the engine
        rows.append({"kind": "pair", "rule": rule,
                     "a": {"indicator": a["indicator"], "calc": a["calc"], "lookback": int(a["lookback"]),
                           "bound": a["bound"], "threshold": a["threshold"]},
                     "b": {"indicator": b["indicator"], "calc": b["calc"], "lookback": int(b["lookback"]),
                           "bound": b["bound"], "threshold": b["threshold"]},
                     "drop_insample": drop_in, "oos": splits,
                     "min_good_keep": (round(min(keeps), 3) if keeps else None), "verdict": verdict})
    rows.sort(key=lambda c: (c["verdict"] != "SAFE", -c["drop_insample"]))
    return rows


def main():
    long = o.load_long()
    report = {}
    for rule in RULES:
        s = singles(long, rule)
        p = pairs(long, rule) if PAIRS else []   # 4-coin: pairs-search alleen op --pairs flag (was O(n²)×4-coin data — vastlooprisico in routine; aparte run mag wel)
        report[rule] = {"singles": s, "pairs": p}
        print(f"\n===== RULE {rule} =====")
        safe_s = [c for c in s if c["verdict"] == "SAFE"]
        n_scale = sum(1 for c in s if c["verdict"] == "SCALE_UNSAFE")
        print(f"singles: {len(s)} candidates (drop>={MIN_DROP}), {len(safe_s)} SAFE out-of-sample"
              + (f", {n_scale} uitgesloten (SCALE_UNSAFE: volumeud level-metric)" if n_scale else ""))
        for c in safe_s[:6]:
            print(f"  SAFE  {c['indicator']}/{c['calc']}/lb{c['lookback']} {c['bound']}<= {c['threshold']} "
                  f"| drops {c['drop_insample']} bad in-sample | min_good_keep={c['min_good_keep']} "
                  f"| splits={ {k: v['good_keep'] for k, v in c['oos'].items()} }")
        if not safe_s and s:
            best = s[0]
            print(f"  (best UNSAFE single: {best['indicator']}/{best['calc']}/lb{best['lookback']} "
                  f"{best['bound']}<= {best['threshold']} drop {best['drop_insample']} min_good_keep={best['min_good_keep']})")
        if p:
            safe_p = [c for c in p if c["verdict"] == "SAFE"]
            print(f"pairs: {len(p)} candidates, {len(safe_p)} SAFE out-of-sample")
            for c in safe_p[:5]:
                aa, bb = c["a"], c["b"]
                print(f"  SAFE pair: ({aa['indicator']}/{aa['calc']}/lb{aa['lookback']} {aa['bound']}<= {aa['threshold']}) "
                      f"AND ({bb['indicator']}/{bb['calc']}/lb{bb['lookback']} {bb['bound']}<= {bb['threshold']}) "
                      f"| drops {c['drop_insample']} | min_good_keep={c['min_good_keep']}")
    path = os.path.join(OUT, f"rq1_tighten_{RULE}.json")
    with open(path, "w") as f:
        json.dump(report, f, indent=2, default=str)
    print(f"\nwrote {path}")


if __name__ == "__main__":
    main()
