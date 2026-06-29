#!/usr/bin/env python3
"""
FASE 2 — READ-ONLY simulatie: helpt een VROEGE-EXIT (op het pad ná instap) rule 30/31?
Echte sim: per trade de baseline sell() herrekenen (huidige stop, in-memory), dan een vroege-exit op
de ECHTE tick-prijzen toepassen — de vroegste exit (vroege-exit vs gewone sell) wint. Effect gemeten in
gerealiseerde profit_loss, met train/holdout-split per munt, toeval-toets (sign-flip + Šidák) en echte
flip-cost (hoeveel GOEDE / overlap-winnaars sneuvelen).

Varianten:
  scratch(W,G): geen follow-through — als de prijs binnen W min nooit >= +G% kwam, sluit op de tick op T+W.
  dip(W,Z):     snelle dip-stop — eerste tick binnen W min met prijs <= -Z%, sluit daar (op -Z%).

GEEN DB-mutatie, geen refire. Draai: engine/.venv/bin/python sim_early_exit_30_31.py
"""
import bisect
from collections import defaultdict
from datetime import timedelta

import numpy as np

import opt_lib as ol
from db import brain
from sell_engine import SellEngine

RULES = (30, 31)
SCRATCH = [(5, 0.5), (5, 1.0), (10, 0.5), (10, 1.0), (10, 1.5), (15, 0.5), (15, 1.0)]
DIP = [(10, 1.0), (10, 1.5), (10, 2.0), (15, 1.5), (15, 2.0)]


def price_at(eng, dt):
    i = bisect.bisect_right(eng.DT, dt)
    return eng.PX[i - 1] if i > 0 else None


def load():
    conn = brain()
    c = conn.cursor()
    c.execute("""
        SELECT cf.trading_symbol_id sym, cf.rule, cf.datetime buy_dt
        FROM coin_fires cf
        WHERE cf.rule IN (30,31) AND cf.is_executed=1 AND cf.profit_loss IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM coin_regime r WHERE r.trading_symbol_id=cf.trading_symbol_id
                          AND r.state='inactive' AND cf.datetime>=r.period_from
                          AND cf.datetime<r.period_to + INTERVAL 1 DAY)
        ORDER BY cf.trading_symbol_id, cf.datetime""")
    trades = c.fetchall()
    c.execute("SELECT trading_symbol_id sym, datetime dt FROM coin_fires WHERE rule IN (20,21,22,23)")
    f2023 = defaultdict(list)
    for r in c.fetchall():
        f2023[r["sym"]].append(r["dt"])
    for k in f2023:
        f2023[k].sort()
    conn.close()
    return trades, f2023


def has_overlap(f2023, sym, buy_dt, sell_dt):
    lst = f2023.get(sym, [])
    i = bisect.bisect_right(lst, buy_dt)
    return i < len(lst) and lst[i] <= (sell_dt or buy_dt)


def scratch_exit(eng, buy_dt, buy, sell_dt, W, G):
    """None als er follow-through was; anders (T_ee, P_ee) op de W-grens."""
    lo = bisect.bisect_left(eng.DT, buy_dt)
    hi = bisect.bisect_right(eng.DT, buy_dt + timedelta(minutes=W))
    if lo >= hi:
        return None
    seg = eng.PX[lo:hi]
    if max(seg) >= buy * (1 + G / 100):
        return None                                   # follow-through → laat de gewone sell het doen
    j = hi - 1                                          # laatste tick binnen W
    return eng.DT[j], eng.PX[j]


def dip_exit(eng, buy_dt, buy, sell_dt, W, Z):
    lo = bisect.bisect_left(eng.DT, buy_dt)
    hi = bisect.bisect_right(eng.DT, buy_dt + timedelta(minutes=W))
    thr = buy * (1 - Z / 100)
    for i in range(lo, hi):
        if eng.PX[i] <= thr:
            return eng.DT[i], thr                       # stop vult op de drempelprijs
    return None


