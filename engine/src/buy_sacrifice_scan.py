#!/usr/bin/env python3
"""
ONDERZOEK (read-only, muteert NIETS): offert buy-tuning ooit een goede trade op om slechte te weren?

Voor elke rule (20/22/23) over ALLE coins heen, het hele b_min-grid: vergelijk de trade-set met de
baseline en kijk welke WINNAARS verdwijnen (geofferd) en welke VERLIEZERS daardoor voorkomen worden.
Toets Daans ruil-regels:

  R1 (hard):  een geofferde winnaar met winst > GOOD_HARD_CAP%  → VERBODEN (kroonjuweel, nooit offeren)
  R2 (ratio): #voorkomen verliezers >= MIN_BAD_PER_GOOD * #geofferde winnaars
  R3 (som):   Σ|voorkomen verlies| > Σ geofferde winst

Meet de ECHTE trade-wereld: shadow + koop-bevestiging zoals live, INCL. handmatige hard-sell overrides.
Over coins heen, want b_min zit op rule-niveau en raakt alle coins tegelijk.

Usage: buy_sacrifice_scan.py [symbol_id ...]   (default 2525 244)
"""
import sys
import bisect
from collections import defaultdict

from db import brain
from rule_engine import RuleEngine, RULES
from sell_engine import SellEngine
from buy_confirm import confirm_buy, params_by_rule

COINS = [int(a) for a in sys.argv[1:] if a.isdigit()] or [2525, 244]
GRID = [-2.0, -1.5, -1.2, -1.0, -0.7, -0.5, -0.3, -0.2, -0.1]

GOOD_HARD_CAP = 10.0   # R1: winnaar > 10% nooit offeren
MIN_BAD_PER_GOOD = 5   # R2: minstens 5 slechte voorkomen per 1 geofferde goede


def load_overrides(conn, sym):
    with conn.cursor() as c:
        c.execute("SELECT datetime, hard_sell_datetime FROM coin_moment_labels "
                  "WHERE trading_symbol_id=%s AND source='manual'", (sym,))
        return {r["datetime"]: r["hard_sell_datetime"] for r in c.fetchall()}


def build(sym, conn):
    re_ = RuleEngine(sym)
    fires = sorted((dt, rule) for rule in RULES for dt in re_.fires(rule))
    re_.close()
    se = SellEngine(sym, conn=conn)
    DT, PX = se.DT, se.PX
    ov = load_overrides(conn, sym)

    def price_at(dt):
        i = bisect.bisect_right(DT, dt)
        return PX[i - 1] if i > 0 else None

    sell_cache = {}

    def sell(dt, sp, rule):
        if dt not in sell_cache:
            sell_cache[dt] = se.sell(dt, sp, rule, hard_sell_dt=ov.get(dt))
        return sell_cache[dt]

    return {"fires": fires, "DT": DT, "PX": PX, "price_at": price_at, "sell": sell, "se": se}


def evaluate(b, fp_params):
    """Live-trouwe lus → dict {dt: (rule, pl)} van uitgevoerde trades (shadow + koop-bevestiging)."""
    DT, PX, price_at, sell = b["DT"], b["PX"], b["price_at"], b["sell"]
    open_until = None
    out = {}
    for (dt, rule) in b["fires"]:
        if open_until is not None and dt <= open_until:
            continue                                     # shadow — telt nooit mee
        sp = price_at(dt)
        if sp is None:
            continue
        fp = fp_params.get(rule)
        if fp and confirm_buy(DT, PX, dt, sp, fp["bmin"], fp["window"], fp["xrows"]) is None:
            continue                                     # afgeblazen door koop-bevestiging
        r = sell(dt, sp, rule)
        if not r:
            open_until = dt                              # live: telt als uitgevoerd zonder sell; geen pl
            continue
        open_until = r["selling_date"]
        out[dt] = (rule, r["profit_loss"])
    return out


def diff(base, cand):
    """(dt-keys verdwenen, dt-keys opgedoken) tussen twee trade-dicts."""
    verdwenen = [(dt, base[dt][0], base[dt][1]) for dt in base if dt not in cand]
    opgedoken = [(dt, cand[dt][0], cand[dt][1]) for dt in cand if dt not in base]
    return verdwenen, opgedoken


