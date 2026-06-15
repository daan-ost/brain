#!/usr/bin/env python3
"""
GATED volume-parameter sweep (read-only, mutates NOTHING — not brain, not volume.py).

Tunes the per-rule `_VOLUME_OVERRIDES` params of check_volumeud_3 (volume.py). For each candidate
param-set it does a FULL persist_to_brain-equivalent re-fire (all rules 20-23, single-position dedup,
best_upside) over a coin's complete history, IN MEMORY, and measures the same gate auto_apply uses:

    GATE = total executed GOOD preserved-or-up  AND  total executed SLECHT strictly down.

GOOD = executed & best_upside>=3 ; SLECHT = executed & best_upside<0.5 (config GOOD/BAD edges).
The gate is trade-quality (best_upside), NOT oracle-agreement — we are decoupled from legacy.

Speed trick (exact, not an approximation): volume params ONLY affect the volume_check subrule.
Every other subrule is invariant under the sweep, so the set of candidate datetimes that pass all
NON-volume subrules per rule is precomputed ONCE. Each evaluate() then only re-runs check_volumeud_3
over those sets + the cheap dedup. This reproduces persist_to_brain exactly (validated by `probe`).

Usage:
    volume_sweep.py probe                 # reproduce the brain.coin_fires baseline (validation)
    volume_sweep.py bench                 # time one evaluate() per coin
    volume_sweep.py sweep <rule> [out]    # coordinate-grid sweep of one rule, cross-coin gated
"""
import bisect
import datetime as _dt
import json
import sys
import time
from collections import defaultdict

from calc import subrule_value
from config import FORWARD_MINUTES
from rule_engine import RuleEngine
from sell_engine import SellEngine
import volume
from volume import check_volumeud_3, volume_settings

DOGEAI, NOS = 2525, 244
COINS = (DOGEAI, NOS)
RULES = (20, 21, 22, 23)
GOOD_EDGE, BAD_EDGE = 3.0, 0.5

# the 10 sweepable check_volumeud_3 params (minutes_to_analyse is inert: _vol_rows window is
# hardcoded to 60 in rule_engine, so we never touch it).
PARAMS = (
    "minimal_relative_volume", "maximal_relative_volume",
    "multiplier_volume_sum_min", "multiplier_volume_sum_max",
    "trigger_minimal_volume_relative", "not_negative_before_x_values",
    "max_price_diff_percentage", "min_price_diff_percentage",
    "rows_to_analyse", "minimal_rows_to_analyse",
)


