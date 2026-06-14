"""
opt_diag — rule-firing DIAGNOSTICS for the optimisation study (read-only, creates nothing).

The cache (opt_lib) covers the metric-based questions (RQ1 tighten, OOS validation). The two
questions that need the ACTUAL firing logic live here:

  RQ2 (earlier entry): re-fire a rule with ONE subrule band widened and measure whether new fires
                       appear earlier with more best_upside and NO new bad trade.
  RQ4 (near-miss):     at promising-period candidate datetimes that produced NO executed trade,
                       count how many subrules of a rule are FALSE — the rule "almost" fired.

Wraps RuleEngine (brain.indicators + brain.rules, bot_signals untouched). best_upside is the best
available exit within FORWARD_MINUTES (the trade's quality, NOT our sell P&L). Promising verdict =
the good-moment ground truth (PromisingEngine).
"""
import bisect
import datetime as _dt
import json
from datetime import timedelta

from db import brain
from calc import subrule_value
from config import FORWARD_MINUTES
from rule_engine import RuleEngine
from volume import missingdata, check_volumeud_3, volume_settings

GOOD_UPSIDE = 3.0
BAD_UPSIDE = 0.5


def _cls(u):
    if u is None:
        return None
    return "goed" if u >= GOOD_UPSIDE else ("slecht" if u < BAD_UPSIDE else "middel")


class DiagEngine(RuleEngine):
    """RuleEngine plus per-subrule diagnostics, band overrides, and best_upside."""

    def __init__(self, symbol, conn=None):
        super().__init__(symbol, conn=conn)
        s = self.series.get("volumeud", {"dt": [], "p": []})
        # price timeline (newest price carried where missing) for best_upside
        self._pdt = s["dt"]
        self._ppx = s["p"]

    # ---- price / quality ------------------------------------------------
    def price_at(self, T):
        i = bisect.bisect_right(self._pdt, T)
        while i > 0 and self._ppx[i - 1] is None:
            i -= 1
        return self._ppx[i - 1] if i > 0 else None

    def best_upside(self, T, buy=None):
        """Max favourable excursion (%) within [T, T+FORWARD_MINUTES] vs buy price."""
        buy = buy if buy is not None else self.price_at(T)
        if not buy:
            return None
        lo = bisect.bisect_left(self._pdt, T)
        hi = bisect.bisect_right(self._pdt, T + timedelta(minutes=FORWARD_MINUTES))
        px = [p for p in self._ppx[lo:hi] if p is not None]
        if not px:
            return None
        return round((max(px) - buy) / buy * 100, 3)

    # ---- per-subrule status (no short-circuit) --------------------------
    def subrule_status(self, rule, T):
        """Return one dict per subrule of `rule` at datetime T: indicator, subrulename, def1,
        band, the computed value, and passed (True/False/None). Mirrors RuleEngine._fire_at
        but evaluates EVERY subrule (no early return) — the substrate for near-miss counting."""
        mv = self.minvol.get(rule, 1e12)
        vset = volume_settings(rule)
        out = []
        for k, sr in enumerate(self.rules[rule]):
            name = sr["subrulename"]
            def1 = int(sr["def1_value"]) if sr["def1_value"] else 1
            if name == "volume_check":
                ok = check_volumeud_3(self._vol_rows(T, 60), mv, vset)
                out.append({"i": k, "indicator": "volumeud", "subrulename": name, "def1": None,
                            "b_min": None, "b_max": None, "value": None, "passed": bool(ok)})
                continue
            if name == "missingdata":
                v = round(missingdata(self._vol_rows(T, 300, def1)), 4)
                p = self._passes(v, sr["b_min"], sr["b_max"])
                out.append({"i": k, "indicator": "volumeud", "subrulename": name, "def1": def1,
                            "b_min": sr["b_min"], "b_max": sr["b_max"], "value": v, "passed": p})
                continue
            vc = json.loads(sr["value_condition"]) if sr["value_condition"] else {}
            n = def1 if name != "currentvalue" else 1
            vals, prices = self._vals(sr["indicator"], n, T)
            v = subrule_value(name, vc, vals, prices)
            p = self._passes(v, sr["b_min"], sr["b_max"])
            out.append({"i": k, "indicator": sr["indicator"], "subrulename": name, "def1": def1,
                        "b_min": sr["b_min"], "b_max": sr["b_max"],
                        "value": (v if v != "PASS" else "PASS"), "passed": (True if v == "PASS" else p)})
        return out

    def n_failing(self, rule, T):
        st = self.subrule_status(rule, T)
        fails = [s for s in st if s["passed"] is False]
        return len(fails), fails

    # ---- re-fire with a band override -----------------------------------
    def fire_at_override(self, rule, T, overrides):
        """Like _fire_at, but `overrides` = {subrule_index: (b_min, b_max)} replaces those bands.
        Returns True iff every (possibly-overridden) subrule passes at T."""
        mv = self.minvol.get(rule, 1e12)
        vset = volume_settings(rule)
        for k, sr in enumerate(self.rules[rule]):
            name = sr["subrulename"]
            def1 = int(sr["def1_value"]) if sr["def1_value"] else 1
            bmin, bmax = overrides.get(k, (sr["b_min"], sr["b_max"]))
            if name == "volume_check":
                if not check_volumeud_3(self._vol_rows(T, 60), mv, vset):
                    return False
                continue
            if name == "missingdata":
                v = round(missingdata(self._vol_rows(T, 300, def1)), 4)
                if self._passes(v, bmin, bmax) is False:
                    return False
                continue
            vc = json.loads(sr["value_condition"]) if sr["value_condition"] else {}
            n = def1 if name != "currentvalue" else 1
            vals, prices = self._vals(sr["indicator"], n, T)
            v = subrule_value(name, vc, vals, prices)
            if self._passes(v, bmin, bmax) is False:
                return False
        return True

    def candidates(self, frm=None, to=None):
        """All volume_found=1 datetimes (the rule's candidate moments), optionally windowed."""
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
            out.append(dt)
        return out

    def fires_override(self, rule, overrides, frm=None, to=None):
        """Fire datetimes for `rule` over [frm,to] with band overrides applied."""
        return [dt for dt in self.candidates(frm, to) if self.fire_at_override(rule, dt, overrides)]


