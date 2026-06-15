#!/usr/bin/env python3
"""
DEFINITIVE full-period re-fire check — replay the WHOLE rule over the FULL candidate history of BOTH
coins, not only the current trades. A boundary change admits/removes fires at datetimes that are not
current trades, so the only honest test is to replay the entire history and classify every changed
fire by best_upside. This is the "doorloop alles opnieuw" test.

Two modes:
  rq2_refire_check.py [rule]        RQ2 loosenings: re-fire with each looser band, count NEW fires
                                    (a new bad fire here disqualifies the loosening).
  rq2_refire_check.py [rule] rq1    RQ1 tightenings: re-fire with the extra AND-subrule, confirm
                                    0 fires added and 0 EXECUTED-good trades lost (shadows may drop).

Read-only, creates nothing.
"""
import sys
from collections import Counter

import opt_lib as o
from opt_diag import DiagEngine, _cls

RULE = int(sys.argv[1]) if len(sys.argv) > 1 else 21
MODE = sys.argv[2] if len(sys.argv) > 2 else "rq2"

# RQ1 recommended tightenings (subrulename = window-metric name; value_condition None)
ADD_RQ1 = {
    20: ("vzo", "range_percentage", 17, -44.30233, None),
    21: ("volumeud", "diff_percentage_prev_max", 9, 158.83697, None),
    22: ("volumeud", "median_value", 5, 0.15143, None),
    23: ("vzo", "diff_number_prev_min", 20, None, -1.2),
}

# RQ2 candidate loosenings from the report (rule -> list of (indicator, subrulename, def1, new_bmin, new_bmax))
# new_b* = the proposed band AFTER loosening; None keeps that side as-is (filled from current below).
CANDS = {
    20: [("vzo", "previous_value", 7, None, 55.0),
         ("obv-x-value", "previous_value", 3, -1.0, None),
         ("phobos", "skewness", 11, -2.20553, None)],
    21: [("vzo", "skewness", 5, None, 2.49739),
         ("volumeud", "previous_value", 10, -5.8, None)],
    22: [("obv-x-value", "range_percentage", 14, -0.32496, None),
         ("obv-x-value", "currentvalue", 1, 33.3, None),
         ("volumeud", "previous_value", 3, -3.1, None)],
    23: [("volumeud", "previous_value", 5, -5.3, None),
         ("phobos", "volatility", 5, -0.7, None),
         ("volumeud", "previous_value", 7, -4.3, None)],
}


def find_subrule_index(eng, rule, ind, name, def1):
    for i, s in enumerate(eng.rules[rule]):
        sd1 = int(s["def1_value"]) if s["def1_value"] else 1
        if s["indicator"] == ind and s["subrulename"] == name and sd1 == def1:
            return i, s
    return None, None


def classify_fires(eng, dts):
    c = Counter()
    for dt in dts:
        bu = eng.best_upside(dt)
        c[_cls(bu) if bu is not None else "geen_prijs"] += 1
    return c


def executed_good_dts():
    """(sym,rule) -> set of EXECUTED good-trade datetimes from coin_fires (for the RQ1 check)."""
    conn = o.brain()
    with conn.cursor() as c:
        c.execute("SELECT trading_symbol_id sym, rule, datetime FROM coin_fires "
                  "WHERE is_executed=1 AND best_upside>=3")
        rows = c.fetchall()
    conn.close()
    out = {}
    for r in rows:
        out.setdefault((r["sym"], r["rule"]), set()).add(r["datetime"])
    return out


if MODE == "rq1":
    ind, name, lb, bmin, bmax = ADD_RQ1[RULE]
    geg = executed_good_dts()
    print(f"=== RQ1 full-period RE-FIRE check — rule {RULE} (extra subrule {ind}/{name}/lb{lb}) ===")
    print("Tightening kan alleen fires WEGNEMEN; bevestig 0 toegevoegd en 0 EXECUTED-goede trade verloren.\n")
    for sym in (o.DOGEAI, o.NOS):
        eng = DiagEngine(sym)
        base = set(eng.fires_override(RULE, {}))
        eng.rules[RULE] = eng.rules[RULE] + [{"rule_number": RULE, "sort": 9999, "indicator": ind,
            "subrulename": name, "def1_value": lb, "b_min": bmin, "b_max": bmax, "value_condition": None}]
        tight = set(eng.fires_override(RULE, {}))
        added = tight - base
        removed = base - tight
        eg = geg.get((sym, RULE), set())
        eg_lost = [dt for dt in eg if dt not in tight]
        rem_good = sum(1 for dt in removed if (lambda b: b is not None and b >= 3)(eng.best_upside(dt)))
        print(f"  sym {sym}: base {len(base)} fires -> +subrule {len(tight)} | "
              f"toegevoegd {len(added)} | verwijderd {len(removed)} (waarvan goed-shadow {rem_good}) | "
              f"EXECUTED-goede trades verloren: {len(eg_lost)}/{len(eg)} "
              f"{'OK' if not added and not eg_lost else '>>> PROBLEEM <<<'}")
        eng.close()
    sys.exit(0)

print(f"=== RQ2 full-period RE-FIRE check — rule {RULE} ===")
print("Per kandidaat: hele rule opnieuw over de VOLLEDIGE periode met de ruimere band; "
      "nieuwe fires t.o.v. baseline geclassificeerd op best_upside.\n")

for sym in (o.DOGEAI, o.NOS):
    eng = DiagEngine(sym)
    baseline = set(eng.fires_override(RULE, {}))            # current rule fires over full history
    base_cls = classify_fires(eng, baseline)
    print(f"--- sym {sym} | baseline fires (volledige periode): {len(baseline)} "
          f"({dict(base_cls)}) ---")
    for ind, name, def1, nb_min, nb_max in CANDS.get(RULE, []):
        i, s = find_subrule_index(eng, RULE, ind, name, def1)
        if i is None:
            print(f"  [{ind}/{name}/lb{def1}] subrule niet gevonden op deze coin — skip")
            continue
        cur_min, cur_max = s["b_min"], s["b_max"]
        new_min = nb_min if nb_min is not None else cur_min
        new_max = nb_max if nb_max is not None else cur_max
        loosened = set(eng.fires_override(RULE, {i: (new_min, new_max)}))
        new_fires = loosened - baseline
        lost_fires = baseline - loosened          # should be empty for a pure loosening
        nc = classify_fires(eng, new_fires)
        flag = "OK (0 nieuw slecht)" if nc.get("slecht", 0) == 0 else f">>> {nc.get('slecht',0)} NIEUW SLECHT <<<"
        print(f"  loosen {ind}/{name}/lb{def1}: [{cur_min},{cur_max}] -> [{new_min},{new_max}]")
        print(f"     +{len(new_fires)} nieuwe fires {dict(nc)} | -{len(lost_fires)} verloren | {flag}")
    eng.close()
    print()
