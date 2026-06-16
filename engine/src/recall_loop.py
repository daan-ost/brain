#!/usr/bin/env python3
"""
recall_loop — the BOUNDED, 1-by-1, CUMULATIVE tweak loop over the genuine FEATURE-missed promising
groups (STAP 2). Read-only on the rules: it proposes loosens and measures them via the recall_shadow
in-memory full re-fire; it APPLIES NOTHING. The accepted loosens are saved to the worklist as a
PROPOSAL (status='proposed_catch' + the tweak in `tried`) for a later gated routine — never written to
brain.rules here.

Per target (a feature-missed group, processed fails-asc then upside-desc):
  1. the loosen = widen each failing FEATURE subrule of the home rule to admit the group's value
     (≤3 subrules — the group's own bound). below_min -> b_min:=value-EPS ; above_max -> b_max:=value+EPS.
  2. candidate stack = the accumulated accepted stack UNION this loosen (same (rule,i) -> most permissive).
  3. SHADOW both coins: caught? (the group's vf=1 tick now produces an executed/covered trade) and the
     INCREMENTAL new executed slecht vs the running accepted stack.
  4. ACCEPT (soft recall-gate) iff: caught AND incremental new slecht ≤ 1 AND the pooled good/bad ratio
     does not materially drop (>=98% of the empty-stack baseline) — the cumulative brake.
Accepted loosens accumulate (so a later group can ride a band already widened, and the brake sees the
full collateral). Rejected = the tweak catches but costs too much (rejected_collateral) or no loosen
catches it (needs_new_rule, parked).

Usage: recall_loop.py [--maxfails N] [--write]    # --write persists the result to the worklist
"""
import datetime as _dt
import json
import sys

from db import brain
from recall_shadow import RecallEval, caught_at, COINS, RULES, GOOD_EDGE, BAD_EDGE

EPS = 1e-6
RATIO_FLOOR_FRAC = 0.98        # pooled good/bad must stay >= 98% of the baseline ratio (cumulative brake)
MAX_NEW_BAD = 1                # per-group: at most 1 new executed slecht is acceptable


def load_targets(maxfails):
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT id, trading_symbol_id sym, group_lead, group_to, max_up_pct, home_rule, "
                  "home_rule_fails, candidate_rules FROM promising_recall_state "
                  "WHERE caught=0 AND blocker='feature' AND home_rule_fails BETWEEN 1 AND %s "
                  "ORDER BY home_rule_fails ASC, max_up_pct DESC", (maxfails,))
        rows = c.fetchall()
    conn.close()
    out = []
    for r in rows:
        cr = json.loads(r["candidate_rules"])
        out.append({"id": r["id"], "sym": r["sym"], "lead": r["group_lead"], "to": r["group_to"],
                    "up": float(r["max_up_pct"]), "home": r["home_rule"], "fails": r["home_rule_fails"],
                    "T": _dt.datetime.strptime(cr["T"], "%Y-%m-%d %H:%M:%S"),
                    "subs": cr["home_fail_subrules"]})
    return out


def loosen_of(target):
    """{rule: {i: (bmin,bmax)}} that admits this group's failing feature subrules on its home rule."""
    rule = target["home"]
    ov = {}
    for s in target["subs"]:
        if s["value"] in (None, "PASS"):
            continue
        v = float(s["value"])
        bmin = s["b_min"] if s["b_min"] is None else float(s["b_min"])
        bmax = s["b_max"] if s["b_max"] is None else float(s["b_max"])
        if s["side"] == "below_min":
            bmin = v - EPS
        else:
            bmax = v + EPS
        ov[s["i"]] = (bmin, bmax)
    return {rule: ov}


def _merge_band(a, b):
    """Most-permissive union of two (bmin,bmax) bands (None = unbounded that side)."""
    amin, amax = a; bmin, bmax = b
    nmin = None if (amin is None or bmin is None) else min(amin, bmin)
    nmax = None if (amax is None or bmax is None) else max(amax, bmax)
    return (nmin, nmax)


def merge_stack(stack, add):
    out = {r: dict(v) for r, v in stack.items()}
    for r, subs in add.items():
        out.setdefault(r, {})
        for i, band in subs.items():
            out[r][i] = _merge_band(out[r][i], band) if i in out[r] else band
    return out


def pooled(evs, stack):
    res = {s: evs[s].evaluate(stack) for s in COINS}
    g = sum(res[s]["good"] for s in COINS)
    b = sum(res[s]["bad"] for s in COINS)
    return res, g, b


