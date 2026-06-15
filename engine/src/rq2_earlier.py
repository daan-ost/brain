#!/usr/bin/env python3
"""
RQ2 — EERDER KOPEN zonder nieuwe slechte. For each EXISTING subrule of a rule, find the candidate
moments where that subrule is the SOLE blocker (every other subrule already passes). Among those
sole-blocked moments, loosen the subrule's binding bound to the BAD EDGE (just before a bad
sole-blocked moment) so the GOOD sole-blocked moments are admitted with ZERO new bad. Report the
captured good — split into "earlier entry in an existing move" (there is already a fire LATER in
the same promising period; the new entry has more best_upside) vs a brand-new good opportunity.

Read-only, creates only JSON under engine/out/opt/. Heavier (re-evaluates every candidate moment).
Usage: rq2_earlier.py [rule] [symbol|both]
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
SYMS = [o.DOGEAI, o.NOS] if WHICH == "both" else [int(WHICH)]

OUT = os.path.join(o.HERE, "..", "out", "opt")
os.makedirs(OUT, exist_ok=True)


def bad_edge_loosen(good_vals, bad_vals, side):
    """Loosen toward `side` ('below' = lower the lower-bound; 'above' = raise the upper-bound).
    We want to ADMIT good values that sit just beyond the current band, stopping at the bad edge.
    Returns (new_threshold, n_good_admitted_safely) or None if no clean gap."""
    good = np.asarray(good_vals, float)
    bad = np.asarray(bad_vals, float)
    if side == "below":
        # admit goods below current band; stop just above the highest bad that is below them
        if not len(good):
            return None
        floor = good.min()
        bad_below = bad[bad < floor]
        edge = float(bad_below.max()) if len(bad_below) else float(min(good.min(), bad.min() if len(bad) else good.min()) - 1)
        # new lower bound sits at the bad edge: admits good >= edge, excludes bad < edge
        admitted = int((good >= edge).sum())
        new_bad = int((bad >= edge).sum())
        return {"bound": "lower", "threshold": round(edge, 5), "admitted_good": admitted, "new_bad": new_bad}
    else:
        if not len(good):
            return None
        ceil = good.max()
        bad_above = bad[bad > ceil]
        edge = float(bad_above.min()) if len(bad_above) else float(max(good.max(), bad.max() if len(bad) else good.max()) + 1)
        admitted = int((good <= edge).sum())
        new_bad = int((bad <= edge).sum())
        return {"bound": "upper", "threshold": round(edge, 5), "admitted_good": admitted, "new_bad": new_bad}


def analyse_symbol(sym):
    eng = DiagEngine(sym)
    periods, traded = promising_verdicts(sym)
    spans = [(p["period_from"], p["period_to"], p["id"]) for p in periods]

    # existing executed fires of this rule, per period, with datetime + best_upside
    conn = o.brain()
    with conn.cursor() as c:
        c.execute("SELECT datetime, period_id, best_upside FROM coin_fires WHERE trading_symbol_id=%s "
                  "AND rule=%s AND is_executed=1", (sym, RULE))
        exec_fires = c.fetchall()
    conn.close()
    fires_by_period = defaultdict(list)
    for r in exec_fires:
        fires_by_period[r["period_id"]].append((r["datetime"], r["best_upside"]))

    def period_of(dt):
        for a, b, pid in spans:
            if a <= dt <= b:
                return pid
        return None

    # subrule meta (index -> descriptor); only loosenable metric/value subrules (skip volume/missingdata)
    subs = eng.rules[RULE]
    LOOSENABLE = {i: s for i, s in enumerate(subs)
                  if s["subrulename"] not in ("volume_check", "missingdata")}

    # scan every candidate moment: which subrules fail? collect sole-blocked values per subrule.
    sole = defaultdict(lambda: {"goed": [], "slecht": [], "middel": [], "moments": []})  # i -> values+meta
    cands = eng.candidates()
    for T in cands:
        st = eng.subrule_status(RULE, T)
        fails = [s for s in st if s["passed"] is False]
        if len(fails) != 1:
            continue
        f = fails[0]
        i = f["i"]
        if i not in LOOSENABLE or f["value"] in (None, "PASS"):
            continue
        bu = eng.best_upside(T)
        if bu is None:
            continue
        cls = _cls(bu)
        sole[i][cls].append(float(f["value"]))
        sole[i]["moments"].append({"dt": T, "value": float(f["value"]), "best_upside": bu, "cls": cls,
                                   "period": period_of(T)})

    results = []
    for i, data in sole.items():
        s = subs[i]
        good_vals, bad_vals = data["goed"], data["slecht"]
        if len(good_vals) < 3:
            continue
        # decide which side the good sole-blocked values sit relative to the current band
        bmin, bmax = s["b_min"], s["b_max"]
        gmin, gmax = min(good_vals), max(good_vals)
        proposals = []
        # goods below current lower bound -> loosen lower
        if bmin is not None and gmin < float(bmin):
            p = bad_edge_loosen(good_vals, bad_vals, "below")
            if p and p["new_bad"] == 0 and p["admitted_good"] > 0:
                proposals.append(("lower", float(bmin), p))
        # goods above current upper bound -> loosen upper
        if bmax is not None and gmax > float(bmax):
            p = bad_edge_loosen(good_vals, bad_vals, "above")
            if p and p["new_bad"] == 0 and p["admitted_good"] > 0:
                proposals.append(("upper", float(bmax), p))
        for side, cur, p in proposals:
            # of the admitted good moments, which are EARLIER entries in a move already caught?
            adm = [m for m in data["moments"] if m["cls"] == "goed" and (
                   (p["bound"] == "lower" and m["value"] >= p["threshold"]) or
                   (p["bound"] == "upper" and m["value"] <= p["threshold"]))]
            earlier, newopp = [], []
            for m in adm:
                later = [bu for (dt, bu) in fires_by_period.get(m["period"], []) if dt > m["dt"]]
                if m["period"] is not None and later:
                    earlier.append({"dt": str(m["dt"]), "best_upside": m["best_upside"],
                                    "vs_later_fire_max_bu": max(later)})
                else:
                    newopp.append({"dt": str(m["dt"]), "best_upside": m["best_upside"], "period": m["period"]})
            results.append({
                "sym": sym, "rule": RULE, "subrule_index": i,
                "subrule": f"{s['indicator']}/{s['subrulename']}/lb{int(s['def1_value']) if s['def1_value'] else 1}",
                "current_band": [bmin, bmax], "loosen_bound": p["bound"],
                "current_threshold": cur, "new_threshold": p["threshold"],
                "admitted_good": p["admitted_good"], "new_bad": p["new_bad"],
                "n_bad_sole_blocked": len(bad_vals),
                "earlier_in_move": earlier, "new_opportunities": newopp,
                "best_upside_gained": round(sum(m["best_upside"] for m in adm), 1)})
    eng.close()
    return results


def main():
    allres = []
    for sym in SYMS:
        allres.extend(analyse_symbol(sym))
    allres.sort(key=lambda r: -r["admitted_good"])
    print(f"===== RQ2 — rule {RULE} — earlier/looser entry (0 new bad, bad-edge) =====")
    if not allres:
        print("  no sole-blocked good moments with a clean bad-edge loosening found")
    for r in allres:
        print(f"\n  sym {r['sym']} | loosen {r['subrule']} {r['loosen_bound']} "
              f"{r['current_threshold']} -> {r['new_threshold']}")
        print(f"     admits {r['admitted_good']} extra GOOD (0 new bad; {r['n_bad_sole_blocked']} bad sole-blocked stayed out) "
              f"| best_upside gained ~{r['best_upside_gained']}%")
        print(f"     of which EARLIER-in-an-existing-move: {len(r['earlier_in_move'])}, new opportunities: {len(r['new_opportunities'])}")
    path = os.path.join(OUT, f"rq2_earlier_rule{RULE}.json")
    with open(path, "w") as f:
        json.dump(allres, f, indent=2, default=str)
    print(f"\nwrote {path}")


if __name__ == "__main__":
    main()
