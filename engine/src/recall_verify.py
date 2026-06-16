#!/usr/bin/env python3
"""
recall_verify — INDEPENDENT cross-check of the recall_shadow result via the SEPARATE, oracle-validated
DiagEngine (opt_diag) — the engine path auto_loosen/rq2 use. Read-only, mutates nothing.

Two checks per accepted override stack {rule: {subrule_index: (b_min, b_max)}}:
  (1) FIRE-SET IDENTITY — for each coin, each rule, the fire datetimes from DiagEngine.fires_override
      must EXACTLY equal recall_shadow.RecallEval.fires_for_override. Two independent implementations of
      the override-fire logic agreeing = the shadow's firing is trustworthy.
  (2) PORTFOLIO RE-DEDUP — feed the DiagEngine fire set through an independent single-position dedup +
      SellEngine + best_upside and recompute executed good/slecht. Must match the shadow's pooled good/bad.

Usage: recall_verify.py [stack_json_path]    # default: ../out/opt/recall_loop.json (accepted_stack)
"""
import bisect
import datetime as _dt
import json
import sys

from config import FORWARD_MINUTES
from opt_diag import DiagEngine
from sell_engine import SellEngine
from recall_shadow import RecallEval, COINS, RULES, GOOD_EDGE, BAD_EDGE


def diag_fires(eng, stack):
    """{rule: [fire dts]} via DiagEngine.fires_override (independent engine)."""
    return {r: eng.fires_override(r, {int(i): tuple(b) for i, b in stack.get(str(r), stack.get(r, {})).items()})
            for r in RULES}


def independent_dedup(fires_by_rule, sell, DT, PX):
    """Single-position dedup + best_upside over a DiagEngine fire set — reimplemented from scratch."""
    fires = sorted((dt, r) for r, dts in fires_by_rule.items() for dt in dts)
    def price_at(dt):
        i = bisect.bisect_right(DT, dt); return PX[i - 1] if i > 0 else None
    def best_upside(dt, buy):
        if not buy: return None
        lo = bisect.bisect_left(DT, dt); hi = bisect.bisect_right(DT, dt + _dt.timedelta(minutes=FORWARD_MINUTES))
        if lo >= hi: return None
        return round((max(PX[lo:hi]) - buy) / buy * 100, 3)
    open_until = None; g = b = 0; eg, eb = set(), set()
    for dt, rule in fires:
        buy = price_at(dt)
        if open_until is not None and dt <= open_until:
            continue
        sres = sell.sell(dt, buy, rule) if buy else None
        open_until = sres["selling_date"] if sres else dt
        bu = best_upside(dt, buy)
        if bu is not None:
            if bu >= GOOD_EDGE: g += 1; eg.add(dt)
            elif bu < BAD_EDGE: b += 1; eb.add(dt)
    return g, b, eg, eb


def main():
    path = sys.argv[1] if len(sys.argv) > 1 else "../out/opt/recall_loop.json"
    data = json.load(open(path))
    stack = data["accepted_stack"]
    print(f"=== recall_verify — onafhankelijke kruiscontrole van accepted_stack ===")
    print(f"stack: {json.dumps(stack)}")
    overall_ok = True
    tot_sg = tot_sb = tot_dg = tot_db = 0
    for sym in COINS:
        ev = RecallEval(sym)
        de = DiagEngine(sym)
        sell = SellEngine(sym)
        DT, PX = sell.DT, sell.PX
        # (1) fire-set identity per rule
        rule_ok = True
        for r in RULES:
            ovr = {int(i): tuple(b) for i, b in stack.get(str(r), {}).items()}
            sh = set(ev.fires_for_override(r, ovr))
            dg = set(de.fires_override(r, ovr))
            if sh != dg:
                rule_ok = False
                print(f"  coin {sym} r{r}: MISMATCH shadow={len(sh)} diag={len(dg)} "
                      f"only_shadow={sorted(str(x) for x in (sh-dg))[:5]} only_diag={sorted(str(x) for x in (dg-sh))[:5]}")
        # (2) independent portfolio re-dedup vs shadow
        sh_res = ev.evaluate({int(r): {int(i): tuple(b) for i, b in subs.items()}
                              for r, subs in stack.items()})
        dfires = {r: list(de.fires_override(r, {int(i): tuple(b) for i, b in stack.get(str(r), {}).items()}))
                  for r in RULES}
        dg_good, dg_bad, _, _ = independent_dedup(dfires, sell, DT, PX)
        match = (sh_res["good"] == dg_good and sh_res["bad"] == dg_bad)
        overall_ok = overall_ok and rule_ok and match
        tot_sg += sh_res["good"]; tot_sb += sh_res["bad"]; tot_dg += dg_good; tot_db += dg_bad
        print(f"  coin {sym}: fire-set identiek={rule_ok} | shadow good/bad {sh_res['good']}/{sh_res['bad']} "
              f"vs onafhankelijke dedup {dg_good}/{dg_bad} | MATCH={match}")
        ev.close(); de.close(); sell.close()
    print(f"\npooled shadow {tot_sg}/{tot_sb} vs onafhankelijk {tot_dg}/{tot_db} | "
          f"ALLES KLOPT={overall_ok}")
    sys.exit(0 if overall_ok else 1)


if __name__ == "__main__":
    main()
