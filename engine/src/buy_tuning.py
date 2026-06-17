#!/usr/bin/env python3
"""
Buy-tuning meet-instrument (futureprice-finetuning) — READ-ONLY, muteert NIETS.

Stelt de koop-bevestigings-drempels af: de futureprice `b_min` per rule (20/22/23) — hoe ver de prijs
mag dippen voordat de trade wordt afgeblazen. Een andere b_min verandert WELKE trades doorgaan, dus dit
is buy-zijde tuning (de tegenhanger van sell_tuning). Meetlat = Σprofit + #verliezers, holdout leidend
(oude helft afstellen, nieuwe helft bevestigen).

Snel: de fires (rule_engine) en de sells (sell_engine) hangen NIET van futureprice af, dus we
memoïseren de sells per (datetime, rule) en draaien per kandidaat alleen de dedup + bevestiging
opnieuw in-memory. Instap = signaalprijs (zoals legacy / persist_to_brain).

Usage: buy_tuning.py [symbol_id ...]   (default 2525 244; schrijft out/opt/buy_tuning_<date>.json)
"""
import sys
import json
import os
import bisect
import datetime
from collections import defaultdict

from db import brain
from rule_engine import RuleEngine, RULES
from sell_engine import SellEngine
from buy_confirm import confirm_buy, params_by_rule

COINS = [int(a) for a in sys.argv[1:] if a.isdigit()] or [2525, 244]
# b_min-grid per rule (rond de huidige legacy-waarde). Strakker (dichter bij 0) = strenger filter.
GRID = [-2.0, -1.5, -1.2, -1.0, -0.7, -0.5, -0.3, -0.2, -0.1]


def build(sym, conn):
    """Fires (alle rules, chronologisch) + gememoïseerde sell per (dt, rule). Geeft de bouwstenen terug."""
    re_ = RuleEngine(sym)
    fires = sorted((dt, rule) for rule in RULES for dt in re_.fires(rule))
    re_.close()
    se = SellEngine(sym, conn=conn)
    DT, PX = se.DT, se.PX

    def price_at(dt):
        i = bisect.bisect_right(DT, dt)
        return PX[i - 1] if i > 0 else None

    sell_cache = {}

    def sell(dt, sp, rule):
        k = (dt, rule)
        if k not in sell_cache:
            sell_cache[k] = se.sell(dt, sp, rule)
        return sell_cache[k]

    return {"fires": fires, "DT": DT, "PX": PX, "price_at": price_at, "sell": sell, "se": se}


def evaluate(b, fp_params):
    """Dedup + koop-bevestiging in-memory → lijst (dt, rule, pl) van executed trades."""
    DT, PX, price_at, sell = b["DT"], b["PX"], b["price_at"], b["sell"]
    open_until = None
    out = []
    for (dt, rule) in b["fires"]:
        if open_until is not None and dt <= open_until:
            continue                                     # shadow
        sp = price_at(dt)
        if sp is None:
            continue
        fp = fp_params.get(rule)
        if fp and confirm_buy(DT, PX, dt, sp, fp["bmin"], fp["window"], fp["xrows"]) is None:
            continue                                     # afgeblazen
        r = sell(dt, sp, rule)
        if not r:
            continue
        open_until = r["selling_date"]
        out.append((dt, rule, r["profit_loss"]))
    return out


def agg(trades, rule=None, lo=None, hi=None):
    """(Σprofit, n, #verliezers) over (optioneel) een rule en een datetime-venster [lo, hi)."""
    sel = [pl for (dt, r, pl) in trades if (rule is None or r == rule)
           and (lo is None or dt >= lo) and (hi is None or dt < hi)]
    return round(sum(sel), 1), len(sel), sum(1 for pl in sel if pl < 0)


def verdict(bt, bh, ct, ch):
    """bt/bh = baseline train/holdout (Σ,n,verlies); ct/ch = kandidaat. SAFE = op BEIDE helften
    Σprofit niet omlaag én verliezers niet omhoog, en op minstens één as strikt beter."""
    niet_slechter = (ct[0] >= bt[0] and ct[2] <= bt[2] and ch[0] >= bh[0] and ch[2] <= bh[2])
    strikt_beter = (ct[0] > bt[0] or ct[2] < bt[2]) and (ch[0] > bh[0] or ch[2] < bh[2])
    if not niet_slechter:
        return "WORSE"
    return "SAFE" if strikt_beter else "NEUTRAL"