def simulate(trades, f2023):
    by_sym = defaultdict(list)
    for t in trades:
        by_sym[t["sym"]].append(t)

    variants = ([("scratch", w, g, False) for (w, g) in SCRATCH]
                + [("dip", w, z, False) for (w, z) in DIP]
                + [("scratch", w, g, True) for (w, g) in [(10, 1.0), (15, 0.5), (15, 1.0)]])
    # per variant: lijsten van (delta, base_pl, new_pl, overlap, is_train)
    agg = {v: [] for v in variants}
    base_all = []                                        # (base_pl, overlap, is_train)

    for sym, ts in by_sym.items():
        eng = SellEngine(sym)
        # per-munt mediaan-datum voor de train/holdout-split
        dts = sorted(t["buy_dt"] for t in ts)
        split = dts[len(dts) // 2] if len(dts) >= 8 else None
        for t in ts:
            buy_dt, rule = t["buy_dt"], t["rule"]
            buy = price_at(eng, buy_dt)
            if not buy:
                continue
            base = eng.sell(buy_dt, buy, rule)
            if not base:
                continue
            base_pl = base["profit_loss"]
            sell_dt = base["selling_date"]
            ov = has_overlap(f2023, sym, buy_dt, sell_dt)
            is_train = (split is not None and buy_dt < split)
            base_all.append((base_pl, ov, is_train))
            for v in variants:
                kind, w, p, pure_only = v
                if pure_only and ov:
                    agg[v].append((0.0, base_pl, base_pl, ov, is_train))
                    continue
                ee = (scratch_exit(eng, buy_dt, buy, sell_dt, w, p) if kind == "scratch"
                      else dip_exit(eng, buy_dt, buy, sell_dt, w, p))
                if ee and sell_dt and ee[0] < sell_dt:
                    new_pl = round((ee[1] - buy) / buy * 100, 3)
                else:
                    new_pl = base_pl
                agg[v].append((new_pl - base_pl, base_pl, new_pl, ov, is_train))
        eng.close() if hasattr(eng, "close") else None
    return variants, agg, base_all


def report(variants, agg, base_all):
    n = len(base_all)
    base_sum = sum(b for b, _, _ in base_all)
    base_los = sum(b < 0 for b, _, _ in base_all)
    ho = [b for b, _, tr in base_all if not tr]
    ho_sum = sum(ho)
    ho_los = sum(x < 0 for x in ho)
    print("=" * 92)
    print(f"VROEGE-EXIT SIMULATIE rule 30/31 — {n} trades (baseline via in-memory sell())")
    print(f"  baseline TOTAAL : Σ {base_sum:+.0f}% | verliezers {base_los}/{n} ({base_los/n*100:.1f}%)")
    print(f"  baseline HOLDOUT: Σ {ho_sum:+.0f}% | verliezers {ho_los}/{len(ho)} ({ho_los/len(ho)*100:.1f}%)")
    print("=" * 92)
    n_hyp = len(variants)
    p_req = ol.required_raw_p(n_hyp, 0.05)
    print(f"Šidák: {n_hyp} varianten → vereiste ruwe p < {p_req:.5f}\n")
    print(f"{'variant':16s} {'ΣΔ tot':>8s} {'ΣΔ hout':>8s} {'verl→':>10s} {'GOED gekapt':>11s} "
          f"{'ovl-win':>7s} {'p_perm(hout)':>12s} {'oordeel':>8s}")
    rows = []
    for v in variants:
        data = agg[v]
        dtot = sum(d for d, *_ in data)
        # holdout
        hd = [(d, bpl, npl, ov) for (d, bpl, npl, ov, tr) in data if not tr]
        dho = sum(d for d, *_ in hd)
        new_los_ho = sum(npl < 0 for _, _, npl, _ in hd)
        # flip-cost: trades die baseline GOED (>=3) waren en nu lager
        good_cut = sum(1 for (d, bpl, npl, ov, tr) in data if bpl >= 3 and npl < bpl)
        ovl_win_cut = sum(1 for (d, bpl, npl, ov, tr) in data if ov and bpl >= 0 and npl < bpl)
        deltas_ho = [d for d, *_ in hd if d != 0]
        res = ol.signflip_pvalue(deltas_ho) if len(deltas_ho) >= 5 else None
        p = res["p"] if res else None
        floor_ok = res is not None and res["floor"] <= p_req
        ok = (dho > 0 and good_cut == 0 and p is not None and p < p_req and floor_ok)
        verdict = "SAFE" if ok else ("zwak" if dho > 0 else "—")
        name = f"{v[0]}(W{v[1]},{v[2]}){'P' if v[3] else ''}"
        pstr = f"{p:.4f}" if p is not None else "n/a"
        if res is not None and not floor_ok:
            pstr += "*"                                   # te weinig geraakte trades om te certificeren
        print(f"{name:16s} {dtot:+8.0f} {dho:+8.0f} {'h:'+str(new_los_ho):>10s} {good_cut:>11d} "
              f"{ovl_win_cut:>7d} {pstr:>12s} {verdict:>8s}")
    print("\nSAFE = ΣΔ holdout > 0 ÉN 0 goede trades gekapt ÉN p_perm < Šidák-drempel.")
    print("'verl→ h:N' = #verliezers in de holdout NÁ de vroege-exit (baseline holdout = %d)." % ho_los)


if __name__ == "__main__":
    trades, f2023 = load()
    variants, agg, base_all = simulate(trades, f2023)
    report(variants, agg, base_all)
