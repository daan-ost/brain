#!/usr/bin/env python3
"""
Sell-discovery — zoek verbeteringen in rule-101 subrules (verkoop-structuur, niet SL-knoppen).

Twee zoekrichtingen:
  1. PARAMETER-VARIATIES van bestaande subrules (previous_value b_min, sell_negative_volume vc)
  2. NIEUWE subrule-types (peak_reversal — nu inert, mogelijk nuttig met meer coins/data)

Alles met holdout-split per coin. Gecombineerde verdict: SAFE = nergens WORSE en minstens ergens
SAFE. Geen DB-mutaties — rapport naar out/opt/sell_discovery_<date>.json.

Usage: sell_discovery.py [symbol_id ...]   (default 2525 244)
"""
import sys
import json
import os
import bisect
import datetime

from db import brain
from rule_engine import RuleEngine, RULES
from sell_engine import SellEngine
from buy_confirm import confirm_buy, params_by_rule

COINS = [int(a) for a in sys.argv[1:] if a.isdigit()] or [2525, 244]

BMIN_DELTAS = [-0.5, -0.3, -0.1, +0.1, +0.3, +0.5]
SNV_VC_GRID = [0.95, 0.96, 0.97, 0.975, 0.985, 0.99, 0.995]


def build(sym, conn):
    re_ = RuleEngine(sym)
    fires = sorted((dt, rule) for rule in RULES for dt in re_.fires(rule))
    re_.close()
    se = SellEngine(sym, conn=conn)
    DT, PX = se.DT, se.PX

    def price_at(dt):
        i = bisect.bisect_right(DT, dt)
        return PX[i - 1] if i > 0 else None

    fp_params = params_by_rule(conn)
    return {"fires": fires, "DT": DT, "PX": PX, "price_at": price_at, "se": se,
            "fp_params": fp_params}


def evaluate(b, replace_subrules=None):
    se = b["se"]
    DT, PX, price_at, fp = b["DT"], b["PX"], b["price_at"], b["fp_params"]

    orig_sr = se.SUBRULES_101
    if replace_subrules is not None:
        se.SUBRULES_101 = replace_subrules

    open_until = None
    out = []
    for (dt, rule) in b["fires"]:
        if open_until is not None and dt <= open_until:
            continue
        sp = price_at(dt)
        if sp is None:
            continue
        fp_p = fp.get(rule)
        if fp_p and confirm_buy(DT, PX, dt, sp, fp_p["bmin"], fp_p["window"], fp_p["xrows"]) is None:
            continue
        r = se.sell(dt, sp, rule)
        if not r:
            continue
        open_until = r["selling_date"]
        out.append((dt, rule, r["profit_loss"]))

    se.SUBRULES_101 = orig_sr
    return out


def agg(trades, lo=None, hi=None):
    sel = [pl for (dt, _, pl) in trades if (lo is None or dt >= lo) and (hi is None or dt < hi)]
    return round(sum(sel), 1), len(sel), sum(1 for pl in sel if pl < 0)


def verdict(bt, bh, ct, ch):
    niet_slechter = (ct[0] >= bt[0] and ct[2] <= bt[2] and ch[0] >= bh[0] and ch[2] <= bh[2])
    strikt_beter = (ct[0] > bt[0] or ct[2] < bt[2]) and (ch[0] > bh[0] or ch[2] < bh[2])
    if not niet_slechter:
        return "WORSE"
    if not strikt_beter:
        if ct[1] == bt[1] and ct[2] == bt[2] and ch[1] == bh[1] and ch[2] == bh[2]:
            return "INERT"
        return "NEUTRAL"
    return "SAFE"


