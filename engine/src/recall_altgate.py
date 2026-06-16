#!/usr/bin/env python3
"""
recall_altgate — FASE 3 quality-gate probe (READ-ONLY, mutates nothing).

Tests the cleanest "alternative volume-trend" candidate gate: DROP the imported `volume_found=1`
pre-filter and let the brain's OWN volume_check (check_volumeud_3, per-rule settings) BE the candidate
gate. So a tick is a candidate iff some rule's volume_check passes there — exactly what the engine
already re-computes as the volume_check subrule, minus the legacy flag.

LEAN streaming implementation (no 144k-tick precompute — the old dict-based version got OOM-killed):
  Pass 1: stream every priced volumeud tick, build its 60-min vol-rows ONCE, test check_volumeud_3 per
          rule -> the alt-candidate set (ticks where some rule's volume passes). vol-rows are not stored.
  Pass 2: run the full rule (_fire_at) only on the alt-candidates -> fires; then single-position dedup +
          SellEngine + best_upside (identical to recall_shadow/persist_to_brain) -> executed good/bad.

Baseline gate='vf1' restricts pass-1 candidates to vf=1 ticks (== brain.coin_fires). Reports, per coin
and pooled: executed good/slecht/exec under both gates, the NEW executed good/bad sets, and the global
flood (alt-candidate count). Cheap to extend with a window for holdout splits.

Usage: recall_altgate.py [from_iso] [to_iso]      # optional window applied to BOTH gates (holdout)
"""
import bisect
import datetime as _dt
import json
import sys

from rule_engine import RuleEngine
from sell_engine import SellEngine
from config import FORWARD_MINUTES
from volume import check_volumeud_3, volume_settings
from recall_shadow import COINS, RULES, GOOD_EDGE, BAD_EDGE
from recall_worklist import (load, group, snap_vf1, best_upside as wl_best_upside,
                             covered_by_hold, CAUGHT_TOL)


def _in(dt, frm, to):
    return (frm is None or dt >= frm) and (to is None or dt < to)


def alt_candidates(eng, gate, frm=None, to=None):
    """Pass 1: candidate ticks. gate='vf1' -> vf=1 ticks; gate='all' -> ticks where some rule's
    volume_check passes. Streams vol-rows (built once per tick), stores nothing per-tick."""
    s = eng.series["volumeud"]
    minvol = {r: eng.minvol.get(r, 1e12) for r in RULES}
    vset = {r: volume_settings(r) for r in RULES}
    cands = []
    for i, dt in enumerate(s["dt"]):
        if s["p"][i] is None or not _in(dt, frm, to):
            continue
        if gate == "vf1":
            if s["vf"][i] == 1:
                cands.append(dt)
            continue
        rows = eng._vol_rows(dt, 60)            # built once, not retained
        if any(check_volumeud_3(rows, minvol[r], vset[r]) for r in RULES):
            cands.append(dt)
    return cands


def fires_on(eng, cands):
    """Pass 2: full rule evaluation on the candidate ticks only."""
    out = []
    for T in cands:
        for r in RULES:
            if eng._fire_at(r, T):
                out.append((T, r))
    out.sort()
    return out


def dedup_eval(fires, sell, DT, PX):
    """Single-position dedup + best_upside (mirrors recall_shadow.evaluate)."""
    def price_at(dt):
        i = bisect.bisect_right(DT, dt); return PX[i - 1] if i > 0 else None

    def best_upside(dt, buy):
        if not buy:
            return None
        lo = bisect.bisect_left(DT, dt); hi = bisect.bisect_right(DT, dt + _dt.timedelta(minutes=FORWARD_MINUTES))
        if lo >= hi:
            return None
        return round((max(PX[lo:hi]) - buy) / buy * 100, 3)

    open_until = None; g = b = n = 0
    eg, eb, ea = set(), set(), set(); holds = []
    for dt, rule in fires:
        buy = price_at(dt)
        if open_until is not None and dt <= open_until:
            continue
        sres = sell.sell(dt, buy, rule) if buy else None
        open_until = sres["selling_date"] if sres else dt
        holds.append((dt, open_until)); n += 1; ea.add(dt)
        bu = best_upside(dt, buy)
        if bu is not None:
            if bu >= GOOD_EDGE:
                g += 1; eg.add(dt)
            elif bu < BAD_EDGE:
                b += 1; eb.add(dt)
    return {"good": g, "bad": b, "exec": n, "exec_good": eg, "exec_bad": eb,
            "exec_all": ea, "holds": holds}


