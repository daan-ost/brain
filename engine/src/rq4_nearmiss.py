#!/usr/bin/env python3
"""
RQ4 — BIJNA-RAKENDE promising trades. For promising periods with NO executed trade, scan the
candidate moments (volume_found=1) inside the period, keep the GOOD ones (best_upside >= 3 — the
genuinely missed opportunities), and find where a main rule is only <3 subrules away from firing.
Per near-miss: which subrule(s) block. Then, for the SINGLE-blocker near-misses, propose a bad-edge
loosening of that subrule that would let it fire while admitting ZERO new bad candidate moments.

Read-only, creates only JSON under engine/out/opt/.
Usage: rq4_nearmiss.py [rule] [symbol|both] [max_fail]   (max_fail default 2 = "<3 false")
"""
import json
import os
import sys
from collections import defaultdict

import numpy as np

import opt_lib as o
from opt_diag import DiagEngine, promising_verdicts, _cls

RULE = int(sys.argv[1]) if len(sys.argv) > 1 else 21
WHICH = sys.argv[2] if len(sys.argv) > 2 else "both"
MAX_FAIL = int(sys.argv[3]) if len(sys.argv) > 3 else 2
SYMS = [o.DOGEAI, o.NOS] if WHICH == "both" else [int(WHICH)]

OUT = os.path.join(o.HERE, "..", "out", "opt")
os.makedirs(OUT, exist_ok=True)


def analyse_symbol(sym):
    eng = DiagEngine(sym)
    periods, traded = promising_verdicts(sym)
    no_trade = [p for p in periods if p["id"] not in traded]

    # candidate moments grouped per no-trade period
    cand_dt = eng.candidates()
    # index candidates by period
    near_misses = []                 # one per good near-miss moment
    blocker_count = defaultdict(int)  # subrule_index -> how many missed-good near-misses it blocks
    # also collect, per subrule, the value at blocked missed-good moments (for the loosening)
    blocked_good_vals = defaultdict(list)

    for p in no_trade:
        a, b = p["period_from"], p["period_to"]
        moments = [T for T in cand_dt if a <= T <= b]
        best = None
        for T in moments:
            bu = eng.best_upside(T)
            if bu is None or _cls(bu) != "goed":
                continue                       # only the genuinely missed GOOD opportunities
            nfail, fails = eng.n_failing(RULE, T)
            if 0 < nfail <= MAX_FAIL:
                rec = {"dt": T, "best_upside": bu, "n_fail": nfail,
                       "blockers": [{"i": f["i"],
                                     "subrule": f"{f['indicator']}/{f['subrulename']}/lb{f['def1'] or 1}",
                                     "value": f["value"], "band": [f["b_min"], f["b_max"]]} for f in fails]}
                if best is None or rec["n_fail"] < best["n_fail"] or (
                        rec["n_fail"] == best["n_fail"] and rec["best_upside"] > best["best_upside"]):
                    best = rec
        if best:
            best["period_id"] = p["id"]
            best["period_best_upside"] = float(p["best_upside"]) if p["best_upside"] is not None else None
            near_misses.append(best)
            for blk in best["blockers"]:
                blocker_count[blk["i"]] += 1
                if blk["value"] not in (None, "PASS"):
                    blocked_good_vals[blk["i"]].append(float(blk["value"]))

    # for the most frequent SINGLE blockers, compute a bad-edge loosening over ALL candidate
    # moments (good vs bad), confirming 0 new bad would be admitted.
    subs = eng.rules[RULE]
    # build candidate value/class table per blocking subrule of interest
    interest = sorted(blocker_count, key=lambda i: -blocker_count[i])[:6]
    loosen_props = []
    if interest:
        # one pass over ALL candidates collecting (value, cls) per subrule of interest
        per_sub = {i: {"goed": [], "slecht": []} for i in interest}
        for T in cand_dt:
            bu = eng.best_upside(T)
            if bu is None:
                continue
            cls = _cls(bu)
            if cls == "middel":
                continue
            st = {s["i"]: s for s in eng.subrule_status(RULE, T)}
            for i in interest:
                v = st[i]["value"]
                if v not in (None, "PASS"):
                    per_sub[i][cls].append(float(v))
        for i in interest:
            s = subs[i]
            good = np.asarray(per_sub[i]["goed"], float)
            bad = np.asarray(per_sub[i]["slecht"], float)
            miss = np.asarray(blocked_good_vals[i], float)
            if not len(miss):
                continue
            bmin, bmax = s["b_min"], s["b_max"]
            prop = None
            # blocked-good below lower bound -> loosen lower to the bad edge
            if bmin is not None and miss.min() < float(bmin):
                bad_below = bad[bad < float(bmin)]
                edge = float(bad_below.max()) if len(bad_below) else float(miss.min())
                new_bad = int((bad >= edge).sum() - (bad >= float(bmin)).sum())
                gained = int(((miss >= edge) & (miss < float(bmin))).sum())
                prop = {"bound": "lower", "from": float(bmin), "to": round(edge, 5),
                        "missed_good_admitted": gained, "new_bad_admitted": new_bad}
            elif bmax is not None and miss.max() > float(bmax):
                bad_above = bad[bad > float(bmax)]
                edge = float(bad_above.min()) if len(bad_above) else float(miss.max())
                new_bad = int((bad <= edge).sum() - (bad <= float(bmax)).sum())
                gained = int(((miss <= edge) & (miss > float(bmax))).sum())
                prop = {"bound": "upper", "from": float(bmax), "to": round(edge, 5),
                        "missed_good_admitted": gained, "new_bad_admitted": new_bad}
            if prop:
                loosen_props.append({
                    "sym": sym, "subrule_index": i,
                    "subrule": f"{s['indicator']}/{s['subrulename']}/lb{int(s['def1_value']) if s['def1_value'] else 1}",
                    "blocks_n_missed_good": blocker_count[i], **prop})
    eng.close()
    return near_misses, loosen_props, len(no_trade)