def promising_verdicts(symbol):
    """Map of promising periods for a coin from brain.coin_periods: list of (from, to, period_id,
    best_entry, best_upside). Used by RQ4 to find periods with NO executed trade."""
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT id, period_from, period_to, best_entry, best_upside FROM coin_periods "
                  "WHERE trading_symbol_id=%s ORDER BY period_from", (symbol,))
        periods = c.fetchall()
        c.execute("SELECT DISTINCT period_id FROM coin_fires WHERE trading_symbol_id=%s "
                  "AND is_executed=1 AND period_id IS NOT NULL", (symbol,))
        traded = {r["period_id"] for r in c.fetchall()}
    conn.close()
    return periods, traded


if __name__ == "__main__":
    import sys
    sym = int(sys.argv[1]) if len(sys.argv) > 1 else 2525
    eng = DiagEngine(sym)
    periods, traded = promising_verdicts(sym)
    no_trade = [p for p in periods if p["id"] not in traded]
    print(f"=== opt_diag smoke — symbol {sym} ===")
    print(f"promising periods: {len(periods)} | with an executed trade: {len(traded)} | "
          f"WITHOUT a trade: {len(no_trade)}")
    # demo: per rule, at each no-trade period's best_entry, how many subrules fail
    from collections import Counter
    for rule in (20, 21, 22, 23):
        cnt = Counter()
        for p in no_trade[:300]:
            nf, _ = eng.n_failing(rule, p["best_entry"])
            cnt[nf] += 1
        near = sum(v for k, v in cnt.items() if 0 < k < 3)
        print(f"  rule {rule}: near-miss (<3 subrules false) at {near}/{min(len(no_trade),300)} "
              f"no-trade best-entries | fail-count histogram {dict(sorted(cnt.items()))}")
    eng.close()
