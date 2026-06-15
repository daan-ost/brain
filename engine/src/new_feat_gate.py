#!/usr/bin/env python3
"""
new_feat_gate — the REAL engine gate for a NEW candidate feature (read-only, mutates NOTHING).

A candidate = (rule, feature_name, bound, threshold). The feature becomes an extra AND-subrule on the
rule: a fire survives iff the current rule fires AND feat(T) passes the bound/threshold. Adding an AND
can only REMOVE fires (monotone), exactly like a tightening, so it never creates a new bad trade; the
only risk is dropping a GOOD trade — which this gate catches.

For each coin it does a FULL persist_to_brain-equivalent re-fire of ALL rules 20-23 with single-
position dedup + best_upside, IN MEMORY, comparing baseline vs +candidate. Mirrors volume_sweep.py
(validated to reproduce brain.coin_fires exactly via `probe`). This is the binding test the
cache-style discovery only approximates (the docs: 91% of cache-SAFE candidates were OOS no-ops).

Reports per coin: executed good/slecht before->after, executed-GOOD lost (must be 0), and a MECHANISM
breakdown of every dropped executed-bad — direct (the candidate subrule kills the fire) vs dedup
(a reshuffle: the fire still happens but is now shadowed / a different rule takes the slot). The gate
PASSES iff total executed good preserved AND total executed slecht strictly drops (the auto_apply gate).

Usage:
    new_feat_gate.py probe                          # reproduce baseline (validation)
    new_feat_gate.py one <rule> <feat> <bound> <thr>   # gate a single candidate, with mechanism diag
    new_feat_gate.py batch <json_path>              # gate a list [{rule,feat,bound,threshold}] -> json
"""
import bisect
import datetime as _dt
import json
import sys

from config import FORWARD_MINUTES
from volume_sweep import CoinEval, RULES, COINS, DOGEAI, NOS, GOOD_EDGE, BAD_EDGE
from volume import volume_settings
from new_feat_lib import REGISTRY
from rule_engine import RuleEngine


def _passes(v, bound, thr):
    if v is None:
        return False
    return (v >= thr) if bound == "lower" else (v <= thr)


class GateEval(CoinEval):
    """CoinEval + an extra-AND predicate on one rule, with a mechanism-aware diff vs baseline."""

    def _firemap_extra(self, rule, predicate):
        """Full firemap (all rules) with rule's current fires filtered by `predicate(dt)`."""
        fires = []
        for r in RULES:
            s = volume_settings(r)
            rfires = self.fires_for(r, s)
            if r == rule:
                rfires = [dt for dt in rfires if predicate(dt)]
            for dt in rfires:
                fires.append((dt, r))
        fires.sort()
        return fires

    def _dedup(self, fires):
        """Single-position dedup + best_upside over a sorted [(dt,rule)] list. Returns executed
        good/bad datetime sets, per-rule counts, and the raw fire set."""
        raw = {dt for dt, _ in fires}
        open_until = None
        per = {r: [0, 0] for r in RULES}
        exec_good, exec_bad = set(), set()
        g = b = n = 0
        for dt, rule in fires:
            buy = self.price_at(dt)
            if open_until is not None and dt <= open_until:
                continue
            sres = self.sell.sell(dt, buy, rule) if buy else None
            open_until = sres["selling_date"] if sres else dt
            n += 1
            bu = self.best_upside(dt, buy)
            if bu is not None:
                if bu >= GOOD_EDGE:
                    g += 1; per[rule][0] += 1; exec_good.add(dt)
                elif bu < BAD_EDGE:
                    b += 1; per[rule][1] += 1; exec_bad.add(dt)
        return {"good": g, "bad": b, "exec": n, "per": {r: tuple(per[r]) for r in RULES},
                "raw": raw, "exec_good": exec_good, "exec_bad": exec_bad}

    def evaluate_extra(self, rule, predicate):
        return self._dedup(self._firemap_extra(rule, predicate))