class CoinEval:
    """Loads one coin once; evaluates any volume-param override set via an in-memory full re-fire."""

    def __init__(self, sym):
        self.sym = sym
        self.eng = RuleEngine(sym)
        self.sell = SellEngine(sym)
        self.DT, self.PX = self.sell.DT, self.sell.PX
        self.minvol = {r: self.eng.minvol.get(r, 1e12) for r in RULES}
        self.nonvol = {}     # rule -> [dts where every NON-volume subrule passes]
        self.volrows = {}    # dt -> _vol_rows(dt, 60)  (shared across rules)
        self._precompute()

    # ---- precompute the volume-param-invariant part -------------------------
    def _nonvol_pass(self, rule, T):
        eng = self.eng
        for sr in eng.rules[rule]:
            name = sr["subrulename"]
            if name == "volume_check":
                continue
            def1 = int(sr["def1_value"]) if sr["def1_value"] else 1
            if name == "missingdata":
                v = round(volume.missingdata(eng._vol_rows(T, 300, def1)), 4)
                if eng._passes(v, sr["b_min"], sr["b_max"]) is False:
                    return False
                continue
            vc = json.loads(sr["value_condition"]) if sr["value_condition"] else {}
            n = def1 if name != "currentvalue" else 1
            vals, prices = eng._vals(sr["indicator"], n, T)
            v = subrule_value(name, vc, vals, prices)
            if eng._passes(v, sr["b_min"], sr["b_max"]) is False:
                return False
        return True

    def _precompute(self):
        s = self.eng.series["volumeud"]
        cand = [dt for i, dt in enumerate(s["dt"]) if s["vf"][i] == 1]
        for rule in RULES:
            self.nonvol[rule] = [dt for dt in cand if self._nonvol_pass(rule, dt)]
        allc = set()
        for rule in RULES:
            allc.update(self.nonvol[rule])
        for dt in allc:
            self.volrows[dt] = self.eng._vol_rows(dt, 60)

    # ---- price / quality ----------------------------------------------------
    def price_at(self, dt):
        i = bisect.bisect_right(self.DT, dt)
        return self.PX[i - 1] if i > 0 else None

    def best_upside(self, dt, buy):
        if not buy:
            return None
        lo = bisect.bisect_left(self.DT, dt)
        hi = bisect.bisect_right(self.DT, dt + _dt.timedelta(minutes=FORWARD_MINUTES))
        if lo >= hi:
            return None
        return round((max(self.PX[lo:hi]) - buy) / buy * 100, 3)

    # ---- the gate measurement ----------------------------------------------
    def fires_for(self, rule, settings):
        mv = self.minvol[rule]
        return [dt for dt in self.nonvol[rule] if check_volumeud_3(self.volrows[dt], mv, settings)]

    def _firemap(self, overrides):
        """Return (sorted unique fire dts across all rules, set of raw fire dts) for an override."""
        fires = []
        for rule in RULES:
            s = {**volume_settings(rule), **overrides.get(rule, {})}
            for dt in self.fires_for(rule, s):
                fires.append((dt, rule))
        fires.sort()
        return fires

    def evaluate(self, overrides, want_sets=False):
        """overrides = {rule: {param: value}}. Full re-fire + single-position dedup + best_upside.
        Returns total/per-rule executed good & slecht (the auto_apply gate inputs).
        want_sets=True also returns the raw-fire and executed-good/bad datetime sets (for diag)."""
        fires = self._firemap(overrides)
        raw = {dt for dt, _ in fires}
        open_until = None
        per = {r: [0, 0] for r in RULES}
        g = b = n = 0
        exec_good, exec_bad = set(), set()
        for dt, rule in fires:
            buy = self.price_at(dt)
            if open_until is not None and dt <= open_until:
                continue  # shadow within an open position
            sres = self.sell.sell(dt, buy, rule) if buy else None
            open_until = sres["selling_date"] if sres else dt
            n += 1
            bu = self.best_upside(dt, buy)
            if bu is not None:
                if bu >= GOOD_EDGE:
                    g += 1; per[rule][0] += 1; exec_good.add(dt)
                elif bu < BAD_EDGE:
                    b += 1; per[rule][1] += 1; exec_bad.add(dt)
        res = {"good": g, "bad": b, "exec": n, "per": {r: tuple(per[r]) for r in RULES},
               "n_fires": len(fires)}
        if want_sets:
            res.update({"raw": raw, "exec_good": exec_good, "exec_bad": exec_bad})
        return res

    def close(self):
        self.eng.close(); self.sell.close()


# ---- param-grid generator ---------------------------------------------------
def grid_for(param, cur):
    """A coarse, both-directions grid for one param anchored at its current value `cur`."""
    out = set()
    if param in ("max_price_diff_percentage", "min_price_diff_percentage",
                 "multiplier_volume_sum_min", "multiplier_volume_sum_max",
                 "maximal_relative_volume", "trigger_minimal_volume_relative"):
        for f in (0.25, 0.5, 0.7, 0.85, 1.15, 1.3, 1.6, 2.0, 3.0):
            out.add(round(cur * f, 5))
    elif param == "minimal_relative_volume":
        # can be negative (rule 22 = -0.04); use additive offsets around cur
        for d in (-0.5, -0.3, -0.15, -0.05, 0.05, 0.15, 0.3, 0.5):
            out.add(round(cur + d, 5))
    elif param == "not_negative_before_x_values":
        for v in (1.8, 2.8, 3.8, 4.8, 5.8):     # counter compared as int < v
            out.add(v)
    elif param == "rows_to_analyse":
        for v in (10, 20, 30, 40, 60):
            out.add(v)
    elif param == "minimal_rows_to_analyse":
        for v in (3, 4, 5, 7, 10):
            out.add(v)
    out.discard(round(cur, 5))    # the current value is the baseline, not a candidate
    return sorted(out)


