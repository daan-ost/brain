#!/usr/bin/env python3
"""
recall_worklist — fill the promising_recall_state store (the RECALL worklist). READ-ONLY on the rules;
the only thing it writes is the worklist table. Per promising GROUP (ok-marked moments grouped like the
labeler): is the rise actually traded, which rule is it closest to (fewest failing FEATURE subrules via
DiagEngine.subrule_status), is volume the blocker, and the group's quality (max best_upside).

"Data is alles": the FACTUAL fields are recomputed every run; the routine-managed fields
(status / tried / resolution_note) are PRESERVED so the dossier accumulates across runs. A caught group
flips to status='caught'; a still-open group keeps whatever a routine recorded (in_progress /
needs_new_rule). See [[brain-promising-labeler]] for the ground-truth labels + grouping.

THE RECALL-MEASUREMENT FIXES (2026-06, before steering on the numbers — see the recall findings doc):
the v1 `caught` flag (an executed BUY within ±3min of a group moment) produced false-negatives and a
mislabeled blocker. Three corrections:

  (B) COVERED-by-open-position — a group whose rise we were ALREADY IN (an executed position's hold
      window [buy, selling_datetime] overlaps the group) is NOT a recall gap; the engine entered that
      rise. v1 missed it because the covering buy can sit >3min before the group lead. → caught=1,
      blocker='covered'. (e.g. DOGEAI 2025-03-04 03:42 r21 — covered by the 03:27 r21 position.)
  (A) NO-CANDIDATE (vf=0) — the engine only ever evaluates `volume_found=1` ticks (the candidate gate in
      rule_engine.fires). subrule_status does NOT apply that gate, so a vf=0 ok-moment showed "0 failing
      subrules" yet can never fire. We re-snap the lead to the nearest vf=1 candidate within the group
      window ±SNAP_TOL; if there is none → blocker='no_candidate' (un-tradeable as-marked, NOT a feature
      gap). This is the dominant NOS blocker (~80% of NOS ok-moments aren't on a candidate tick).
  Home-rule fails now count NON-volume failing subrules at the vf=1 candidate tick (the loosenable
  targets); volume is its own blocker when every rule's volume_check fails.

Grouping (approximates PromisingLabeler.php): a new group starts at a >5min gap OR a >=1% price drop
between consecutive ok-moments OR a manual group_break.

Usage: recall_worklist.py            # rebuild the worklist for the engine-evaluable coins (2525, 244)
"""
import bisect
import datetime as dt
import json

from db import brain
from opt_diag import DiagEngine
from config import FORWARD_MINUTES

GROUP_GAP = 300          # 5 min (PromisingLabeler GROUP_GAP_MIN)
GROUP_DROP = -0.01       # 1% dip (PromisingLabeler GROUP_DROP_PCT)
CAUGHT_TOL = 180         # an executed BUY within +/- 3 min of any group moment = directly caught
SNAP_TOL = 180           # re-snap the group lead to a vf=1 candidate within the window +/- 3 min
HOLD_MAX_MIN = 70        # an open position can hold up to FORWARD_MINUTES (+slack) — overlap search bound
COINS = [2525, 244]      # only coins with brain indicators can be subrule-evaluated
RULES = [20, 21, 22, 23]


def load(sym):
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT datetime, group_break FROM coin_moment_labels WHERE trading_symbol_id=%s "
                  "AND decision='yes' ORDER BY datetime", (sym,))
        ok = [(r["datetime"], r["group_break"]) for r in c.fetchall()]
        # executed BUY datetimes (direct caught) + hold windows [buy, sell] (covered-by-open-position)
        c.execute("SELECT datetime, selling_datetime FROM coin_fires WHERE trading_symbol_id=%s "
                  "AND is_executed=1", (sym,))
        fr = c.fetchall()
        fires = sorted({r["datetime"] for r in fr})
        holds = sorted((r["datetime"], r["selling_datetime"]) for r in fr if r["selling_datetime"])
        # volumeud price series + the candidate flag — read brain_volume_found (same source the motor
        # uses since the 2026-06-17 switch; see memory brain-volume-found-switch).
        c.execute("SELECT datetime, price, brain_volume_found AS volume_found FROM indicators WHERE trading_symbol_id=%s "
                  "AND indicator='volumeud' AND price IS NOT NULL ORDER BY datetime", (sym,))
        rows = c.fetchall()
    conn.close()
    DT = [r["datetime"] for r in rows]
    P = [float(r["price"]) for r in rows]
    VF1 = [r["datetime"] for r in rows if int(r["volume_found"]) == 1]
    return ok, fires, holds, DT, P, VF1


