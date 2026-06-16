#!/usr/bin/env python3
"""
simulate_brain_vf_optimized — schat in waar de ratio's uitkomen als we omschakelen NAAR
brain_volume_found EN daarna de Rule-precisie-routine z'n werk laat doen. Read-only; raakt
brain.rules / coin_fires niet aan.

Pipeline (zoals routines.py rule-optimization + auto-apply, maar in-memory):
  1. shadow re-fire met brain_volume_found als candidate-gate → shadow trades per rule (beide coins)
  2. zoek per rule de sterkste SAFE single-tightening tegen die shadow trades (opt_lib.sweep_single +
     full_validation; zelfde logica als rq1_tighten.py)
  3. pas de allersterkste in-memory toe (eng.rules[rule].append(...))
  4. her-fire, herhaal tot geen SAFE meer gevonden wordt (of MAX_ITER)
  5. rapporteer per-rule + gepoolde eind-ratio

Beperkingen (eerlijk):
  - de indicator_metrics-cache is gebouwd voor de huidige legacy-gate trades + promising-periodes.
    Shadow trades op datetimes buiten de cache zijn niet SAFE-valideerbaar → conservatieve schatting
    (de echte routine zou iets verder kunnen komen).
  - alleen tighten in deze simulatie (geen auto_loosen-tak); idem conservatief.

Usage: simulate_brain_vf_optimized.py
"""
import bisect
import datetime as _dt
import json
import os
import sys

import numpy as np
import pandas as pd

from db import brain
from rule_engine import RuleEngine
from sell_engine import SellEngine
from config import FORWARD_MINUTES
import opt_lib as o

COINS = [2525, 244]
RULES = (20, 21, 22, 23)
GOOD = 3.0
BAD = 0.5
SAFE_KEEP = 0.98
MAX_ITER = 8        # auto_apply doet 1 subrule/rule/run; ~8 iteraties = 2 subrules/rule, dekt grootste deel
HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, "..", "out", "opt", "brain_vf_simulated.json")
LOG = os.path.join(HERE, "..", "out", "opt", "brain_vf_simulated.log")


def log(line):
    """Append-and-flush direct naar logfile (geen tail-buffer)."""
    sys.stdout.write(line + "\n")
    sys.stdout.flush()
    with open(LOG, "a") as f:
        f.write(line + "\n")


def in_memory_eng(sym, extra_subrules):
    """RuleEngine met brain_volume_found als gate + extra in-memory subrules per rule."""
    eng = RuleEngine(sym)
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT datetime, brain_volume_found vf FROM indicators WHERE trading_symbol_id=%s "
                  "AND indicator='volumeud' AND value IS NOT NULL ORDER BY datetime", (sym,))
        rows = c.fetchall()
    conn.close()
    vfmap = {r["datetime"]: int(r["vf"]) for r in rows}
    s = eng.series["volumeud"]
    s["vf"] = [vfmap.get(d, 0) for d in s["dt"]]
    for rule, subs in extra_subrules.items():
        for sub in subs:
            eng.rules[rule].append(sub)
    return eng


def price_at(DT, PX, d):
    i = bisect.bisect_right(DT, d)
    return PX[i - 1] if i > 0 else None


def best_upside(DT, PX, d, buy):
    if not buy:
        return None
    lo = bisect.bisect_left(DT, d)
    hi = bisect.bisect_right(DT, d + _dt.timedelta(minutes=FORWARD_MINUTES))
    return (max(PX[lo:hi]) - buy) / buy * 100 if lo < hi else None


def shadow_trades(extra_subrules):
    """Re-fire beide coins met brain_vf gate + extra subrules. Returns DataFrame of executed trades."""
    rows = []
    for sym in COINS:
        eng = in_memory_eng(sym, extra_subrules)
        sell_eng = SellEngine(sym)
        DT, PX = sell_eng.DT, sell_eng.PX
        all_fires = []
        for r in RULES:
            for d in eng.fires(r):
                all_fires.append((d, r))
        all_fires.sort()
        open_until = None
        for d, r in all_fires:
            if open_until is not None and d < open_until:
                continue
            buy = price_at(DT, PX, d)
            bu = best_upside(DT, PX, d, buy) if buy else None
            if bu is not None:
                rows.append({"sym": sym, "datetime": d, "rule": r, "best_upside": bu})
            sres = sell_eng.sell(d, buy, r) if buy else None
            open_until = sres["selling_date"] if sres else d
        sell_eng.close()
        eng.close()
    df = pd.DataFrame(rows)
    df["cls"] = df["best_upside"].apply(lambda u: "goed" if u >= GOOD else ("slecht" if u < BAD else "middel"))
    df["split"] = "train"
    for sym, g in df.groupby("sym"):
        if len(g) < 5:
            continue
        cut = g["datetime"].sort_values().iloc[int(len(g) * 0.7)]
        df.loc[(df["sym"] == sym) & (df["datetime"] > cut), "split"] = "test"
    return df