# ---- entrypoints ------------------------------------------------------------
def probe():
    print("=== probe: reproduce brain.coin_fires baseline (no overrides) ===")
    for sym in COINS:
        ev = CoinEval(sym)
        r = ev.evaluate({})
        per = "  ".join(f"r{rr}:{r['per'][rr][0]}/{r['per'][rr][1]}" for rr in RULES)
        print(f"  sym {sym}: good={r['good']} bad={r['bad']} exec={r['exec']} | per-rule g/b: {per}")
        ev.close()


def bench():
    for sym in COINS:
        t0 = time.time(); ev = CoinEval(sym); load = time.time() - t0
        sizes = {r: len(ev.nonvol[r]) for r in RULES}
        t0 = time.time(); ev.evaluate({}); e1 = time.time() - t0
        t0 = time.time()
        for _ in range(5):
            ev.evaluate({20: {"max_price_diff_percentage": 14.0}})
        e5 = (time.time() - t0) / 5
        print(f"  sym {sym}: load {load:.1f}s | nonvol sizes {sizes} | evaluate ~{e5:.3f}s/call (first {e1:.3f}s)")
        ev.close()


def sweep(rule, out_path=None):
    """Coordinate-grid sweep of ONE rule's volume params, gated cross-coin.
    Derive on each coin, require the SAME param change to also pass the gate on the other coin."""
    print(f"=== volume sweep — rule {rule} (cross-coin gated) ===", flush=True)
    evals = {sym: CoinEval(sym) for sym in COINS}
    base = {sym: evals[sym].evaluate({}) for sym in COINS}
    for sym in COINS:
        print(f"  baseline sym {sym}: good={base[sym]['good']} bad={base[sym]['bad']}", flush=True)

    cur = volume_settings(rule)
    results = []
    for param in PARAMS:
        for val in grid_for(param, cur[param]):
            ov = {rule: {param: val}}
            res = {sym: evals[sym].evaluate(ov) for sym in COINS}
            row = {"rule": rule, "param": param, "from": cur[param], "to": val}
            for sym in COINS:
                row[f"good_{sym}"] = [base[sym]["good"], res[sym]["good"]]
                row[f"bad_{sym}"] = [base[sym]["bad"], res[sym]["bad"]]
                row[f"rule_gb_{sym}"] = [list(base[sym]["per"][rule]), list(res[sym]["per"][rule])]
            # per-coin gate: good>=base AND bad<base (strict)
            gate = {sym: (res[sym]["good"] >= base[sym]["good"] and res[sym]["bad"] < base[sym]["bad"])
                    for sym in COINS}
            # pooled gate (auto_apply): total good>= AND total bad<
            tot_bg = sum(base[s]["good"] for s in COINS); tot_bb = sum(base[s]["bad"] for s in COINS)
            tot_ng = sum(res[s]["good"] for s in COINS); tot_nb = sum(res[s]["bad"] for s in COINS)
            row["pooled"] = [[tot_bg, tot_ng], [tot_bb, tot_nb]]
            row["gate_pass"] = {str(s): gate[s] for s in COINS}
            row["pooled_pass"] = (tot_ng >= tot_bg and tot_nb < tot_bb)
            row["cross_coin_robust"] = gate[DOGEAI] and gate[NOS]
            row["no_harm_other"] = all(  # passes on one coin, at least does no harm on the other
                (res[s]["good"] >= base[s]["good"] and res[s]["bad"] <= base[s]["bad"]) for s in COINS)
            results.append(row)
            if gate[DOGEAI] or gate[NOS] or row["pooled_pass"]:
                tag = "ROBUST" if row["cross_coin_robust"] else ("pooled" if row["pooled_pass"] else "single")
                print(f"  [{tag}] {param}: {cur[param]}->{val} | "
                      f"DOGEAI g {base[DOGEAI]['good']}->{res[DOGEAI]['good']} b {base[DOGEAI]['bad']}->{res[DOGEAI]['bad']} | "
                      f"NOS g {base[NOS]['good']}->{res[NOS]['good']} b {base[NOS]['bad']}->{res[NOS]['bad']}",
                      flush=True)
    for ev in evals.values():
        ev.close()
    out_path = out_path or f"../out/opt/volume_sweep_rule{rule}.json"
    with open(out_path, "w") as f:
        json.dump({"rule": rule, "baseline": {str(s): base[s] for s in COINS},
                   "current_settings": cur, "results": results}, f, indent=2, default=str)
    robust = [r for r in results if r["cross_coin_robust"]]
    pooled = [r for r in results if r["pooled_pass"] and not r["cross_coin_robust"]]
    print(f"--- rule {rule}: {len(robust)} cross-coin-robust, {len(pooled)} pooled-only. -> {out_path}", flush=True)


