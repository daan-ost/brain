#!/usr/bin/env python3
"""
auto_loosen — RQ2: LOOSEN an existing subrule band to admit MORE good without new slecht (the other
half of the ratio; mirror of auto_apply which tightens). A loosening ADDS fires on non-trade
datetimes, so it can introduce new slecht — the report proved the cheap per-coin in-sample "0 new bad"
is misleading (3/11 candidates failed the full re-fire). So the gate is two-stage and conservative:

  (1) Full-HISTORY re-fire on BOTH coins, then classify each NEW fire on the REALIZED sell-engine
      profit_loss (slecht = pl<0, goed = pl>=3) — NOT best_upside, mirroring the tighten path and what
      Daan steers on. Reject if ANY new realized loser appears on either coin.
  (2) persist portfolio confirm: UPDATE the band, re-fire both coins, KEEP only if total executed GOOD
      strictly RISES and total executed SLECHT does NOT rise; else revert the band.

At most one loosening per rule per run. source stays the subrule's own; the band change is recorded in
rules_history (source='auto-loosened'). Candidates come from rq2_earlier.py. Builds nothing permanent
unless a candidate passes BOTH gates.

Usage (standalone): auto_loosen.py    — propose+apply now, print the journal.
"""
import json
import os
import subprocess
import sys

import opt_lib as o
import auto_apply as ap                      # reuse _totals(), _refire()
import rules_history
from db import brain
from opt_diag import DiagEngine
from sell_engine import SellEngine

PY = sys.executable
HERE = o.HERE
OUT = os.path.join(HERE, "..", "out", "opt")
RULES = (20, 21, 22, 23)


def gen_candidates(rule):
    """Run rq2_earlier for a rule (both coins) and return its candidates, strongest good first."""
    subprocess.run([PY, os.path.join(HERE, "rq2_earlier.py"), str(rule), "both"],
                   cwd=HERE, capture_output=True, text=True)
    path = os.path.join(OUT, f"rq2_earlier_rule{rule}.json")
    if not os.path.exists(path):
        return []
    cands = json.load(open(path))
    cands.sort(key=lambda c: -c["admitted_good"])
    return cands


def _parse(cand):
    """(indicator, subrulename, lookback, new_min, new_max-as-loosened-side) from a rq2_earlier cand."""
    ind, name, lb = cand["subrule"].split("/")
    lb = int(lb[2:])
    thr = cand["new_threshold"]
    if cand["loosen_bound"] == "lower":
        return ind, name, lb, ("lower", thr)
    return ind, name, lb, ("upper", thr)


def _find_index(eng, rule, ind, name, lb):
    for i, s in enumerate(eng.rules[rule]):
        sd1 = int(s["def1_value"]) if s["def1_value"] else 1
        if s["indicator"] == ind and s["subrulename"] == name and sd1 == lb:
            return i, s
    return None, None


def diag_new_fires(rule, ind, name, lb, bound, thr):
    """Full-history re-fire on BOTH coins with the loosened band; classify each NEW fire on the
    REALIZED sell-engine profit_loss (slecht = pl<0, goed = pl>=3) — mirrors the tighten path
    (opt_lib._cls_pl) and what Daan steers on, NOT the theoretical 60-min best_upside. A new fire
    sits on a non-trade datetime so it has no stored profit_loss; we simulate the sell from the
    candidate's buy price to get the realized result. Return (new_slecht, new_good)."""
    ns = ng = 0
    for sym in (o.DOGEAI, o.NOS):
        eng = DiagEngine(sym)
        i, s = _find_index(eng, rule, ind, name, lb)
        if i is None:
            eng.close(); continue
        nmin = thr if bound == "lower" else s["b_min"]
        nmax = thr if bound == "upper" else s["b_max"]
        base = set(eng.fires_override(rule, {}))
        loos = set(eng.fires_override(rule, {i: (nmin, nmax)}))
        new_dts = loos - base
        if new_dts:
            seng = SellEngine(sym)
            for dt in new_dts:
                buy = eng.price_at(dt)
                if not buy:
                    continue
                res = seng.sell(dt, buy, rule)
                if res is None:                  # geen ticks vooruit → niet te klasseren, sla over
                    continue
                pl = res["profit_loss"]
                ns += pl < o.BAD_PL
                ng += pl >= o.GOOD_PL
            seng.close()
        eng.close()
    return ns, ng


