#!/usr/bin/env python3
"""
recall_worklist — fill the promising_recall_state store (the RECALL worklist). READ-ONLY on the rules;
the only thing it writes is the worklist table. Per promising GROUP (ok-marked moments grouped like the
labeler): is it caught by an executed trade, which rule is it closest to (fewest failing subrules via
DiagEngine.subrule_status), is volume the blocker, and the group's quality (max best_upside).

"Data is alles": the FACTUAL fields are recomputed every run; the routine-managed fields
(status / tried / resolution_note) are PRESERVED so the dossier accumulates across runs. A caught group
flips to status='caught'; a still-open group keeps whatever a routine recorded (in_progress /
needs_new_rule). See [[brain-promising-labeler]] for the ground-truth labels + grouping.

Grouping (approximates PromisingLabeler.php): a new group starts at a >5min gap OR a >=1% price drop
between consecutive ok-moments OR a manual group_break. NB: align exactly with the labeler (incl.
manual group_break) when that screen is final — this is the v1 foundation.

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
CAUGHT_TOL = 180         # an executed trade within +/- 3 min of any group moment = caught
COINS = [2525, 244]      # only coins with brain indicators can be subrule-evaluated
RULES = [20, 21, 22, 23]


def load(sym):
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT datetime, group_break FROM coin_moment_labels WHERE trading_symbol_id=%s "
                  "AND decision='yes' ORDER BY datetime", (sym,))
        ok = [(r["datetime"], r["group_break"]) for r in c.fetchall()]
        c.execute("SELECT datetime FROM coin_fires WHERE trading_symbol_id=%s AND is_executed=1", (sym,))
        fires = sorted({r["datetime"] for r in c.fetchall()})
        c.execute("SELECT datetime, price FROM indicators WHERE trading_symbol_id=%s AND indicator='volumeud' "
                  "AND price IS NOT NULL ORDER BY datetime", (sym,))
        rows = c.fetchall()
    conn.close()
    return ok, fires, [r["datetime"] for r in rows], [float(r["price"]) for r in rows]


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


def main():
    conn = brain()
    summary = {}
    for sym in COINS:
        ok, fires, DT, P = load(sym)
        if not ok:
            continue
        groups = group(ok, DT, P)
        eng = DiagEngine(sym)
        s = {"groups": len(groups), "caught": 0, "volume": 0, "feature": 0, "home": {r: 0 for r in RULES}}
        for g in groups:
            lead, last = g[0], g[-1]
            caught = any(abs((f - m).total_seconds()) <= CAUGHT_TOL for m in g for f in fires)
            maxup = max((best_upside(DT, P, m) or 0.0) for m in g)
            i = bisect.bisect_right(DT, lead)
            T = DT[i - 1] if i > 0 else lead
            fails, volfail = {}, {}
            for r in RULES:
                st = eng.subrule_status(r, T)
                fails[r] = sum(1 for x in st if x["passed"] is False)
                volfail[r] = any(x["subrulename"] == "volume_check" and x["passed"] is False for x in st)
            home = min(RULES, key=lambda r: fails[r])
            blocker = "caught" if caught else ("volume" if all(volfail.values()) else "feature")
            s["caught" if caught else blocker] += 1 if caught else 0
            if not caught:
                s[blocker] += 1
            s["home"][home] += 1

            with conn.cursor() as c:
                c.execute("SELECT status FROM promising_recall_state WHERE trading_symbol_id=%s AND group_lead=%s",
                          (sym, lead))
                ex = c.fetchone()
            # caught flips to 'caught'; an open group keeps its routine-recorded status; new = 'open'
            status = "caught" if caught else (ex["status"] if (ex and ex["status"] != "caught") else "open")
            with conn.cursor() as c:
                c.execute(
                    "INSERT INTO promising_recall_state (trading_symbol_id, group_lead, group_to, n_moments, "
                    "max_up_pct, caught, home_rule, home_rule_fails, candidate_rules, blocker, status, "
                    "last_checked_at, created_at, updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,NOW(),NOW(),NOW()) "
                    "ON DUPLICATE KEY UPDATE group_to=VALUES(group_to), n_moments=VALUES(n_moments), "
                    "max_up_pct=VALUES(max_up_pct), caught=VALUES(caught), home_rule=VALUES(home_rule), "
                    "home_rule_fails=VALUES(home_rule_fails), candidate_rules=VALUES(candidate_rules), "
                    "blocker=VALUES(blocker), status=VALUES(status), last_checked_at=NOW(), updated_at=NOW()",
                    (sym, lead, last, len(g), round(maxup, 2), caught, home, fails[home],
                     json.dumps({str(r): fails[r] for r in RULES}), blocker, status))
            conn.commit()
        eng.close()
        summary[sym] = s
    conn.close()

    for sym, s in summary.items():
        rec = 100 * s["caught"] / s["groups"] if s["groups"] else 0
        print(f"coin {sym}: {s['groups']} promising-groepen | gevangen {s['caught']} (recall {rec:.0f}%) | "
              f"gemist: volume {s['volume']} / feature {s['feature']}")
        print(f"   thuis-rule (minste falende subrules): " + ", ".join(f"r{r}:{n}" for r, n in s["home"].items()))
    print("\nworklist -> brain.promising_recall_state (status/tried/resolution behouden over runs)")


if __name__ == "__main__":
    main()