def _gate_pair(base, res):
    """Per-coin gate dict + pooled-pass, given base/res keyed by sym."""
    gate = {s: (res[s]["good"] >= base[s]["good"] and res[s]["bad"] < base[s]["bad"]) for s in COINS}
    tg = sum(base[s]["good"] for s in COINS); tb = sum(base[s]["bad"] for s in COINS)
    ng = sum(res[s]["good"] for s in COINS); nb = sum(res[s]["bad"] for s in COINS)
    return gate, (ng >= tg and nb < tb), (tg, tb, ng, nb)


def descent(rule, out_path=None):
    """Greedy multi-round coordinate descent: repeatedly add the single-param move that maximises
    pooled bad-drop subject to NO good lost on either coin AND bad not up on either coin. Stacks
    compatible moves. Reports cross-coin robustness of the final stacked override."""
    print(f"=== coordinate descent — rule {rule} ===", flush=True)
    evals = {s: CoinEval(s) for s in COINS}
    base0 = {s: evals[s].evaluate({}) for s in COINS}
    cur = volume_settings(rule)
    applied = {}          # param -> value (the stacked override for this rule)
    base = base0
    path = []
    for rnd in range(8):
        best = None
        for param in PARAMS:
            if param in applied:
                continue
            anchor = applied.get(param, cur[param])
            for val in grid_for(param, cur[param]):
                ov = {rule: {**applied, param: val}}
                res = {s: evals[s].evaluate(ov) for s in COINS}
                gate, pooled_pass, (tg, tb, ng, nb) = _gate_pair(base, res)
                # admissible step: pooled bad strictly down, NO good lost on either coin,
                # bad not up on either coin (so it can't be a one-coin overfit that harms the other).
                ok = (nb < tb and all(res[s]["good"] >= base[s]["good"] for s in COINS)
                      and all(res[s]["bad"] <= base[s]["bad"] for s in COINS))
                if ok and (best is None or (tb - nb) > best["drop"]):
                    best = {"param": param, "val": val, "drop": tb - nb, "res": res, "gate": gate}
        if not best:
            break
        applied[best["param"]] = best["val"]
        base = best["res"]
        path.append({"param": best["param"], "to": best["val"], "pooled_bad_drop": best["drop"],
                     "robust": best["gate"][DOGEAI] and best["gate"][NOS],
                     "per_coin": {str(s): [best["res"][s]["good"], best["res"][s]["bad"]] for s in COINS}})
        print(f"  +{best['param']}={best['val']} | pooled bad -{best['drop']} | "
              f"DOGEAI g/b {best['res'][DOGEAI]['good']}/{best['res'][DOGEAI]['bad']} "
              f"NOS g/b {best['res'][NOS]['good']}/{best['res'][NOS]['bad']} "
              f"{'ROBUST' if path[-1]['robust'] else 'pooled'}", flush=True)
    final = {s: evals[s].evaluate({rule: applied}) for s in COINS} if applied else base0
    for ev in evals.values():
        ev.close()
    summary = {"rule": rule, "stacked_override": applied, "path": path,
               "baseline": {str(s): [base0[s]["good"], base0[s]["bad"]] for s in COINS},
               "final": {str(s): [final[s]["good"], final[s]["bad"]] for s in COINS}}
    out_path = out_path or f"../out/opt/volume_descent_rule{rule}.json"
    with open(out_path, "w") as f:
        json.dump(summary, f, indent=2, default=str)
    print(f"--- rule {rule}: stacked {applied} -> {out_path}", flush=True)