def main():
    maxfails = 3
    write = "--write" in sys.argv
    if "--maxfails" in sys.argv:
        maxfails = int(sys.argv[sys.argv.index("--maxfails") + 1])

    evs = {s: RecallEval(s) for s in COINS}
    targets = load_targets(maxfails)
    print(f"=== recall_loop — {len(targets)} feature-doelen (fails 1-{maxfails}), cumulatief ===", flush=True)

    _, base_g, base_b = pooled(evs, {})
    base_ratio = base_g / base_b if base_b else 0.0
    floor = base_ratio * RATIO_FLOOR_FRAC
    print(f"baseline pooled: good={base_g} bad={base_b} ratio={base_ratio:.3f} | ratio-floor {floor:.3f}", flush=True)

    accepted = {}
    prev_res, prev_g, prev_b = pooled(evs, accepted)
    results = []
    for t in targets:
        add = loosen_of(t)
        cand_stack = merge_stack(accepted, add)
        cand_res, cand_g, cand_b = pooled(evs, cand_stack)
        caught = caught_at(cand_res[t["sym"]], t["T"])
        inc_new_bad = sum(len(cand_res[s]["exec_bad"] - prev_res[s]["exec_bad"]) for s in COINS)
        inc_new_good = sum(len(cand_res[s]["exec_good"] - prev_res[s]["exec_good"]) for s in COINS)
        ratio = cand_g / cand_b if cand_b else 0.0
        new_bad_dts = sorted(str(d) for s in COINS for d in (cand_res[s]["exec_bad"] - prev_res[s]["exec_bad"]))
        new_good_dts = sorted(str(d) for s in COINS for d in (cand_res[s]["exec_good"] - prev_res[s]["exec_good"]))

        accept = bool(caught and inc_new_bad <= MAX_NEW_BAD and ratio >= floor)
        outcome = ("proposed_catch" if accept else
                   ("rejected_collateral" if caught else "needs_new_rule"))
        rec = {"id": t["id"], "sym": t["sym"], "lead": str(t["lead"]), "up": t["up"], "home": t["home"],
               "fails": t["fails"], "loosen": {str(r): {str(i): list(b) for i, b in subs.items()}
                                               for r, subs in add.items()},
               "caught": caught, "inc_new_good": inc_new_good, "inc_new_bad": inc_new_bad,
               "new_good_dts": new_good_dts, "new_bad_dts": new_bad_dts,
               "pooled_after": [cand_g, cand_b], "ratio_after": round(ratio, 3),
               "accepted": accept, "outcome": outcome}
        results.append(rec)
        if accept:
            accepted = cand_stack
            prev_res, prev_g, prev_b = cand_res, cand_g, cand_b
        print(f"  [{outcome:19s}] {t['sym']} {t['lead']} up{t['up']}% r{t['home']} f{t['fails']} "
              f"| caught={caught} +good={inc_new_good} +bad={inc_new_bad} ratio={ratio:.3f}", flush=True)

    # final pass: any still-open target now caught for free by the accepted stack? Only count it if the
    # catch is CAUSED by the stack — i.e. caught under the accepted stack AND NOT already caught under the
    # empty baseline. Without the baseline guard a group that a baseline fire already covers (within the
    # ±tol of caught_at) is falsely credited to the stack (critical-eye finding, 16 jun).
    base_res, _, _ = pooled(evs, {})
    fin_res, fin_g, fin_b = pooled(evs, accepted)
    free = 0
    for t in targets:
        r = next(x for x in results if x["id"] == t["id"])
        if (not r["accepted"] and caught_at(fin_res[t["sym"]], t["T"])
                and not caught_at(base_res[t["sym"]], t["T"])):
            r["outcome"] = "caught_by_stack"; r["caught_by_stack"] = True; free += 1

    acc = [r for r in results if r["accepted"]]
    print(f"\n=== SAMENVATTING ===", flush=True)
    print(f"geaccepteerde loosens: {len(acc)} | extra caught-by-stack (gratis): {free} | "
          f"needs_new_rule: {sum(1 for r in results if r['outcome']=='needs_new_rule')} | "
          f"rejected_collateral: {sum(1 for r in results if r['outcome']=='rejected_collateral')}", flush=True)
    print(f"RECALL-WINST: groepen gevangen via tweaks = {len(acc)+free}", flush=True)
    print(f"PRECISIE-KOST: pooled good {base_g}->{fin_g} (+{fin_g-base_g}), "
          f"bad {base_b}->{fin_b} (+{fin_b-base_b}), ratio {base_ratio:.3f}->{fin_g/fin_b if fin_b else 0:.3f}", flush=True)
    print(f"final accepted stack: {json.dumps({str(r): {str(i): list(b) for i,b in s.items()} for r,s in accepted.items()})}", flush=True)

    out_path = "../out/opt/recall_loop.json"
    json.dump({"baseline": {"good": base_g, "bad": base_b, "ratio": base_ratio},
               "final": {"good": fin_g, "bad": fin_b},
               "accepted_stack": {str(r): {str(i): list(b) for i, b in s.items()} for r, s in accepted.items()},
               "results": results}, open(out_path, "w"), indent=2, default=str)
    print(f"-> {out_path}", flush=True)

    if write:
        conn = brain()
        for r in results:
            status = {"proposed_catch": "proposed_catch", "caught_by_stack": "proposed_catch",
                      "rejected_collateral": "rejected_collateral",
                      "needs_new_rule": "needs_new_rule"}[r["outcome"]]
            note = _note(r)
            with conn.cursor() as c:
                c.execute("UPDATE promising_recall_state SET status=%s, tried=%s, resolution_note=%s, "
                          "updated_at=NOW() WHERE id=%s", (status, json.dumps(r, default=str), note, r["id"]))
            conn.commit()
        conn.close()
        print(f"-> worklist bijgewerkt ({len(results)} groepen): status + tried + resolution_note", flush=True)

    for ev in evs.values():
        ev.close()


def _note(r):
    if r["outcome"] in ("proposed_catch", "caught_by_stack"):
        how = "via eigen loosen" if r["outcome"] == "proposed_catch" else "gratis door geaccepteerde stack"
        return (f"Vangbaar {how}: home r{r['home']}, loosen {r['loosen']}. "
                f"+{r['inc_new_good']} goed / +{r['inc_new_bad']} slecht incrementeel "
                f"(nieuw slecht: {r['new_bad_dts']}). Ratio na {r['ratio_after']}. PROPOSAL — niet toegepast.")
    if r["outcome"] == "rejected_collateral":
        return (f"Tweak vangt de groep maar kost +{r['inc_new_bad']} slecht "
                f"({r['new_bad_dts']}) / ratio {r['ratio_after']} — afgewezen door de rem. "
                f"Cleanup-tightening of nieuwe rule nodig.")
    return (f"Geen bounded loosen (≤3) vangt deze groep op zijn vf=1 candidate-tick "
            f"zonder de gate te schenden — geparkeerd voor een nieuwe rule.")


if __name__ == "__main__":
    main()