def price_at(DT, P, d):
    i = bisect.bisect_right(DT, d)
    return P[i - 1] if i > 0 else None


def best_upside(DT, P, d):
    i = bisect.bisect_right(DT, d)
    if i == 0:
        return None
    buy = P[i - 1]
    lo = bisect.bisect_left(DT, d)
    hi = bisect.bisect_right(DT, d + dt.timedelta(minutes=FORWARD_MINUTES))
    return (max(P[lo:hi]) - buy) / buy * 100 if lo < hi else None


def min_between(DT, P, a, b):
    lo, hi = bisect.bisect_right(DT, a), bisect.bisect_right(DT, b)
    return min(P[lo:hi]) if lo < hi else None


def group(ok, DT, P):
    groups, cur = [], []
    for d, gbreak in ok:
        if cur:
            gap = (d - cur[-1]).total_seconds()
            pa, mn = price_at(DT, P, cur[-1]), min_between(DT, P, cur[-1], d)
            drop = (mn - pa) / pa if (pa and mn) else 0.0
            if gbreak or gap > GROUP_GAP or drop <= GROUP_DROP:
                groups.append(cur); cur = []
        cur.append(d)
    if cur:
        groups.append(cur)
    return groups


def covered_by_hold(holds, hstart, a, b):
    """True iff an executed position's hold window [buy, sell] overlaps the group span [a, b]."""
    j = bisect.bisect_right(hstart, b)
    for k in range(j - 1, -1, -1):
        if holds[k][1] is not None and holds[k][1] >= a:
            return True
        if hstart[k] < a - dt.timedelta(minutes=HOLD_MAX_MIN):
            break
    return False


def snap_vf1(VF1, a, b):
    """Nearest vf=1 candidate tick inside [a-SNAP_TOL, b+SNAP_TOL], closest to the lead a; else None."""
    lo = bisect.bisect_left(VF1, a - dt.timedelta(seconds=SNAP_TOL))
    hi = bisect.bisect_right(VF1, b + dt.timedelta(seconds=SNAP_TOL))
    if lo >= hi:
        return None
    return min(VF1[lo:hi], key=lambda d: abs((d - a).total_seconds()))


def load_existing(conn, sym):
    """Existing worklist rows for the coin, for DOSSIER CARRY-OVER. group_lead is not a perfectly stable
    key (it shifts when a label is added before the lead, or the grouping changes), so a re-run must
    carry the routine-managed fields (status/tried/resolution_note) to the SAME rise — matched by span
    overlap — instead of pruning them. Without this the dossier fragments on every relabel."""
    with conn.cursor() as c:
        c.execute("SELECT group_lead, group_to, status, tried, resolution_note "
                  "FROM promising_recall_state WHERE trading_symbol_id=%s ORDER BY group_lead", (sym,))
        return c.fetchall()


def inherit(existing, used, lead, last):
    """The existing row whose dossier this group inherits: exact group_lead, else the most lead-close
    existing row whose span overlaps [lead, last] and is not yet claimed. Returns the row or None."""
    for e in existing:
        if e["group_lead"] == lead:
            return e
    cands = [e for e in existing if id(e) not in used
             and (e["group_to"] or e["group_lead"]) >= lead and e["group_lead"] <= last]
    return min(cands, key=lambda e: abs((e["group_lead"] - lead).total_seconds())) if cands else None