def oos(rule, out_path=None):
    """TRUE cross-coin OOS: per param, find the TRAIN-coin-optimal value (max bad-drop, good held),
    then report its TEST-coin transfer. If the train-optimal value harms the test coin -> overfit."""
    print(f"=== cross-coin OOS (train->test) — rule {rule} ===", flush=True)
    evals = {s: CoinEval(s) for s in COINS}
    base = {s: evals[s].evaluate({}) for s in COINS}
    cur = volume_settings(rule)
    rows = []
    for train, test in ((DOGEAI, NOS), (NOS, DOGEAI)):
        for param in PARAMS:
            best = None
            for val in grid_for(param, cur[param]):
                r = evals[train].evaluate({rule: {param: val}})
                if r["good"] >= base[train]["good"] and r["bad"] < base[train]["bad"]:
                    if best is None or r["bad"] < best["bad"]:
                        best = {"val": val, "good": r["good"], "bad": r["bad"]}
            if not best:
                continue
            t = evals[test].evaluate({rule: {param: best["val"]}})
            transfer_ok = t["good"] >= base[test]["good"] and t["bad"] <= base[test]["bad"]
            rows.append({"train": train, "test": test, "param": param, "val": best["val"],
                         "train_gb": [base[train]["good"], best["good"], base[train]["bad"], best["bad"]],
                         "test_gb": [base[test]["good"], t["good"], base[test]["bad"], t["bad"]],
                         "transfer_ok": transfer_ok})
            print(f"  train {train} {param}={best['val']}: train b {base[train]['bad']}->{best['bad']} | "
                  f"test {test} b {base[test]['bad']}->{t['bad']} g {base[test]['good']}->{t['good']} "
                  f"{'TRANSFERS' if transfer_ok else 'OVERFIT'}", flush=True)
    for ev in evals.values():
        ev.close()
    out_path = out_path or f"../out/opt/volume_oos_rule{rule}.json"
    with open(out_path, "w") as f:
        json.dump({"rule": rule, "rows": rows}, f, indent=2, default=str)
    print(f"--- rule {rule}: {sum(r['transfer_ok'] for r in rows)}/{len(rows)} transfer. -> {out_path}", flush=True)


def rule_fingerprint(ev):
    """A stable signature of the NON-volume subrule set of rules 20-23 (the snapshot we gate
    against). If brain.rules mutates mid-session this changes, flagging a stale comparison."""
    import hashlib
    sig = []
    for rule in RULES:
        for sr in ev.eng.rules[rule]:
            if sr["subrulename"] == "volume_check":
                continue
            sig.append((rule, sr["indicator"], sr["subrulename"],
                        str(sr["def1_value"]), str(sr["b_min"]), str(sr["b_max"])))
    sig.sort()
    return hashlib.md5(json.dumps(sig, default=str).encode()).hexdigest(), len(sig)


def _row(rule, param, frm, to, base, res):
    gate = {s: (res[s]["good"] >= base[s]["good"] and res[s]["bad"] < base[s]["bad"]) for s in COINS}
    tg = sum(base[s]["good"] for s in COINS); tb = sum(base[s]["bad"] for s in COINS)
    ng = sum(res[s]["good"] for s in COINS); nb = sum(res[s]["bad"] for s in COINS)
    no_good_lost = all(res[s]["good"] >= base[s]["good"] for s in COINS)
    no_bad_added = all(res[s]["bad"] <= base[s]["bad"] for s in COINS)
    return {
        "rule": rule, "param": param, "from": frm, "to": to,
        "DOGEAI": [base[DOGEAI]["good"], res[DOGEAI]["good"], base[DOGEAI]["bad"], res[DOGEAI]["bad"]],
        "NOS": [base[NOS]["good"], res[NOS]["good"], base[NOS]["bad"], res[NOS]["bad"]],
        "pooled": [tg, ng, tb, nb],
        "robust": gate[DOGEAI] and gate[NOS],            # bad strictly down on BOTH coins, good held
        "pooled_pass": (ng >= tg and nb < tb),           # auto_apply gate (total)
        "admissible": (nb < tb and no_good_lost and no_bad_added),  # net win, harms neither coin
    }