def make_predicate(eng, feat, bound, thr):
    fam_fn = REGISTRY.get(feat)
    if fam_fn is None:
        raise SystemExit(f"unknown feature {feat}")
    fn = fam_fn[1]
    return lambda dt: _passes(fn(eng, dt), bound, thr)


def gate_candidate(rule, feat, bound, thr, diag=False, evals=None, bases=None):
    """Gate one candidate across both coins. Returns a structured verdict dict. If `evals`/`bases`
    (preloaded GateEval + baseline per coin) are given, reuse them — avoids the ~20s/coin reload so a
    whole batch runs against ONE frozen snapshot (consistent numbers, the volume_sweep lesson)."""
    res = {"rule": rule, "feat": feat, "bound": bound, "threshold": thr, "coins": {}}
    tot_bg = tot_bb = tot_ng = tot_nb = 0
    good_lost_total = 0
    own = evals is None
    for sym in COINS:
        ev = evals[sym] if evals else GateEval(sym)
        base = bases[sym] if bases else ev.evaluate({}, want_sets=True)
        pred = make_predicate(ev.eng, feat, bound, thr)
        cand = ev.evaluate_extra(rule, pred)
        dropped_bad = base["exec_bad"] - cand["exec_bad"]
        new_bad = cand["exec_bad"] - base["exec_bad"]            # NOT always empty: an AND-tightening can
        # un-shadow a co-located fire via the single-position dedup, and that promoted fire may itself be bad
        good_lost = base["exec_good"] - cand["exec_good"]
        # mechanism: a dropped bad is DIRECT if it no longer fires at all, else a dedup reshuffle
        direct = sum(1 for dt in dropped_bad if dt not in cand["raw"])
        reshuffle = len(dropped_bad) - direct
        c = {"good": [base["good"], cand["good"]], "bad": [base["bad"], cand["bad"]],
             "per_rule_gb": {r: [list(base["per"][r]), list(cand["per"][r])] for r in RULES},
             "exec_good_lost": len(good_lost), "exec_bad_dropped": len(dropped_bad),
             "new_bad": len(new_bad), "drop_direct": direct, "drop_dedup_reshuffle": reshuffle}
        if diag:
            c["dropped_bad_dts"] = [str(d) for d in sorted(dropped_bad)]
            c["good_lost_dts"] = [str(d) for d in sorted(good_lost)]
        res["coins"][str(sym)] = c
        tot_bg += base["good"]; tot_bb += base["bad"]; tot_ng += cand["good"]; tot_nb += cand["bad"]
        good_lost_total += len(good_lost)
        if own:
            ev.close()
    res["pooled"] = {"good": [tot_bg, tot_ng], "bad": [tot_bb, tot_nb]}
    res["good_lost_total"] = good_lost_total
    # gate: total good preserved AND total slecht strictly drops (net, == the auto_apply portfolio gate)
    res["gate_pass"] = (tot_ng >= tot_bg and tot_nb < tot_bb)
    res["total_new_bad"] = sum(res["coins"][str(s)]["new_bad"] for s in COINS)
    # cross-coin robust SURVIVOR: slecht strictly down on BOTH coins, 0 good lost on each, AND 0 new bad
    # per coin (a CLEAN direct kill, not a dedup reshuffle that introduces a new bad elsewhere). Without
    # the new_bad==0 guard the "direct, geen reshuffle" claim is false (critical-eye 15 jun).
    res["cross_coin_robust"] = all(
        res["coins"][str(s)]["bad"][1] < res["coins"][str(s)]["bad"][0]
        and res["coins"][str(s)]["good"][1] >= res["coins"][str(s)]["good"][0]
        and res["coins"][str(s)]["new_bad"] == 0 for s in COINS)
    res["bad_dropped_total"] = tot_bb - tot_nb
    return res


def probe():
    print("=== probe: GateEval reproduces brain.coin_fires baseline (always-true predicate) ===")
    for sym in COINS:
        ev = GateEval(sym)
        base = ev.evaluate({})
        ext = ev.evaluate_extra(21, lambda dt: True)
        per = "  ".join(f"r{rr}:{base['per'][rr][0]}/{base['per'][rr][1]}" for rr in RULES)
        ok = (base["good"] == ext["good"] and base["bad"] == ext["bad"])
        print(f"  sym {sym}: good={base['good']} bad={base['bad']} | extra-true matches baseline: {ok} | per {per}")
        ev.close()