def measure(coins=None, conn=None, write_json=True, verbose=True):
    own = conn is None
    conn = conn or brain()
    coins = coins or COINS
    p = (lambda *a: print(*a)) if verbose else (lambda *a: None)
    report = {"generated_at": datetime.datetime.now().isoformat(timespec="seconds"),
              "median_split": {}, "baseline": {}, "proposals": []}
    p("=" * 80)
    p("BUY-TUNING — futureprice b_min afstellen (read-only). Meetlat = Σprofit + verliezers, holdout leidend.")
    p("=" * 80)

    for sym in coins:
        b = build(sym, conn)
        fp_base = params_by_rule(conn)
        base = evaluate(b, fp_base)
        dts = sorted(dt for (dt, _, _) in base)
        med = dts[len(dts) // 2] if dts else None
        report["median_split"][sym] = med.isoformat() if med else None
        tunable = [r for r in (20, 22, 23) if r in fp_base]
        bsig, bn, bver = agg(base)
        report["baseline"][sym] = {"n": bn, "sigma": bsig, "verliezers": bver}
        p(f"\n### {sym} — baseline {bn} trades, Σ{bsig:+.1f}%, {bver} verlies · split {med}")

        for rule in tunable:
            cur = fp_base[rule]["bmin"]
            bt = agg(base, rule, None, med)
            bh = agg(base, rule, med, None)
            best = None
            for val in GRID:
                if abs(val - cur) < 1e-9:
                    continue
                fp_cand = {r: dict(v) for r, v in fp_base.items()}
                fp_cand[rule]["bmin"] = val
                cand = evaluate(b, fp_cand)
                ct = agg(cand, rule, None, med)
                ch = agg(cand, rule, med, None)
                vd = verdict(bt, bh, ct, ch)
                d_sig = round((ct[0] + ch[0]) - (bt[0] + bh[0]), 1)
                d_ver = (ct[2] + ch[2]) - (bt[2] + bh[2])
                prop = {"coin": sym, "rule": rule, "knob": "futureprice.b_min", "from": cur, "to": val,
                        "verdict": vd, "delta_sigma": d_sig, "delta_verliezers": d_ver,
                        "train": {"sigma": ct[0], "verlies": ct[2]}, "holdout": {"sigma": ch[0], "verlies": ch[2]}}
                report["proposals"].append(prop)
                # beste = grootste ΔΣprofit; bij gelijke winst de ZACHTSTE aanpassing (b_min dichtst
                # bij de huidige), zodat de routine de filter niet onnodig ver losdraait.
                if vd == "SAFE" and (best is None or d_sig > best["delta_sigma"]
                                     or (d_sig == best["delta_sigma"] and abs(val - cur) < abs(best["to"] - cur))):
                    best = prop
            line = f"  rule {rule} (b_min {cur}): Σ{bt[0]+bh[0]:+.1f}% / {bt[2]+bh[2]} verlies"
            if best:
                line += (f"  →  b_min {best['to']}: ΔΣ {best['delta_sigma']:+.1f}%, "
                         f"Δverlies {best['delta_verliezers']:+d}  [SAFE]")
            else:
                line += "  →  geen veilig voorstel"
            p(line)
        b["se"].close()

    safe = [x for x in report["proposals"] if x["verdict"] == "SAFE"]
    p(f"\n--- {len(safe)} SAFE van {len(report['proposals'])} gemeten ---")
    for x in sorted(safe, key=lambda x: -x["delta_sigma"])[:6]:
        p(f"   {x['coin']} r{x['rule']} b_min {x['from']}→{x['to']}: "
          f"ΔΣ {x['delta_sigma']:+.1f}%, Δverlies {x['delta_verliezers']:+d}")

    if write_json:
        os.makedirs("out/opt", exist_ok=True)
        path = f"out/opt/buy_tuning_{datetime.date.today().isoformat()}.json"
        with open(path, "w") as f:
            json.dump(report, f, indent=2, default=str)
        report["report_path"] = path
        p(f"\nrapport → engine/src/{path}")
    if own:
        conn.close()
    return report


if __name__ == "__main__":
    measure(write_json=True, verbose=True)