def _test_candidate(all_builds, all_baselines, all_medians, coins, modify_fn, label, extra_data=None):
    """Test one candidate across all coins. Returns a proposal dict."""
    combined_dsig = 0
    combined_dver = 0
    per_coin = {}
    all_safe_or_neutral = True

    for sym in coins:
        b = all_builds[sym]
        base = all_baselines[sym]
        med = all_medians[sym]
        bt = agg(base, None, med)
        bh = agg(base, med, None)

        modified = modify_fn(b["se"].SUBRULES_101)
        cand = evaluate(b, replace_subrules=modified)
        ct = agg(cand, None, med)
        ch = agg(cand, med, None)
        vd = verdict(bt, bh, ct, ch)

        d_sig = round((ct[0] + ch[0]) - (bt[0] + bh[0]), 1)
        d_ver = (ct[2] + ch[2]) - (bt[2] + bh[2])
        combined_dsig += d_sig
        combined_dver += d_ver
        per_coin[sym] = {"verdict": vd, "delta_sigma": d_sig, "delta_verliezers": d_ver,
                         "train": {"sigma": ct[0], "verlies": ct[2]},
                         "holdout": {"sigma": ch[0], "verlies": ch[2]}}
        if vd == "WORSE":
            all_safe_or_neutral = False

    any_safe = any(v["verdict"] == "SAFE" for v in per_coin.values())
    combined_vd = "SAFE" if all_safe_or_neutral and any_safe else (
        "NEUTRAL" if all_safe_or_neutral else "WORSE")

    prop = {"label": label, "combined_verdict": combined_vd,
            "combined_delta_sigma": round(combined_dsig, 1),
            "combined_delta_verliezers": combined_dver,
            "per_coin": per_coin}
    if extra_data:
        prop.update(extra_data)
    return prop


