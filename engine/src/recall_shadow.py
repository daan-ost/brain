#!/usr/bin/env python3
"""
recall_shadow — the fast in-memory full-refire SHADOW for the RECALL worklist (read-only, mutates
NOTHING: not brain, not rules, not volume.py). The mirror of new_feat_gate/volume_sweep, but for
LOOSENING a FEATURE subrule's band (and stacking several such loosens across rules) to CATCH a missed
promising group — then reading exactly which NEW executed trades appear (good vs slecht) over the full
history of BOTH coins, with the single-position dedup + best_upside that reproduce coin_fires exactly.

A loosening WIDENS a band, so it ADDS candidate fires on non-trade datetimes — it can introduce new
slecht (unlike a tightening). That is precisely what the shadow surfaces: per coin, executed
good/slecht before->after, the NEW executed-good and NEW executed-slecht datetime sets, and whether a
given TARGET datetime (the group's vf=1 candidate tick) now produces an executed trade.

Speed trick (exact): a feature-band override only changes the PASS of the one overridden subrule. So we
precompute, per rule per non-volume subrule, the computed VALUE at every candidate datetime ONCE; an
override just re-tests that stored value against the new band and intersects with the other subrules'
baseline pass. volume_check is re-run from the (lazily-cached) 60-min vol-rows. This reproduces
persist_to_brain exactly (validated by `probe`).

Override shape: {rule: {subrule_index: (b_min, b_max)}} — None on a side means "no bound that side".

Usage:
    recall_shadow.py probe                                  # reproduce the brain.coin_fires baseline
    recall_shadow.py one <sym> <rule> <i> <b_min> <b_max>   # one loosen on one coin, with the new trades
"""
import bisect
import datetime as _dt
import json
import sys

from calc import subrule_value
from config import FORWARD_MINUTES
from rule_engine import RuleEngine
from sell_engine import SellEngine
from volume import check_volumeud_3, volume_settings, missingdata

DOGEAI, NOS = 2525, 244
COINS = (DOGEAI, NOS)
RULES = (20, 21, 22, 23)
GOOD_EDGE, BAD_EDGE = 3.0, 0.5


def _passes(v, lo, hi):
    """RuleEngine pass semantics: None -> not-False (passes), 'PASS' sentinel -> passes."""
    if v is None or v == "PASS":
        return True
    if lo is not None and v < float(lo):
        return False
    if hi is not None and v > float(hi):
        return False
    return True


class RecallEval:
    """Loads one coin once; evaluates any FEATURE-band loosen-stack via an in-memory full re-fire."""

    def __init__(self, sym):
        self.sym = sym
        self.eng = RuleEngine(sym)
        self.sell = SellEngine(sym)
        self.DT, self.PX = self.sell.DT, self.sell.PX
        self.minvol = {r: self.eng.minvol.get(r, 1e12) for r in RULES}
        s = self.eng.series["volumeud"]
        self.cand = [dt for i, dt in enumerate(s["dt"]) if s["vf"][i] == 1]   # candidate ticks
        self.candset = set(self.cand)
        self.sub = {}            # rule -> list of {i,name,indicator,def1,b_min,b_max,passcol:{dt->bool},val:{dt->v}}
        self._volrows = {}       # dt -> _vol_rows(dt,60), lazily cached
        self._precompute()

    # ---- precompute per-subrule values over the candidate ticks ----------------
    def _precompute(self):
        eng = self.eng
        for rule in RULES:
            cols = []
            for k, sr in enumerate(eng.rules[rule]):
                name = sr["subrulename"]
                if name == "volume_check":
                    cols.append({"i": k, "name": name, "vol": True})
                    continue
                def1 = int(sr["def1_value"]) if sr["def1_value"] else 1
                vc = json.loads(sr["value_condition"]) if sr["value_condition"] else {}
                vals = {}
                for dt in self.cand:
                    if name == "missingdata":
                        v = round(missingdata(eng._vol_rows(dt, 300, def1)), 4)
                    else:
                        n = def1 if name != "currentvalue" else 1
                        vv, prices = eng._vals(sr["indicator"], n, dt)
                        v = subrule_value(name, vc, vv, prices)
                    vals[dt] = v
                cols.append({"i": k, "name": name, "indicator": sr["indicator"], "def1": def1,
                             "b_min": sr["b_min"], "b_max": sr["b_max"], "vol": False, "val": vals})
            self.sub[rule] = cols

    def _volrows(self, dt):
        r = self._volrows_cache_get(dt)
        return r

    def _volrows_cache_get(self, dt):
        v = self._volrows.get(dt)
        if v is None:
            v = self.eng._vol_rows(dt, 60)
            self._volrows[dt] = v
        return v

    # ---- fire set for one rule under a per-rule band override -------------------
    def fires_for_override(self, rule, ov):
        """ov = {subrule_index: (b_min, b_max)}. Datetimes where every non-vol subrule passes (under
        the override) AND volume_check passes."""
        mv = self.minvol[rule]
        vset = volume_settings(rule)
        cols = self.sub[rule]
        out = []
        for dt in self.cand:
            ok = True
            for col in cols:
                if col.get("vol"):
                    continue
                bmin, bmax = ov.get(col["i"], (col["b_min"], col["b_max"]))
                if _passes(col["val"][dt], bmin, bmax) is False:
                    ok = False
                    break
            if not ok:
                continue
            if check_volumeud_3(self._volrows_cache_get(dt), mv, vset):
                out.append(dt)
        return out

    # ---- price / quality -------------------------------------------------------
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

    # ---- full re-fire + single-position dedup ----------------------------------
    def evaluate(self, overrides=None):
        """overrides = {rule: {i: (b_min, b_max)}}. Returns executed good/bad counts + sets + raw fires."""
        overrides = overrides or {}
        fires = []
        for rule in RULES:
            for dt in self.fires_for_override(rule, overrides.get(rule, {})):
                fires.append((dt, rule))
        fires.sort()
        raw = {dt for dt, _ in fires}
        open_until = None
        per = {r: [0, 0] for r in RULES}
        g = b = n = 0
        exec_good, exec_bad, exec_all = set(), set(), set()
        holds = []                                          # (buy_dt, sell_dt) per executed position
        for dt, rule in fires:
            buy = self.price_at(dt)
            if open_until is not None and dt <= open_until:
                continue                                   # shadow within an open position
            sres = self.sell.sell(dt, buy, rule) if buy else None
            open_until = sres["selling_date"] if sres else dt
            holds.append((dt, open_until))
            n += 1
            exec_all.add(dt)
            bu = self.best_upside(dt, buy)
            if bu is not None:
                if bu >= GOOD_EDGE:
                    g += 1; per[rule][0] += 1; exec_good.add(dt)
                elif bu < BAD_EDGE:
                    b += 1; per[rule][1] += 1; exec_bad.add(dt)
        return {"good": g, "bad": b, "exec": n, "per": {r: tuple(per[r]) for r in RULES},
                "raw": raw, "exec_good": exec_good, "exec_bad": exec_bad, "exec_all": exec_all,
                "holds": holds}

    def close(self):
        self.eng.close(); self.sell.close()