def best_safe_tighten(long, rule):
    """Sterkste SAFE single tightening — sweep_single + full_validation, skip SCALE_UNSAFE."""
    sw = o.sweep_single(long, rule)
    if sw.empty:
        return None
    for _, r in sw.iterrows():
        if o.scale_unsafe(r["indicator"], r["calc"]):
            continue
        splits = o.full_validation(long, rule, r["indicator"], int(r["lookback"]),
                                   r["calc"], r["bound"])
        keeps = [s["good_keep"] for s in splits.values() if not np.isnan(s.get("good_keep", float("nan")))]
        if keeps and min(keeps) >= SAFE_KEEP:
            return {"rule": rule, "indicator": r["indicator"], "calc": r["calc"],
                    "lookback": int(r["lookback"]), "bound": r["bound"],
                    "threshold": float(r["threshold"]), "drop": int(r["drop_insample"])}
    return None


def per_rule_counts(trades):
    out = {}
    for r in RULES:
        sub = trades[trades["rule"] == r]
        g = int((sub["cls"] == "goed").sum())
        b = int((sub["cls"] == "slecht").sum())
        m = int((sub["cls"] == "middel").sum())
        out[r] = {"goed": g, "slecht": b, "middel": m, "ratio": round(g / b, 2) if b else None}
    return out


def pooled(per):
    g = sum(v["goed"] for v in per.values())
    b = sum(v["slecht"] for v in per.values())
    return {"goed": g, "slecht": b, "ratio": round(g / b, 2) if b else None}


def fmt(label, per):
    p = pooled(per)
    body = " | ".join(f"r{r}: {v['goed']}g/{v['slecht']}s ({v['ratio']})" for r, v in per.items())
    return f"  {label}:  totaal {p['goed']}g/{p['slecht']}s ratio {p['ratio']}  | {body}"


def main():
    extra = {r: [] for r in RULES}
    history = []
    log("=== iter 0: shadow re-fire met brain_volume_found, géén optimalisatie ===")
    tr0 = shadow_trades(extra)
    per0 = per_rule_counts(tr0)
    log(fmt("baseline", per0))
    history.append({"iter": 0, "per_rule": per0, "applied": None})

    for it in range(1, MAX_ITER + 1):
        long = o.load_long(trades=tr0)
        best = None
        for r in RULES:
            c = best_safe_tighten(long, r)
            if c and (best is None or c["drop"] > best["drop"]):
                best = c
        if not best:
            log(f"\niter {it}: geen SAFE tightening meer — geconvergeerd na {it-1} stappen")
            break
        rule = best["rule"]
        b_min = best["threshold"] if best["bound"] == "lower" else None
        b_max = None if best["bound"] == "lower" else best["threshold"]
        sub = {"rule_number": rule, "sort": 9999 + it, "indicator": best["indicator"],
               "subrulename": best["calc"], "def1_value": best["lookback"],
               "b_min": b_min, "b_max": b_max, "value_condition": None}
        extra[rule].append(sub)
        tr0 = shadow_trades(extra)
        per = per_rule_counts(tr0)
        bnd = "≥" if best["bound"] == "lower" else "≤"
        log(f"\niter {it}: + rule {rule} {best['indicator']}/{best['calc']}/lb{best['lookback']} "
              f"{bnd} {round(best['threshold'], 4)} (in-sample dropt {best['drop']})")
        log(fmt("na deze stap", per))
        history.append({"iter": it, "per_rule": per,
                        "applied": {"rule": rule, "indicator": best["indicator"],
                                    "calc": best["calc"], "lookback": best["lookback"],
                                    "bound": best["bound"], "threshold": best["threshold"],
                                    "drop_insample": best["drop"]}})

    # huidig (legacy gate, uit coin_fires) als benchmark
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT rule, SUM(is_executed=1 AND best_upside>=%s) g, "
                  "SUM(is_executed=1 AND best_upside<%s) b FROM coin_fires "
                  "WHERE best_upside IS NOT NULL GROUP BY rule ORDER BY rule", (GOOD, BAD))
        cur = {r["rule"]: {"goed": int(r["g"] or 0), "slecht": int(r["b"] or 0),
                           "middel": 0, "ratio": round(int(r["g"] or 0) / int(r["b"] or 1), 2) if r["b"] else None}
               for r in c.fetchall()}
    conn.close()

    log("\n=== EINDSAMENVATTING ===")
    log(fmt("NU (legacy-gate, na ~30 routine-runs):  ", cur))
    log(fmt("brain-gate, ZONDER optimalisatie:        ", history[0]["per_rule"]))
    log(fmt("brain-gate, NA simulatie van routine:    ", history[-1]["per_rule"]))

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, "w") as f:
        json.dump({"current_legacy": cur, "history": history}, f, indent=2, default=str)
    log(f"\nrapport -> {OUT}")


if __name__ == "__main__":
    main()