def full_report(out_path=None):
    """Authoritative single-snapshot run: baseline + per-param sweep + pairwise combos + greedy
    descent + cross-coin OOS, all against ONE in-memory snapshot so every number is consistent."""
    t0 = time.time()
    evals = {s: CoinEval(s) for s in COINS}
    fp, n_sub = rule_fingerprint(evals[DOGEAI])
    base = {s: evals[s].evaluate({}) for s in COINS}
    print(f"=== volume_sweep full report ===", flush=True)
    print(f"snapshot fingerprint {fp} ({n_sub} non-volume subrules) | load {time.time()-t0:.0f}s", flush=True)
    print(f"baseline: DOGEAI {base[DOGEAI]['good']}g/{base[DOGEAI]['bad']}b  "
          f"NOS {base[NOS]['good']}g/{base[NOS]['bad']}b  "
          f"pooled {sum(base[s]['good'] for s in COINS)}g/{sum(base[s]['bad'] for s in COINS)}b", flush=True)
    out = {"fingerprint": fp, "n_nonvol_subrules": n_sub,
           "baseline": {str(s): {"good": base[s]["good"], "bad": base[s]["bad"]} for s in COINS},
           "per_rule": {}}
    for rule in RULES:
        cur = volume_settings(rule)
        singles, admissible = [], []
        for param in PARAMS:
            for val in grid_for(param, cur[param]):
                res = {s: evals[s].evaluate({rule: {param: val}}) for s in COINS}
                r = _row(rule, param, cur[param], val, base, res)
                singles.append(r)
                if r["admissible"]:
                    admissible.append((param, val, r))
        # pairwise combos of admissible singles (different params)
        combos = []
        for i in range(len(admissible)):
            for j in range(i + 1, len(admissible)):
                p1, v1, _ = admissible[i]; p2, v2, _ = admissible[j]
                if p1 == p2:
                    continue
                res = {s: evals[s].evaluate({rule: {p1: v1, p2: v2}}) for s in COINS}
                rr = _row(rule, f"{p1}+{p2}", f"{cur[p1]},{cur[p2]}", f"{v1},{v2}", base, res)
                if rr["pooled_pass"]:
                    combos.append(rr)
        # greedy descent (stack admissible moves maximising pooled bad-drop)
        applied = {}; b = base; path = []
        for _ in range(8):
            best = None
            for param in PARAMS:
                if param in applied:
                    continue
                for val in grid_for(param, cur[param]):
                    res = {s: evals[s].evaluate({rule: {**applied, param: val}}) for s in COINS}
                    tb = sum(b[s]["bad"] for s in COINS); nb = sum(res[s]["bad"] for s in COINS)
                    ok = (nb < tb and all(res[s]["good"] >= b[s]["good"] for s in COINS)
                          and all(res[s]["bad"] <= b[s]["bad"] for s in COINS))
                    if ok and (best is None or (tb - nb) > best["drop"]):
                        best = {"param": param, "val": val, "drop": tb - nb, "res": res}
            if not best:
                break
            applied[best["param"]] = best["val"]; b = best["res"]
            path.append({"param": best["param"], "to": best["val"], "pooled_bad_drop": best["drop"],
                         "DOGEAI": [b[DOGEAI]["good"], b[DOGEAI]["bad"]], "NOS": [b[NOS]["good"], b[NOS]["bad"]]})
        # cross-coin OOS transfer per param
        oos_rows = []
        for train, test in ((DOGEAI, NOS), (NOS, DOGEAI)):
            for param in PARAMS:
                best = None
                for val in grid_for(param, cur[param]):
                    r = evals[train].evaluate({rule: {param: val}})
                    if r["good"] >= base[train]["good"] and r["bad"] < base[train]["bad"]:
                        if best is None or r["bad"] < best["bad"]:
                            best = {"val": val, "good": r["good"], "bad": r["bad"]}
                if not best:
                    continue
                t = evals[test].evaluate({rule: {param: best["val"]}})
                oos_rows.append({"train": train, "test": test, "param": param, "val": best["val"],
                                 "train_bad": [base[train]["bad"], best["bad"]],
                                 "test_bad": [base[test]["bad"], t["bad"]],
                                 "test_good": [base[test]["good"], t["good"]],
                                 "transfer": t["good"] >= base[test]["good"] and t["bad"] <= base[test]["bad"]})
        robust = [r for r in singles if r["robust"]]
        out["per_rule"][rule] = {"current": {k: cur[k] for k in PARAMS},
                                 "robust_singles": robust,
                                 "pooled_singles": [r for r in singles if r["pooled_pass"] and not r["robust"]],
                                 "combos_pooled": combos, "descent_path": path,
                                 "descent_stack": applied, "oos": oos_rows}
        print(f"\n--- rule {rule}: {len(robust)} robust singles, "
              f"{len([r for r in singles if r['pooled_pass']])} pooled singles, "
              f"{len(combos)} pooled combos, descent stack {applied or '∅'}", flush=True)
        for r in robust:
            print(f"    ROBUST {r['param']} {r['from']}->{r['to']} | "
                  f"DOGEAI b {r['DOGEAI'][2]}->{r['DOGEAI'][3]} NOS b {r['NOS'][2]}->{r['NOS'][3]} "
                  f"(good DOGEAI {r['DOGEAI'][0]}->{r['DOGEAI'][1]} NOS {r['NOS'][0]}->{r['NOS'][1]})", flush=True)
        ot = sum(r["transfer"] for r in oos_rows)
        print(f"    OOS transfer: {ot}/{len(oos_rows)} param-moves transfer cross-coin", flush=True)
    for ev in evals.values():
        ev.close()
    out_path = out_path or "../out/opt/volume_sweep_report.json"
    with open(out_path, "w") as f:
        json.dump(out, f, indent=2, default=str)
    print(f"\n=== done in {time.time()-t0:.0f}s -> {out_path} ===", flush=True)


