#!/usr/bin/env python3
"""
recall_nocand_diag — DIAGNOSE the no_candidate bucket (READ-ONLY, writes nothing to brain/rules/volume).

For the NOS (244) promising groups that recall_worklist marks blocker='no_candidate' (the ok-moment is
not on/near a volume_found=1 tick, so the engine can never fire there), split them into:

  (a) ALIGNMENT  — a vf=1 candidate tick sits CLOSE to the moment (just outside the ±SNAP_TOL window),
                   so a better snap / the +5s offset would reach it. No new volume signal needed.
  (b) SUB-THRESH — no nearby vf=1, BUT the brain's OWN volume_check (check_volumeud_3) PASSES for some
                   rule at the moment's (vf=0) volumeud tick. The volume IS there by the detector logic;
                   only the imported legacy flag says no. Unlockable by a live volume-candidate gate.
  (c) ABSENT     — no nearby vf=1 AND volume_check fails for every rule at every moment tick → genuinely
                   no tradeable volume. Not unlockable via volume.

Also: per bucket the QUALITY (best_upside good>=3 / mid / slecht<0.5) of the moments, so we see whether
the unlockable buckets are GOOD moments (worth catching) or a flood of bad.

Usage: recall_nocand_diag.py [symbol]      # default 244 (NOS); also accepts 2525 (DOGEAI) for cross-coin
"""
import bisect
import datetime as dt
import json
import sys

from db import brain
from opt_diag import DiagEngine
from recall_worklist import (load, group, snap_vf1, best_upside, covered_by_hold,
                             CAUGHT_TOL, SNAP_TOL)

RULES = [20, 21, 22, 23]
ALIGN_TOL = 300          # a vf=1 within this many seconds of the moment = "alignment" (just-missed snap)
GOOD, BAD = 3.0, 0.5


def cls(u):
    if u is None:
        return "none"
    return "goed" if u >= GOOD else ("slecht" if u < BAD else "middel")


def nearest_vf1(VF1, d):
    """Seconds to the nearest vf=1 tick (either side)."""
    if not VF1:
        return None
    i = bisect.bisect_left(VF1, d)
    cands = []
    if i < len(VF1):
        cands.append(abs((VF1[i] - d).total_seconds()))
    if i > 0:
        cands.append(abs((VF1[i - 1] - d).total_seconds()))
    return min(cands) if cands else None


def vol_check_pass(eng, rule, T):
    """Does the brain's volume_check (check_volumeud_3) pass for `rule` at T? + non-volume fail count."""
    st = eng.subrule_status(rule, T)
    volpass = any(x["subrulename"] == "volume_check" and x["passed"] for x in st)
    nv_fail = len([x for x in st if x["passed"] is False and x["subrulename"] != "volume_check"])
    return volpass, nv_fail


def main():
    sym = int(sys.argv[1]) if len(sys.argv) > 1 else 244
    ok, fires, holds, DT, P, VF1 = load(sym)
    hstart = [h[0] for h in holds]
    groups = group(ok, DT, P)
    eng = DiagEngine(sym)

    nocand = []
    for g in groups:
        lead, last = g[0], g[-1]
        direct = any(abs((f - m).total_seconds()) <= CAUGHT_TOL for m in g for f in fires)
        covered = covered_by_hold(holds, hstart, lead, last) if not direct else False
        if direct or covered:
            continue
        T = snap_vf1(VF1, lead, last)
        if T is not None:
            continue                              # has a candidate tick -> not no_candidate
        nocand.append(g)

    print(f"=== recall_nocand_diag — symbol {sym} ===")
    print(f"groepen totaal {len(groups)} | no_candidate {len(nocand)}\n")

    rows = []
    for g in nocand:
        lead, last = g[0], g[-1]
        # per-moment diagnostics
        m_near = []      # nearest vf=1 distance per moment
        m_live = []      # (volpass_any, best_nv_fail) per moment when volume_check passes
        m_up = []        # best_upside per moment
        live_any = False
        live_good = False
        best_nv_when_live = None
        for m in g:
            m_near.append(nearest_vf1(VF1, m))
            u = best_upside(DT, P, m)
            m_up.append(u)
            volpass_any = False
            min_nv = 99
            for r in RULES:
                vp, nv = vol_check_pass(eng, r, m)
                if vp:
                    volpass_any = True
                    min_nv = min(min_nv, nv)
            if volpass_any:
                live_any = True
                if (u or 0) >= GOOD:
                    live_good = True
                best_nv_when_live = min_nv if best_nv_when_live is None else min(best_nv_when_live, min_nv)
        near = min([x for x in m_near if x is not None], default=None)
        maxup = max([u for u in m_up if u is not None], default=None)

        if near is not None and near <= ALIGN_TOL:
            bucket = "a_alignment"
        elif live_any:
            bucket = "b_subthresh"
        else:
            bucket = "c_absent"
        rows.append({"lead": str(lead), "last": str(last), "n": len(g), "near_vf1_s": near,
                     "maxup": maxup, "klasse": cls(maxup), "bucket": bucket,
                     "live_vol": live_any, "live_good": live_good,
                     "nv_fail_when_live": best_nv_when_live})

    # ---- summary ----
    from collections import Counter
    bc = Counter(r["bucket"] for r in rows)
    print("BUCKET-VERDELING:")
    for b in ("a_alignment", "b_subthresh", "c_absent"):
        sub = [r for r in rows if r["bucket"] == b]
        kc = Counter(r["klasse"] for r in sub)
        good = sum(1 for r in sub if r["klasse"] == "goed")
        print(f"  {b:14s} {bc[b]:3d}  | kwaliteit {dict(kc)}  (goed={good})")

    # nearest-vf1 distance histogram over ALL no_candidate
    print("\nNEAREST vf=1 AFSTAND (s) — histogram over alle no_candidate moment-minima:")
    buckets_s = [(0, 60), (60, 180), (180, 300), (300, 600), (600, 1800), (1800, 3600), (3600, 10**9)]
    nears = [r["near_vf1_s"] for r in rows if r["near_vf1_s"] is not None]
    for lo, hi in buckets_s:
        n = sum(1 for x in nears if lo <= x < hi)
        print(f"  {lo:5d}-{hi if hi < 10**8 else '∞':>6}s : {n}")

    # bucket (b) unlock potential: how many no_candidate become candidate via live volume_check, and quality
    live_groups = [r for r in rows if r["live_vol"]]
    live_good = [r for r in live_groups if r["live_good"]]
    print(f"\nUNLOCK via live volume_check (bucket b-signaal): {len(live_groups)}/{len(rows)} groepen, "
          f"waarvan {len(live_good)} met een GOEDE moment (best_upside>=3%).")
    print(f"  kwaliteit van unlockbare groepen: {dict(Counter(r['klasse'] for r in live_groups))}")

    out = {"symbol": sym, "n_groups": len(groups), "n_nocand": len(rows),
           "buckets": dict(bc), "rows": rows}
    path = f"../out/opt/recall_nocand_{sym}.json"
    json.dump(out, open(path, "w"), indent=2)
    print(f"\n-> {path}")
    eng.close()


if __name__ == "__main__":
    main()