def set_band(rule, ind, name, lb, bound, thr):
    """UPDATE the subrule's loosened side to thr; keep the other side. Return (id, old_min, old_max)."""
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT id, b_min, b_max FROM rules WHERE rule_number=%s AND indicator=%s AND "
                  "subrulename=%s AND def1_value=%s AND active=1 LIMIT 1", (rule, ind, name, lb))
        r = c.fetchone()
        if not r:
            conn.close(); return None, None, None
        new_min = thr if bound == "lower" else r["b_min"]
        new_max = thr if bound == "upper" else r["b_max"]
        # keep the subrule's original source (provenance); the loosening is logged in rules_history.
        c.execute("UPDATE rules SET b_min=%s, b_max=%s, updated_at=NOW() WHERE id=%s",
                  (new_min, new_max, r["id"]))
    conn.commit(); conn.close()
    return r["id"], r["b_min"], r["b_max"]


def restore_band(sid, old_min, old_max):
    conn = brain()
    with conn.cursor() as c:
        c.execute("UPDATE rules SET b_min=%s, b_max=%s, updated_at=NOW() WHERE id=%s", (old_min, old_max, sid))
    conn.commit(); conn.close()


def loosen_safe(emit):
    """Try the strongest rq2 loosening per rule behind the two-stage gate. emit(message, level, rule, data)."""
    base_good, base_slecht, _ = ap._totals()
    applied, rejected = {}, 0            # {rule: toelichting} → rules_history (niet leeg, per critical-eye)
    for rule in RULES:
        cands = gen_candidates(rule)
        chosen = None
        for cand in cands[:6]:                       # try a few strongest; first to pass both gates wins
            ind, name, lb, (bound, thr) = _parse(cand)
            ns, ng = diag_new_fires(rule, ind, name, lb, bound, thr)
            if ns == 0 and ng >= 1:                  # gate 1: 0 nieuwe gerealiseerde verliezer + >=1 winnaar
                chosen = (cand, ind, name, lb, bound, thr, ng); break
        if not chosen:
            emit(f"rule {rule}: geen veilige loosening (alle kandidaten brengen nieuw slecht of geen goed).",
                 "info", rule, None)
            continue
        cand, ind, name, lb, bound, thr, ng = chosen
        label = f"{ind}/{name}/lb{lb} {bound}-grens → {round(thr, 5)}"
        sid, old_min, old_max = set_band(rule, ind, name, lb, bound, thr)
        if sid is None:
            emit(f"rule {rule}: subrule {ind}/{name}/lb{lb} niet gevonden — skip.", "info", rule, None); continue
        ap._refire()                                  # gate 2: portfolio confirm via real persist
        now_good, now_slecht, _ = ap._totals()
        if now_good > base_good and now_slecht <= base_slecht:
            emit(f"rule {rule}: band versoepeld ({label}). Goede trades {base_good}→{now_good} "
                 f"(+{now_good - base_good}), totaal slecht {base_slecht}→{now_slecht} (geen stijging). "
                 f"~{ng} extra goede full-history.", "change", rule,
                 {"candidate": cand, "good_total": [base_good, now_good], "slecht_total": [base_slecht, now_slecht]})
            applied[rule] = (f"Auto-versoepeld: {label}, goede {base_good}→{now_good} (+{now_good - base_good}), "
                             f"totaal slecht {base_slecht}→{now_slecht} (geen stijging).")
            base_good, base_slecht = now_good, now_slecht
        else:
            restore_band(sid, old_min, old_max)
            ap._refire()
            reason = (f"goede stegen niet ({base_good}→{now_good})" if now_good <= base_good
                      else f"slecht steeg ({base_slecht}→{now_slecht})")
            emit(f"rule {rule}: loosening {label} AFGEWEZEN door portfolio-refire — {reason}. Teruggedraaid.",
                 "info", rule, {"candidate": cand})
            rejected += 1

    if applied:
        subprocess.run([PY, os.path.join(HERE, "build_indicator_metrics.py")], cwd=HERE,
                       capture_output=True, text=True)
        rules_history.record(applied, source="auto-loosened", author="routine")
    return f"{len(applied)} versoepeld, {rejected} afgewezen"


if __name__ == "__main__":
    loosen_safe(lambda m, level="change", rule=None, data=None: print(f"[{level}] {m}"))