def measure(coins=None, conn=None, write_json=True, verbose=True):
    own = conn is None
    conn = conn or brain()
    coins = coins or COINS
    p = (lambda *a: print(*a)) if verbose else (lambda *a: None)
    report = {"generated_at": datetime.datetime.now().isoformat(timespec="seconds"),
              "proposals": []}

    p("=" * 80)
    p("SELL-DISCOVERY — rule-101 parameter-variaties + nieuwe types. Read-only.")
    p("=" * 80)

    all_builds, all_baselines, all_medians = {}, {}, {}

    for sym in coins:
        b = build(sym, conn)
        base = evaluate(b)
        dts = sorted(dt for (dt, _, _) in base)
        med = dts[len(dts) // 2] if dts else None
        all_builds[sym] = b
        all_baselines[sym] = base
        all_medians[sym] = med
        bsig, bn, bver = agg(base)
        p("\n### sym %s — baseline %d trades, Σ%+.1f%%, %d verlies · split %s" % (sym, bn, bsig, bver, med))
        report["baseline_%d" % sym] = {"n": bn, "sigma": bsig, "verliezers": bver}

    # --- 1. previous_value b_min variaties per lookback ---
    p("\n--- previous_value b_min variaties ---")
    subrule_lbs = set()
    for sr in all_builds[coins[0]]["se"].SUBRULES_101:
        if sr["subrulename"] == "previous_value":
            subrule_lbs.add(str(sr["def1_value"]))

    for lb in sorted(subrule_lbs):
        for delta in BMIN_DELTAS:
            def make_mod(lb_=lb, d_=delta):
                def modify(srs):
                    out = []
                    for sr in srs:
                        c = dict(sr)
                        if sr["subrulename"] == "previous_value" and str(sr["def1_value"]) == lb_:
                            c["b_min"] = str(round(float(sr["b_min"]) + d_, 2))
                        out.append(c)
                    return out
                return modify

            cur_bmin = None
            for sr in all_builds[coins[0]]["se"].SUBRULES_101:
                if sr["subrulename"] == "previous_value" and str(sr["def1_value"]) == lb:
                    cur_bmin = float(sr["b_min"])
            new_bmin = round(cur_bmin + delta, 2) if cur_bmin is not None else None

            prop = _test_candidate(all_builds, all_baselines, all_medians, coins,
                                   make_mod(),
                                   "prev_val lb%s b_min %s→%s" % (lb, cur_bmin, new_bmin),
                                   {"type": "param_variation", "subrulename": "previous_value",
                                    "lookback": lb, "knob": "b_min", "from": cur_bmin, "to": new_bmin})
            report["proposals"].append(prop)

    # --- 2. sell_negative_volume vc variaties ---
    p("--- sell_negative_volume vc variaties ---")
    cur_vc = None
    for sr in all_builds[coins[0]]["se"].SUBRULES_101:
        if sr["subrulename"] == "sell_negative_volume":
            cur_vc = float(sr["value_condition"])

    for vc in SNV_VC_GRID:
        if cur_vc is not None and abs(vc - cur_vc) < 1e-9:
            continue

        def make_mod_snv(vc_=vc):
            def modify(srs):
                out = []
                for sr in srs:
                    c = dict(sr)
                    if sr["subrulename"] == "sell_negative_volume":
                        c["value_condition"] = str(vc_)
                    out.append(c)
                return out
            return modify

        prop = _test_candidate(all_builds, all_baselines, all_medians, coins,
                               make_mod_snv(),
                               "sell_neg_vol vc %s→%s" % (cur_vc, vc),
                               {"type": "param_variation", "subrulename": "sell_negative_volume",
                                "knob": "value_condition", "from": cur_vc, "to": vc})
        report["proposals"].append(prop)

    # --- 3. NEW previous_value subrules at unused lookbacks ---
    p("--- nieuwe previous_value lookbacks ---")
    used_lbs = {str(sr["def1_value"]) for sr in all_builds[coins[0]]["se"].SUBRULES_101
                if sr["subrulename"] == "previous_value"}
    new_lbs = [lb for lb in [1, 6, 8, 9, 10] if str(float(lb)) not in used_lbs]

    for lb in new_lbs:
        for bmin in [-1.5, -1.0, -0.5, -0.3, -0.1, 0.0]:
            new_sr = {"subrulename": "previous_value", "def1_value": str(float(lb)),
                      "b_min": str(bmin), "b_max": None,
                      "value_condition": '{"diff_price":1}', "operator": "SL",
                      "condition_rule": "2"}

            def make_mod_new(sr_=new_sr):
                def modify(srs):
                    return list(srs) + [sr_]
                return modify

            prop = _test_candidate(all_builds, all_baselines, all_medians, coins,
                                   make_mod_new(),
                                   "NEW prev_val lb%d b_min=%s" % (lb, bmin),
                                   {"type": "new_subrule", "subrulename": "previous_value",
                                    "lookback": str(float(lb)), "knob": "b_min", "to": bmin})
            report["proposals"].append(prop)

    for sym in coins:
        all_builds[sym]["se"].close()

    safe = [x for x in report["proposals"] if x["combined_verdict"] == "SAFE"]
    safe.sort(key=lambda x: (-x["combined_delta_sigma"], x["combined_delta_verliezers"]))

    p("\n--- %d SAFE van %d geteste kandidaten ---" % (len(safe), len(report["proposals"])))
    for x in safe[:15]:
        coins_detail = " | ".join("%s:%s ΔΣ%+.1f Δv%+d" % (s, v["verdict"], v["delta_sigma"], v["delta_verliezers"])
                                  for s, v in sorted(x["per_coin"].items()))
        p("  %s: ΔΣ %+.1f%%, Δverlies %+d  |  %s" % (
            x["label"], x["combined_delta_sigma"], x["combined_delta_verliezers"], coins_detail))

    if write_json:
        os.makedirs("out/opt", exist_ok=True)
        path = "out/opt/sell_discovery_%s.json" % datetime.date.today().isoformat()
        with open(path, "w") as f:
            json.dump(report, f, indent=2, default=str)
        report["report_path"] = path
        p("\nrapport → engine/src/%s" % path)

    if own:
        conn.close()
    return report


if __name__ == "__main__":
    measure(write_json=True, verbose=True)
