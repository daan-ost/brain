#!/usr/bin/env python3
"""
analyse_spoor45.py — READ-ONLY analyse van roadmap-spoor 4 (promising-schoonmaken) en
spoor 5 (recall / witte vlekken) toegepast op rule 20-23 en 30.

Schrijft NIETS. Hergebruikt de groep-logica van recall_worklist.py.

Spoor 5: per promising-groep (yes-marks, gap>5min of >=1% dip = nieuwe groep) -> gevangen door
  {20-23} alleen vs {20-23,30} vs {30} alleen. Antwoord: heeft rule 30 (ongepoort) het recall-plafond
  doorbroken, en hoeveel WIT blijft (= waar een seed-tighten box nog iets toe te voegen heeft).
Spoor 4: splits de groepen naar labelbron (Daan eigen / auto-ok / legacy) en kijk hoe de recall +
  rule-beoordeling verschilt -> is de promising-set vervuild door de sell-gedreven auto-ok labels?
"""
import bisect
import datetime as dt
from collections import defaultdict

from db import brain

GROUP_GAP = 300
GROUP_DROP = -0.01
CAUGHT_TOL = 180
HOLD_MAX_MIN = 70
COINS = [(2525, "DOGEAI"), (244, "NOS")]
RULESETS = {"20-23": (20, 21, 22, 23), "30": (30,), "20-23+30": (20, 21, 22, 23, 30)}


def price_at(DT, P, d):
    i = bisect.bisect_right(DT, d)
    return P[i - 1] if i > 0 else None


def min_between(DT, P, a, b):
    lo, hi = bisect.bisect_right(DT, a), bisect.bisect_right(DT, b)
    return min(P[lo:hi]) if lo < hi else None


def best_upside(DT, P, d, fwd=60):
    i = bisect.bisect_right(DT, d)
    if i == 0:
        return 0.0
    buy = P[i - 1]
    lo = bisect.bisect_left(DT, d)
    hi = bisect.bisect_right(DT, d + dt.timedelta(minutes=fwd))
    return (max(P[lo:hi]) - buy) / buy * 100 if lo < hi else 0.0


def group(ok, DT, P):
    """ok = list of (datetime, group_break, set_by). Returns list of groups (each a list of those tuples)."""
    groups, cur = [], []
    for rec in ok:
        d, gbreak, _sb = rec
        if cur:
            prev = cur[-1][0]
            gap = (d - prev).total_seconds()
            pa, mn = price_at(DT, P, prev), min_between(DT, P, prev, d)
            drop = (mn - pa) / pa if (pa and mn) else 0.0
            if gbreak or gap > GROUP_GAP or drop <= GROUP_DROP:
                groups.append(cur); cur = []
        cur.append(rec)
    if cur:
        groups.append(cur)
    return groups


def covered_by_hold(holds_for_rule, hstart, a, b):
    j = bisect.bisect_right(hstart, b)
    for k in range(j - 1, -1, -1):
        if holds_for_rule[k][1] is not None and holds_for_rule[k][1] >= a:
            return True
        if hstart[k] < a - dt.timedelta(minutes=HOLD_MAX_MIN):
            break
    return False


def caught_by(ruleset, fires_by_rule, holds_by_rule, g):
    """True iff an executed buy of any rule in ruleset is within CAUGHT_TOL of any group moment,
    or its hold window overlaps the group span."""
    lead, last = g[0][0], g[-1][0]
    moments = [m[0] for m in g]
    for r in ruleset:
        fires = fires_by_rule.get(r, [])
        for f in fires:
            for m in moments:
                if abs((f - m).total_seconds()) <= CAUGHT_TOL:
                    return True
        holds = holds_by_rule.get(r, [])
        hstart = [h[0] for h in holds]
        if holds and covered_by_hold(holds, hstart, lead, last):
            return True
    return False


def group_source(g):
    """Bron-klasse van de groep: 'daan' als een echte handmatige mark erin zit, anders 'auto'
    (auto-ok) of 'legacy'."""
    sbs = {m[2] for m in g}
    if any(s and "@" in s for s in sbs):
        return "daan"
    if "auto-ok" in sbs:
        return "auto"
    return "legacy"