def caught_at(res, T, tol=180):
    """Is the candidate tick T traded under this evaluate() result? An executed buy within ±tol, OR an
    executed position whose hold window spans T (covered)."""
    import datetime as d
    for h in res["holds"]:
        if h[0] - d.timedelta(seconds=tol) <= T and (h[1] is None or T <= h[1]):
            return True
    return False


def diff(base, cand):
    """What changed between two evaluate() results."""
    return {
        "good": [base["good"], cand["good"]], "bad": [base["bad"], cand["bad"]],
        "new_good": sorted(str(d) for d in (cand["exec_good"] - base["exec_good"])),
        "lost_good": sorted(str(d) for d in (base["exec_good"] - cand["exec_good"])),
        "new_bad": sorted(str(d) for d in (cand["exec_bad"] - base["exec_bad"])),
        "dropped_bad": sorted(str(d) for d in (base["exec_bad"] - cand["exec_bad"])),
        "new_exec": sorted(str(d) for d in (cand["exec_all"] - base["exec_all"])),
        "lost_exec": sorted(str(d) for d in (base["exec_all"] - cand["exec_all"])),
    }


def probe():
    print("=== probe: RecallEval reproduces brain.coin_fires baseline (empty override) ===")
    from db import brain
    conn = brain()
    for sym in COINS:
        ev = RecallEval(sym)
        r = ev.evaluate({})
        with conn.cursor() as c:
            c.execute("SELECT COUNT(*) n, SUM(best_upside>=3) g, SUM(best_upside<0.5) b "
                      "FROM coin_fires WHERE trading_symbol_id=%s AND is_executed=1", (sym,))
            db = c.fetchone()
        per = "  ".join(f"r{rr}:{r['per'][rr][0]}/{r['per'][rr][1]}" for rr in RULES)
        match = (r["good"] == int(db["g"]) and r["bad"] == int(db["b"]) and r["exec"] == int(db["n"]))
        print(f"  sym {sym}: shadow good={r['good']} bad={r['bad']} exec={r['exec']} | "
              f"DB good={db['g']} bad={db['b']} exec={db['n']} | MATCH={match} | per {per}")
        ev.close()
    conn.close()


if __name__ == "__main__":
    cmd = sys.argv[1] if len(sys.argv) > 1 else "probe"
    if cmd == "probe":
        probe()
    elif cmd == "one":
        sym, rule, i = int(sys.argv[2]), int(sys.argv[3]), int(sys.argv[4])
        bmin = None if sys.argv[5] == "None" else float(sys.argv[5])
        bmax = None if sys.argv[6] == "None" else float(sys.argv[6])
        ev = RecallEval(sym)
        base = ev.evaluate({})
        cand = ev.evaluate({rule: {i: (bmin, bmax)}})
        d = diff(base, cand)
        print(f"=== loosen sym {sym} rule {rule} sub {i} -> ({bmin},{bmax}) ===")
        print(f"  good {d['good'][0]}->{d['good'][1]}  bad {d['bad'][0]}->{d['bad'][1]}")
        print(f"  new_good {d['new_good']}")
        print(f"  new_bad  {d['new_bad']}")
        print(f"  new_exec {d['new_exec']}  lost_exec {d['lost_exec']}")
        ev.close()
    else:
        print(__doc__)