def _print_one(v):
    print(f"=== gate — rule {v['rule']} + {v['feat']} {v['bound']} {v['threshold']} ===")
    for s in (DOGEAI, NOS):
        c = v["coins"][str(s)]
        print(f"  sym {s}: good {c['good'][0]}->{c['good'][1]}  slecht {c['bad'][0]}->{c['bad'][1]}  "
              f"| good lost {c['exec_good_lost']} | bad dropped {c['exec_bad_dropped']} "
              f"(direct {c['drop_direct']}, dedup {c['drop_dedup_reshuffle']}) | new_bad {c['new_bad']}")
        if c.get("dropped_bad_dts"):
            print(f"       dropped bad: {c['dropped_bad_dts']}")
        if c.get("good_lost_dts"):
            print(f"       GOOD LOST: {c['good_lost_dts']}")
    p = v["pooled"]
    print(f"  pooled: good {p['good'][0]}->{p['good'][1]}  slecht {p['bad'][0]}->{p['bad'][1]}  "
          f"| total good lost {v['good_lost_total']} | bad dropped {v['bad_dropped_total']}")
    print(f"  GATE_PASS={v['gate_pass']}  CROSS_COIN_ROBUST={v['cross_coin_robust']}")


if __name__ == "__main__":
    cmd = sys.argv[1] if len(sys.argv) > 1 else "probe"
    if cmd == "probe":
        probe()
    elif cmd == "one":
        rule = int(sys.argv[2]); feat = sys.argv[3]; bound = sys.argv[4]; thr = float(sys.argv[5])
        _print_one(gate_candidate(rule, feat, bound, thr, diag=True))
    elif cmd == "batch":
        import time
        from volume_sweep import rule_fingerprint
        cands = json.load(open(sys.argv[2]))
        t0 = time.time()
        evals = {s: GateEval(s) for s in COINS}                 # load both coins ONCE (frozen snapshot)
        fp, n_sub = rule_fingerprint(evals[DOGEAI])
        bases = {s: evals[s].evaluate({}, want_sets=True) for s in COINS}
        print(f"snapshot fingerprint {fp} ({n_sub} non-volume subrules) | load {time.time()-t0:.0f}s", flush=True)
        print(f"baseline: DOGEAI {bases[DOGEAI]['good']}g/{bases[DOGEAI]['bad']}b  "
              f"NOS {bases[NOS]['good']}g/{bases[NOS]['bad']}b", flush=True)
        out = []
        for i, c in enumerate(cands):
            v = gate_candidate(int(c["rule"]), c["feat"], c["bound"], float(c["threshold"]),
                               diag=c.get("diag", True), evals=evals, bases=bases)
            v["fingerprint"] = fp
            out.append(v)
            tag = "PASS" if v["gate_pass"] else "fail"
            rob = "ROBUST" if v["cross_coin_robust"] else ""
            print(f"[{i+1}/{len(cands)}] r{v['rule']} {v['feat']} {v['bound']} {v['threshold']}: "
                  f"pooled slecht {v['pooled']['bad'][0]}->{v['pooled']['bad'][1]} "
                  f"good_lost {v['good_lost_total']} | DOGEAI b {v['coins'][str(DOGEAI)]['bad'][0]}->{v['coins'][str(DOGEAI)]['bad'][1]} "
                  f"NOS b {v['coins'][str(NOS)]['bad'][0]}->{v['coins'][str(NOS)]['bad'][1]} [{tag}] {rob}", flush=True)
        for ev in evals.values():
            ev.close()
        outp = sys.argv[3] if len(sys.argv) > 3 else "../out/opt/new_feat_gate_batch.json"
        json.dump(out, open(outp, "w"), indent=2, default=str)
        print(f"-> {outp}")
    else:
        print(__doc__)