def main():
    conn = brain()
    for sym, name in COINS:
        with conn.cursor() as c:
            c.execute("SELECT datetime, group_break, set_by FROM coin_moment_labels "
                      "WHERE trading_symbol_id=%s AND decision='yes' ORDER BY datetime", (sym,))
            ok = [(r["datetime"], r["group_break"], r["set_by"]) for r in c.fetchall()]
            c.execute("SELECT rule, datetime, selling_datetime FROM coin_fires "
                      "WHERE trading_symbol_id=%s AND is_executed=1", (sym,))
            fr = c.fetchall()
            c.execute("SELECT datetime, price FROM indicators WHERE trading_symbol_id=%s "
                      "AND indicator='volumeud' AND price IS NOT NULL ORDER BY datetime", (sym,))
            rows = c.fetchall()
        DT = [r["datetime"] for r in rows]
        P = [float(r["price"]) for r in rows]
        fires_by_rule = defaultdict(list)
        holds_by_rule = defaultdict(list)
        for r in fr:
            fires_by_rule[r["rule"]].append(r["datetime"])
            if r["selling_datetime"]:
                holds_by_rule[r["rule"]].append((r["datetime"], r["selling_datetime"]))
        for r in fires_by_rule:
            fires_by_rule[r].sort()
        for r in holds_by_rule:
            holds_by_rule[r].sort()

        groups = group(ok, DT, P)
        n = len(groups)

        # Spoor 5: recall per ruleset, totaal + per bron
        by_src = defaultdict(lambda: {"n": 0, "20-23": 0, "30": 0, "20-23+30": 0,
                                      "wit_up": [], "n_up3": 0})
        tot = {"n": n, "20-23": 0, "30": 0, "20-23+30": 0, "wit_up3": 0, "wit_groups": []}
        for g in groups:
            src = group_source(g)
            by_src[src]["n"] += 1
            maxup = max(best_upside(DT, P, m[0]) for m in g)
            caughts = {}
            for label, rs in RULESETS.items():
                hit = caught_by(rs, fires_by_rule, holds_by_rule, g)
                caughts[label] = hit
                if hit:
                    tot[label] += 1
                    by_src[src][label] += 1
            if maxup >= 3:
                tot.setdefault("n_up3", 0)
                tot["n_up3"] = tot.get("n_up3", 0) + 1
                by_src[src]["n_up3"] += 1
            if not caughts["20-23+30"]:  # WIT: door geen enkele rule gevangen
                tot["wit_groups"].append((g[0][0], round(maxup, 1), src))
                if maxup >= 3:
                    tot["wit_up3"] += 1

        print(f"\n{'='*78}\n{name} ({sym}) — {n} promising-groepen (yes-marks)\n{'='*78}")
        print(f"  RECALL (gevangen door executed trades):")
        for label in ("20-23", "30", "20-23+30"):
            print(f"    {label:10s}: {tot[label]:4d}/{n}  ({100*tot[label]/n:.0f}%)")
        only30 = sum(1 for g in groups
                     if caught_by((30,), fires_by_rule, holds_by_rule, g)
                     and not caught_by((20, 21, 22, 23), fires_by_rule, holds_by_rule, g))
        print(f"    -> rule 30 vangt {only30} groepen die 20-23 MISTEN (de witte-vlek-winst van r30)")
        print(f"    -> WIT na 20-23+30: {n - tot['20-23+30']} groepen "
              f"(waarvan {tot['wit_up3']} met >=3% upside = echt gemiste winst)")

        print(f"  PER BRON (spoor 4 — is de promising-set vervuild?):")
        print(f"    {'bron':8s} {'#grp':>5s} {'r20-23':>7s} {'r30':>6s} {'beide':>7s} {'#up>=3%':>8s}")
        for src in ("daan", "auto", "legacy"):
            s = by_src[src]
            if s["n"] == 0:
                continue
            print(f"    {src:8s} {s['n']:5d} "
                  f"{100*s['20-23']/s['n']:6.0f}% {100*s['30']/s['n']:5.0f}% "
                  f"{100*s['20-23+30']/s['n']:6.0f}% {s['n_up3']:8d}")
    conn.close()


if __name__ == "__main__":
    main()
