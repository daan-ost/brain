#!/usr/bin/env python3
"""
Our OWN rule engine: runs the brain.rules over brain.indicators to produce OUR fires — no
legacy trades imported. A rule is a flat AND of subrules; a candidate datetime (volume_found=1)
fires when every subrule's computed value sits inside its [b_min, b_max].

Reads ONLY brain (indicators + rules + coin_rule_settings). bot_signals is not touched.

Usage (sanity check): rule_engine.py [symbol_id] [from] [to]
"""
import bisect
import json
import sys
from datetime import timedelta

from db import brain
from calc import subrule_value
from volume import missingdata, check_volumeud_3, volume_settings

RULES = (20, 21, 22, 23)


class RuleEngine:
    def __init__(self, symbol, conn=None):
        self.symbol = symbol
        self._own = conn is None
        self.conn = conn or brain()
        # all indicator series for this coin, from brain
        self.series = {}
        with self.conn.cursor() as c:
            # candidate-gate: use brain_volume_found (brain's own computation, identical for all rules
            # 20-23), not the legacy-copied volume_found which was only set on ticks where legacy ran.
            # Switched 2026-06-17 — see memory brain-volume-found-switch.
            c.execute("SELECT indicator, datetime, value, price, brain_volume_found AS volume_found FROM indicators "
                      "WHERE trading_symbol_id=%s AND value IS NOT NULL ORDER BY datetime", (symbol,))
            for r in c.fetchall():
                s = self.series.setdefault(r["indicator"], {"dt": [], "v": [], "p": [], "vf": []})
                s["dt"].append(r["datetime"]); s["v"].append(float(r["value"]))
                s["p"].append(float(r["price"]) if r["price"] is not None else None)
                s["vf"].append(int(r["volume_found"]))
            # rules
            c.execute("SELECT rule_number, sort, indicator, subrulename, def1_value, b_min, b_max, "
                      "value_condition FROM rules WHERE rule_number IN (20,21,22,23) AND active=1 "
                      "ORDER BY rule_number, sort, id")
            self.rules = {}
            for r in c.fetchall():
                self.rules.setdefault(r["rule_number"], []).append(r)
            # min_volume per rule
            c.execute("SELECT rule_number, min_volume FROM coin_rule_settings WHERE trading_symbol_id=%s", (symbol,))
            self.minvol = {r["rule_number"]: float(r["min_volume"]) if r["min_volume"] is not None else 1e12
                           for r in c.fetchall()}

    def close(self):
        if self._own:
            self.conn.close()

    def _idx(self, ind, T):
        s = self.series.get(ind)
        return (s, bisect.bisect_right(s["dt"], T)) if s else (None, 0)

    def _vals(self, ind, n, T):
        s, i = self._idx(ind, T)
        if not s:
            return [], []
        lo = max(0, i - n)
        return s["v"][lo:i][::-1], [p for p in s["p"][lo:i][::-1] if p is not None]

    def _vol_rows(self, T, minutes, n=None):
        s, i = self._idx("volumeud", T)
        if not s:
            return []
        cut = T - timedelta(minutes=minutes); out = []; j = i - 1
        while j >= 0 and s["dt"][j] >= cut and (n is None or len(out) < n):
            out.append({"datetime": s["dt"][j], "value": s["v"][j], "price": s["p"][j]}); j -= 1
        return out

    @staticmethod
    def _passes(v, lo, hi):
        if v is None:
            return None
        if v == "PASS":
            return True
        if lo is not None and v < float(lo):
            return False
        if hi is not None and v > float(hi):
            return False
        return True

    def _fire_at(self, rule, T):
        mv = self.minvol.get(rule, 1e12)
        vset = volume_settings(rule)
        for sr in self.rules[rule]:
            name = sr["subrulename"]
            def1 = int(sr["def1_value"]) if sr["def1_value"] else 1
            if name == "volume_check":
                if not check_volumeud_3(self._vol_rows(T, 60), mv, vset):
                    return False
                continue
            if name == "missingdata":
                v = round(missingdata(self._vol_rows(T, 300, def1)), 4)
                if self._passes(v, sr["b_min"], sr["b_max"]) is False:
                    return False
                continue
            vc = json.loads(sr["value_condition"]) if sr["value_condition"] else {}
            n = def1 if name != "currentvalue" else 1
            vals, prices = self._vals(sr["indicator"], n, T)
            v = subrule_value(name, vc, vals, prices)
            if self._passes(v, sr["b_min"], sr["b_max"]) is False:
                return False
        return True

    def fires(self, rule, frm=None, to=None):
        """OUR fire datetimes for `rule` over [frm,to] — candidates gated by volume_found=1."""
        s = self.series.get("volumeud")
        if not s:
            return []
        out = []
        for i, dt in enumerate(s["dt"]):
            if s["vf"][i] != 1:
                continue
            if frm and dt < frm:
                continue
            if to and dt >= to:
                break
            if self._fire_at(rule, dt):
                out.append(dt)
        return out


if __name__ == "__main__":
    import datetime as _dt
    sym = int(sys.argv[1]) if len(sys.argv) > 1 else 2525
    frm = _dt.datetime.strptime(sys.argv[2], "%Y-%m-%d %H:%M:%S") if len(sys.argv) > 2 else None
    to = _dt.datetime.strptime(sys.argv[3], "%Y-%m-%d %H:%M:%S") if len(sys.argv) > 3 else None
    eng = RuleEngine(sym)
    print(f"=== rule_engine — symbol {sym} (our rules over brain.indicators) ===")
    for r in RULES:
        f = eng.fires(r, frm, to)
        print(f"  rule {r}: {len(f)} fires")
    eng.close()