def main():
    conn = brain()
    summary = {}
    for sym in COINS:
        ok, fires, holds, DT, P, VF1 = load(sym)
        if not ok:
            continue
        hstart = [h[0] for h in holds]
        groups = group(ok, DT, P)
        existing = load_existing(conn, sym)      # dossier carry-over when a group's lead shifts
        used = set()
        eng = DiagEngine(sym)
        s = {"groups": len(groups), "caught": 0, "covered": 0, "no_candidate": 0,
             "volume": 0, "feature": 0, "home": {r: 0 for r in RULES}}
        for g in groups:
            lead, last = g[0], g[-1]
            maxup = max((best_upside(DT, P, m) or 0.0) for m in g)
            direct = any(abs((f - m).total_seconds()) <= CAUGHT_TOL for m in g for f in fires)
            covered = covered_by_hold(holds, hstart, lead, last) if not direct else False
            caught = direct or covered

            T = snap_vf1(VF1, lead, last)         # the real candidate tick the engine would evaluate
            cand = {}
            home = None
            home_fails = None
            blocker = None
            if caught:
                blocker = "direct" if direct else "covered"
            elif T is None:
                blocker = "no_candidate"          # not on/near a volume_found=1 tick -> can never fire
            else:
                # per-rule diagnostics at the candidate tick; count NON-volume fails (the loosenable ones)
                fails_nv, volfail, detail = {}, {}, {}
                for r in RULES:
                    st = eng.subrule_status(r, T)
                    nv = [x for x in st if x["passed"] is False and x["subrulename"] != "volume_check"]
                    fails_nv[r] = len(nv)
                    volfail[r] = any(x["subrulename"] == "volume_check" and x["passed"] is False for x in st)
                    detail[r] = [{"i": x["i"], "indicator": x["indicator"], "subrulename": x["subrulename"],
                                  "def1": x["def1"], "value": x["value"], "b_min": x["b_min"],
                                  "b_max": x["b_max"],
                                  "side": ("below_min" if (x["b_min"] is not None and x["value"] is not None
                                                           and x["value"] != "PASS" and x["value"] < float(x["b_min"]))
                                           else "above_max")} for x in nv]
                home = min(RULES, key=lambda r: fails_nv[r])
                home_fails = fails_nv[home]
                if home_fails == 0:
                    blocker = "shadow"            # fires at the candidate tick but only as a dedup shadow
                elif all(volfail.values()):
                    blocker = "volume"
                else:
                    blocker = "feature"
                cand = {"T": T.strftime("%Y-%m-%d %H:%M:%S"),
                        "fails_nv": {str(r): fails_nv[r] for r in RULES},
                        "volfail": {str(r): volfail[r] for r in RULES},
                        "home_fail_subrules": detail[home]}

            # roll up the summary
            if caught:
                s["caught" if direct else "covered"] += 1
            elif blocker in ("no_candidate", "volume", "feature"):
                s[blocker] += 1
            if home is not None:
                s["home"][home] += 1

            inh = inherit(existing, used, lead, last)   # carry the dossier from the SAME rise
            if inh is not None:
                used.add(id(inh))
            inh_status = inh["status"] if inh else None
            status = "caught" if caught else (inh_status if (inh_status and inh_status != "caught") else "open")
            inh_tried = inh["tried"] if inh else None            # already JSON text (or None) — passes through
            inh_resol = inh["resolution_note"] if inh else None
            with conn.cursor() as c:
                # tried/resolution_note are set on INSERT (carry-over) but PRESERVED on duplicate (exact lead).
                c.execute(
                    "INSERT INTO promising_recall_state (trading_symbol_id, group_lead, group_to, n_moments, "
                    "max_up_pct, caught, home_rule, home_rule_fails, candidate_rules, blocker, status, "
                    "tried, resolution_note, last_checked_at, created_at, updated_at) "
                    "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,NOW(),NOW(),NOW()) "
                    "ON DUPLICATE KEY UPDATE group_to=VALUES(group_to), n_moments=VALUES(n_moments), "
                    "max_up_pct=VALUES(max_up_pct), caught=VALUES(caught), home_rule=VALUES(home_rule), "
                    "home_rule_fails=VALUES(home_rule_fails), candidate_rules=VALUES(candidate_rules), "
                    "blocker=VALUES(blocker), status=VALUES(status), last_checked_at=NOW(), updated_at=NOW()",
                    (sym, lead, last, len(g), round(maxup, 2), int(caught), home, home_fails,
                     json.dumps(cand) if cand else None, blocker, status, inh_tried, inh_resol))
            conn.commit()
        # prune stale rows: group_leads no longer produced by the current grouping (e.g. after a
        # re-group when labels were added/removed). Keeps the worklist an exact mirror of the groups.
        leads = [g[0] for g in groups]
        with conn.cursor() as c:
            if leads:
                ph = ",".join(["%s"] * len(leads))
                c.execute(f"DELETE FROM promising_recall_state WHERE trading_symbol_id=%s "
                          f"AND group_lead NOT IN ({ph})", (sym, *leads))
                s["pruned"] = c.rowcount
        conn.commit()
        eng.close()
        summary[sym] = s
    conn.close()

    for sym, s in summary.items():
        caught = s["caught"] + s["covered"]
        rec = 100 * caught / s["groups"] if s["groups"] else 0
        print(f"coin {sym}: {s['groups']} promising-groepen | gevangen {caught} "
              f"(direct {s['caught']} + covered {s['covered']}) recall {rec:.0f}% | "
              f"gemist: no_candidate {s['no_candidate']} / volume {s['volume']} / feature {s['feature']}")
        print(f"   thuis-rule (minste falende feature-subrules): "
              + ", ".join(f"r{r}:{n}" for r, n in s["home"].items()))
    print("\nworklist -> brain.promising_recall_state (status/tried/resolution behouden over runs)")


if __name__ == "__main__":
    main()