def pairgrid(rule, pa, pb, out_path=None):
    """Cross-coin-gated grid over TWO params of `rule` (grid_for each). Reports any ROBUST pair
    (bad strictly down on BOTH coins, 0 good lost) — does a combo beat the best single?"""
    evals = {s: CoinEval(s) for s in COINS}
    base = {s: evals[s].evaluate({}) for s in COINS}
    cur = volume_settings(rule)
    print(f"=== pairgrid — rule {rule} {pa} x {pb} | baseline "
          f"DOGEAI {base[DOGEAI]['good']}/{base[DOGEAI]['bad']} NOS {base[NOS]['good']}/{base[NOS]['bad']} ===", flush=True)
    robust, pooled = [], []
    for va in [cur[pa]] + grid_for(pa, cur[pa]):
        for vb in [cur[pb]] + grid_for(pb, cur[pb]):
            res = {s: evals[s].evaluate({rule: {pa: va, pb: vb}}) for s in COINS}
            r = _row(rule, f"{pa}+{pb}", f"{cur[pa]},{cur[pb]}", f"{va},{vb}", base, res)
            if r["robust"]:
                robust.append(r)
            elif r["pooled_pass"]:
                pooled.append(r)
    robust.sort(key=lambda r: r["pooled"][3])   # smallest resulting pooled bad first
    for r in robust[:12]:
        print(f"  ROBUST {pa}={r['to'].split(',')[0]} {pb}={r['to'].split(',')[1]} | "
              f"DOGEAI b {r['DOGEAI'][2]}->{r['DOGEAI'][3]} NOS b {r['NOS'][2]}->{r['NOS'][3]} | pooled bad {r['pooled'][2]}->{r['pooled'][3]}", flush=True)
    print(f"  -> {len(robust)} robust pairs, {len(pooled)} pooled-only pairs", flush=True)
    for ev in evals.values():
        ev.close()
    if out_path:
        with open(out_path, "w") as f:
            json.dump({"rule": rule, "pa": pa, "pb": pb, "robust": robust, "pooled": pooled}, f, indent=2, default=str)