def main():
    all_nm, all_loose, total_notrade = [], [], 0
    for sym in SYMS:
        nm, lp, nt = analyse_symbol(sym)
        for r in nm:
            r["sym"] = sym
        all_nm.extend(nm); all_loose.extend(lp); total_notrade += nt
    print(f"===== RQ4 — rule {RULE} — near-misses on promising periods WITHOUT a trade =====")
    print(f"no-trade promising periods scanned: {total_notrade} | "
          f"good near-miss moments (<{MAX_FAIL+1} subrules false): {len(all_nm)}")
    all_nm.sort(key=lambda r: (r["n_fail"], -r["best_upside"]))
    for r in all_nm[:12]:
        blk = ", ".join(f"{b['subrule']}(val={b['value']} band={b['band']})" for b in r["blockers"])
        print(f"  sym {r['sym']} {r['dt']} bu={r['best_upside']}% nfail={r['n_fail']} | blockers: {blk}")
    print("\n  --- proposed safe loosenings (admit missed good, 0 new bad over all candidates) ---")
    all_loose.sort(key=lambda r: (r["new_bad_admitted"], -r["missed_good_admitted"]))
    for r in all_loose:
        flag = "SAFE" if r["new_bad_admitted"] == 0 else f"adds {r['new_bad_admitted']} bad"
        print(f"  [{flag}] sym {r['sym']} loosen {r['subrule']} {r['bound']} {r['from']} -> {r['to']} "
              f"| admits {r['missed_good_admitted']} missed-good | blocks {r['blocks_n_missed_good']} near-misses")
    path = os.path.join(OUT, f"rq4_nearmiss_rule{RULE}.json")
    with open(path, "w") as f:
        json.dump({"near_misses": all_nm, "loosenings": all_loose,
                   "no_trade_periods": total_notrade}, f, indent=2, default=str)
    print(f"\nwrote {path}")


if __name__ == "__main__":
    main()