def main():
    conn = brain()
    fp_base = params_by_rule(conn)
    tunable = [r for r in (20, 22, 23) if r in fp_base]

    builds = {sym: build(sym, conn) for sym in COINS}
    base = {sym: evaluate(builds[sym], fp_base) for sym in COINS}

    print("=" * 90)
    print("ONDERZOEK — offert een strakkere koop-drempel (b_min) ooit een goede trade op?")
    print(f"Regels: R1 winnaar >{GOOD_HARD_CAP:.0f}% nooit · R2 >={MIN_BAD_PER_GOOD} slechte per goede · "
          f"R3 Σverlies-voorkomen > Σwinst-geofferd")
    print(f"Coins: {COINS}  (over coins heen geaggregeerd; b_min is rule-breed)")
    print("=" * 90)

    for rule in tunable:
        cur = fp_base[rule]["bmin"]
        print(f"\n### rule {rule} — huidige b_min {cur}")
        for val in GRID:
            if abs(val - cur) < 1e-9:                     # huidige waarde overslaan
                continue
            richting = "strakker" if val > cur else "losser "  # strakker = dichter bij 0 = strenger filter
            # over coins heen verzamelen
            geofferd, voorkomen, opg_win, opg_verl = [], [], [], []
            for sym in COINS:
                fp_cand = {r: dict(v) for r, v in fp_base.items()}
                fp_cand[rule]["bmin"] = val
                cand = evaluate(builds[sym], fp_cand)
                verdwenen, opgedoken = diff(base[sym], cand)
                for (dt, r_, pl) in verdwenen:
                    if pl is None:
                        continue
                    (geofferd if pl > 0 else voorkomen).append((sym, dt, r_, pl))
                for (dt, r_, pl) in opgedoken:
                    if pl is None:
                        continue
                    (opg_win if pl > 0 else opg_verl).append((sym, dt, r_, pl))

            n_good = len(geofferd)
            n_bad = len(voorkomen)
            sum_good = round(sum(pl for *_h, pl in geofferd), 1)
            sum_bad = round(sum(pl for *_h, pl in voorkomen), 1)         # negatief
            net_sigma = round((sum(pl for *_h, pl in opg_win) + sum(pl for *_h, pl in opg_verl))
                              - (sum_good + sum_bad), 1)
            net_verl = (len(opg_verl) - n_bad)                           # Δ#verliezers (netto)

            if n_good == 0:
                tag = "·  geen winnaar geofferd"
            else:
                max_good = max(pl for *_h, pl in geofferd)
                r1 = max_good > GOOD_HARD_CAP
                r2 = n_bad >= MIN_BAD_PER_GOOD * n_good
                r3 = abs(sum_bad) > sum_good
                if r1:
                    tag = f"❌ VERBODEN — offert winnaar +{max_good:.1f}% (>{GOOD_HARD_CAP:.0f}%)"
                elif r2 and r3:
                    tag = "✅ TOEGESTANE RUIL"
                else:
                    fout = []
                    if not r2: fout.append(f"R2 {n_bad}/{n_good}<{MIN_BAD_PER_GOOD}")
                    if not r3: fout.append(f"R3 |{sum_bad:.1f}|≤{sum_good:.1f}")
                    tag = f"⚠️ onvoldoende ruil ({', '.join(fout)})"

            print(f"  b_min {val:>4} ({richting}):  geofferd {n_good}w (Σ+{sum_good:.1f}%) · "
                  f"voorkomen {n_bad}v (Σ{sum_bad:.1f}%) · netto ΔΣ{net_sigma:+.1f}% Δverl{net_verl:+d}   {tag}")
            for (sym, dt, r_, pl) in sorted(geofferd, key=lambda x: -x[3]):
                print(f"        ↳ geofferde winnaar  coin {sym}  {dt}  r{r_}  +{pl:.1f}%")

    conn.close()


if __name__ == "__main__":
    main()