def diag(rule, param, value):
    """Mechanism decomposition of a single param override on `rule`. Per coin, classify why each
    executed BAD drops (direct: volume_check now rejects it -> no longer fires; OR dedup: still fires
    but became a shadow) and why each executed GOOD appears (un-shadowed by dedup; or newly fires).
    This separates a REAL volume-signal improvement from a dedup-reshuffle wash (the 2b/RQ2 lesson)."""
    val = float(value)
    print(f"=== diag — rule {rule}, {param}: -> {val} ===", flush=True)
    for sym in COINS:
        ev = CoinEval(sym)
        cur = volume_settings(rule)
        base = ev.evaluate({}, want_sets=True)
        ovr = ev.evaluate({rule: {param: val}}, want_sets=True)
        dropped_bad = base["exec_bad"] - ovr["exec_bad"]
        new_bad = ovr["exec_bad"] - base["exec_bad"]
        gained_good = ovr["exec_good"] - base["exec_good"]
        lost_good = base["exec_good"] - ovr["exec_good"]
        def cls_drop(dt):  # why did this executed-bad disappear?
            return "no_longer_fires(direct)" if dt not in ovr["raw"] else "now_shadowed(dedup)"
        def cls_gain(dt):  # why did this executed-good appear?
            return "newly_fires" if dt not in base["raw"] else "un_shadowed(dedup)"
        print(f"  sym {sym}: from {cur[param]} | good {base['good']}->{ovr['good']}  bad {base['bad']}->{ovr['bad']}  "
              f"(raw fires {len(base['raw'])}->{len(ovr['raw'])})", flush=True)
        for dt in sorted(dropped_bad):
            print(f"      -BAD  {dt}  {cls_drop(dt)}", flush=True)
        for dt in sorted(new_bad):
            print(f"      +BAD  {dt}  {'newly_fires' if dt not in base['raw'] else 'un_shadowed(dedup)'}", flush=True)
        for dt in sorted(gained_good):
            print(f"      +GOOD {dt}  {cls_gain(dt)}", flush=True)
        for dt in sorted(lost_good):
            print(f"      -GOOD {dt}  {'no_longer_fires(direct)' if dt not in ovr['raw'] else 'now_shadowed(dedup)'}", flush=True)
        ev.close()


def finegrid(rule, param, lo, hi, n=11, out_path=None):
    """Fine cross-coin-gated grid of ONE param over [lo,hi] (n points) — knife-edge / sweet-spot test."""
    lo, hi, n = float(lo), float(hi), int(n)
    vals = [round(lo + (hi - lo) * k / (n - 1), 6) for k in range(n)]
    evals = {s: CoinEval(s) for s in COINS}
    base = {s: evals[s].evaluate({}) for s in COINS}
    cur = volume_settings(rule)[param]
    print(f"=== finegrid — rule {rule} {param} (current {cur}) | baseline "
          f"DOGEAI {base[DOGEAI]['good']}/{base[DOGEAI]['bad']} NOS {base[NOS]['good']}/{base[NOS]['bad']} ===", flush=True)
    rows = []
    for v in vals:
        res = {s: evals[s].evaluate({rule: {param: v}}) for s in COINS}
        r = _row(rule, param, cur, v, base, res)
        rows.append(r)
        tag = "ROBUST" if r["robust"] else ("pooled" if r["pooled_pass"] else ("admis" if r["admissible"] else ""))
        print(f"  {param}={v:<10} DOGEAI g/b {res[DOGEAI]['good']}/{res[DOGEAI]['bad']}  "
              f"NOS g/b {res[NOS]['good']}/{res[NOS]['bad']}  pooled b {sum(base[s]['bad'] for s in COINS)}->"
              f"{sum(res[s]['bad'] for s in COINS)}  {tag}", flush=True)
    for ev in evals.values():
        ev.close()
    if out_path:
        with open(out_path, "w") as f:
            json.dump({"rule": rule, "param": param, "rows": rows}, f, indent=2, default=str)


if __name__ == "__main__":
    cmd = sys.argv[1] if len(sys.argv) > 1 else "probe"
    if cmd == "probe":
        probe()
    elif cmd == "diag":
        diag(int(sys.argv[2]), sys.argv[3], sys.argv[4])
    elif cmd == "pairgrid":
        pairgrid(int(sys.argv[2]), sys.argv[3], sys.argv[4], sys.argv[5] if len(sys.argv) > 5 else None)
    elif cmd == "finegrid":
        finegrid(int(sys.argv[2]), sys.argv[3], sys.argv[4], sys.argv[5],
                 sys.argv[6] if len(sys.argv) > 6 else 11, sys.argv[7] if len(sys.argv) > 7 else None)
    elif cmd == "report":
        full_report(sys.argv[2] if len(sys.argv) > 2 else None)
    elif cmd == "bench":
        bench()
    elif cmd == "sweep":
        sweep(int(sys.argv[2]), sys.argv[3] if len(sys.argv) > 3 else None)
    elif cmd == "descent":
        descent(int(sys.argv[2]), sys.argv[3] if len(sys.argv) > 3 else None)
    elif cmd == "oos":
        oos(int(sys.argv[2]), sys.argv[3] if len(sys.argv) > 3 else None)
    else:
        print(__doc__)