def evaluate_gate(sym, gate, frm=None, to=None):
    eng = RuleEngine(sym); sell = SellEngine(sym)
    cands = alt_candidates(eng, gate, frm, to)
    fires = fires_on(eng, cands)
    res = dedup_eval(fires, sell, sell.DT, sell.PX)
    res["n_cand"] = len(cands)
    eng.close(); sell.close()
    return res


# thin back-compat wrapper for agents importing AltGateEval
class AltGateEval:
    def __init__(self, sym, gate="all", frm=None, to=None):
        self.sym, self.gate, self.frm, self.to = sym, gate, frm, to

    def evaluate(self, overrides=None):
        if overrides:
            raise NotImplementedError("lean AltGateEval supports the empty-override flood test only")
        return evaluate_gate(self.sym, self.gate, self.frm, self.to)


def nocand_groups(sym):
    ok, fires, holds, DT, P, VF1 = load(sym)
    hstart = [h[0] for h in holds]
    out = []
    for g in group(ok, DT, P):
        lead, last = g[0], g[-1]
        direct = any(abs((f - m).total_seconds()) <= CAUGHT_TOL for m in g for f in fires)
        covered = covered_by_hold(holds, hstart, lead, last) if not direct else False
        if direct or covered or snap_vf1(VF1, lead, last) is not None:
            continue
        maxup = max((wl_best_upside(DT, P, m) or 0.0) for m in g)
        out.append({"lead": lead, "last": last, "maxup": round(maxup, 2)})
    return out


def caught_span(holds, lead, last, tol=180):
    for b, s in holds:
        if b - _dt.timedelta(seconds=tol) <= last and (s is None or s >= lead - _dt.timedelta(seconds=tol)):
            return True
    return False


def main():
    frm = _dt.datetime.fromisoformat(sys.argv[1]) if len(sys.argv) > 1 else None
    to = _dt.datetime.fromisoformat(sys.argv[2]) if len(sys.argv) > 2 else None
    win = f" [{frm} .. {to}]" if (frm or to) else ""
    print(f"=== recall_altgate — vf=1 gate vs live volume_check gate (lean full re-fire){win} ===", flush=True)
    pool = {"vf1": [0, 0, 0], "all": [0, 0, 0]}
    for sym in COINS:
        base = evaluate_gate(sym, "vf1", frm, to)
        alt = evaluate_gate(sym, "all", frm, to)
        new_good = alt["exec_good"] - base["exec_good"]
        new_bad = alt["exec_bad"] - base["exec_bad"]
        lost_good = base["exec_good"] - alt["exec_good"]
        print(f"\n--- coin {sym} ---", flush=True)
        print(f"  cand-ticks : vf1={base['n_cand']}  all={alt['n_cand']}", flush=True)
        print(f"  vf1-gate   : good={base['good']} bad={base['bad']} exec={base['exec']} "
              f"ratio={base['good']/max(base['bad'],1):.3f}", flush=True)
        print(f"  live-gate  : good={alt['good']} bad={alt['bad']} exec={alt['exec']} "
              f"ratio={alt['good']/max(alt['bad'],1):.3f}", flush=True)
        print(f"  delta      : good {alt['good']-base['good']:+d}  bad {alt['bad']-base['bad']:+d}  "
              f"exec {alt['exec']-base['exec']:+d} | nieuw-goed {len(new_good)} nieuw-slecht {len(new_bad)} "
              f"verloren-goed {len(lost_good)}", flush=True)
        ncg = nocand_groups(sym)
        caught = sum(1 for g in ncg if caught_span(alt["holds"], g["lead"], g["last"]))
        caught_good = sum(1 for g in ncg if g["maxup"] >= 3.0 and caught_span(alt["holds"], g["lead"], g["last"]))
        print(f"  no_candidate groepen: {len(ncg)} | gevangen onder live-gate: {caught} "
              f"(goed-upside: {caught_good})", flush=True)
        for k, r in (("vf1", base), ("all", alt)):
            pool[k][0] += r["good"]; pool[k][1] += r["bad"]; pool[k][2] += r["exec"]
    b, a = pool["vf1"], pool["all"]
    print("\n=== POOLED (beide coins) ===", flush=True)
    print(f"  vf1-gate : good={b[0]} bad={b[1]} exec={b[2]} ratio={b[0]/max(b[1],1):.3f}", flush=True)
    print(f"  live-gate: good={a[0]} bad={a[1]} exec={a[2]} ratio={a[0]/max(a[1],1):.3f}", flush=True)
    print(f"  delta    : good {a[0]-b[0]:+d}  bad {a[1]-b[1]:+d}  exec {a[2]-b[2]:+d}", flush=True)


if __name__ == "__main__":
    main()
