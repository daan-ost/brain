#!/usr/bin/env python3
"""
apply.py — de coin-agnostische rule vastleggen in brain.rules (Epic RD, propose-only).

Schrijft ÉÉN rule_number (30) met GEDEELDE banden — dezelfde subregels voor ALLE munten (DOGEAI, NOS én
toekomstige), precies zoals 20-23. **INACTIEF** (active=0): vuurt niets, raakt de live engine niet, tot
een expliciete activatie. Met `rules_history`-audit en bron-tag `discovery-RD-pooled` (idempotent +
terug te draaien).

Vóór schrijven:
  - GETROUWHEID per munt: vuurt de rule via het live-pad (`subrule_value`/`window_metrics`) identiek aan
    de discovery-engine (`lean_metrics`)? 100% = reproduceert exact wat is gemeten.
  - REFIRE-CIJFERS per munt (net-winst-basis): wat voegt de rule toe bovenop 20-23.

Standaard DRY-RUN. Met `--write` schrijft hij de inactieve rijen + audit.

Draaien (vanuit engine/src):
    python -m discovery.apply              # dry-run
    python -m discovery.apply --write      # schrijf rule 30 (inactief) + rules_history
"""
import argparse
import bisect
import json
import os
from datetime import datetime

import numpy as np

from db import brain
from parent_crossgroup import AsOf
from parent_spoor1 import lshape
from calc import subrule_value
from discovery.data import min_volume
import rules_history

DISCOVERY_RULE = 30                       # ÉÉN rule_number, gedeelde banden voor alle munten
SOURCE = "discovery-RD-pooled"
RULE_PATH = os.path.join(os.path.dirname(__file__), ".cache", "pooled_rule.json")


def _set_rule(rule_number, path):
    """Parametriseer het doel-rule-nummer + bron-json (rule 30, 31, … volgen dezelfde vorm)."""
    global DISCOVERY_RULE, RULE_PATH
    DISCOVERY_RULE = rule_number
    RULE_PATH = path or os.path.join(os.path.dirname(__file__), ".cache", f"pooled_rule_{rule_number}.json")


def parse_col(col):
    ind, lb, metric = col.split("|")
    return ind, int(lb[1:]), metric


def subrule_rows(subrules):
    rows = []
    for i, (col, side, lo, hi) in enumerate(subrules):
        ind, lb, metric = parse_col(col)
        rows.append(dict(sort=i + 1, indicator=ind, subrulename=metric, def1_value=lb,
                         b_min=(lo if side in ("ge", "band") else None),
                         b_max=(hi if side in ("le", "band") else None), value_condition="{}"))
    return rows


def _passes(v, lo, hi):
    if v is None:
        return None
    if lo is not None and v < float(lo):
        return False
    if hi is not None and v > float(hi):
        return False
    return True


def _verdict(A, subrules, t, lean, vol_base=1.0):
    for (col, side, lo, hi) in subrules:
        ind, lb, metric = parse_col(col)
        base_ind = "volumeud" if ind == "relvol" else ind   # relvol = volumeud-metric / per-munt basislijn
        if lean:
            v = lshape(A, base_ind, lb, metric, t)
        else:
            s = A.series[base_ind]
            k = bisect.bisect_right(s["dt"], t)
            w = s["v"][max(0, k - lb):k][::-1]
            v = subrule_value(metric, {}, w, [])
        if ind == "relvol" and v is not None:
            v = v / vol_base
        if _passes(v, lo, hi) is False:
            return False
    return True


def fidelity(symbol, subrules, n=4000, seed=1):
    A = AsOf(symbol)
    vb = min_volume(symbol)
    rng = np.random.default_rng(seed)
    idxs = rng.choice(len(A.vdt), size=min(n, len(A.vdt)), replace=False)
    agree = fl = fw = 0
    for i in idxs:
        t = A.vdt[i]
        vl = _verdict(A, subrules, t, True, vb)
        vw = _verdict(A, subrules, t, False, vb)
        agree += (vl == vw)
        fl += vl
        fw += vw
    return dict(n=len(idxs), pct=100 * agree / len(idxs), fire_lean=fl, fire_win=fw)


def report_and_write(write=False):
    if not os.path.exists(RULE_PATH):
        print(f"GEEN rule gevonden ({RULE_PATH}). Draai eerst: python -m discovery.pooled")
        return
    data = json.load(open(RULE_PATH))
    subrules = [(c, s, lo, hi) for (c, s, lo, hi) in data["subrules"]]
    rows = subrule_rows(subrules)
    print(f"\n{'=' * 78}\n  APPLY — coin-agnostische rule vastleggen (rule {DISCOVERY_RULE}, propose-only, INACTIEF)\n{'=' * 78}")
    print(f"  ÉÉN gedeelde rule, {len(rows)} subregels (zelfde banden voor ALLE munten):")
    print(f"  {len(data['structure'])} indikkende + {len(data['added'])} verlies-reductie-subregels")

    for nm, blob in data["coins"].items():
        inc = blob["incr"]
        print(f"\n  --- {nm} (sym {blob['symbol']}) ---")
        print(f"      {blob['compact']}")
        print(f"      {blob['n_trades']} trades, {100*blob['selectivity']:.3f}% ticks, gem {blob['mean']:+.3f}%/trade, "
              f"CPCV buiten-data {blob['cpcv_oos']:+.3f}%, p={blob['perm_p']:.3f}")
        print(f"      refire op 20-23: base {inc['base_n']} trades (Σ{inc['base_sigma']:+.0f}%) → "
              f"+{inc['added']} nieuwe (goed {inc['added_good']}/slecht {inc['added_bad']}) | ΔΣ {inc['d_sigma']:+.0f}%")
        fid = fidelity(blob["symbol"], subrules)
        print(f"      getrouwheid (lean vs live-pad, {fid['n']} ticks): {fid['pct']:.2f}% eens "
              f"(vuurt lean {fid['fire_lean']} / live {fid['fire_win']})")

    if write:
        conn = brain()
        with conn.cursor() as c:
            c.execute("DELETE FROM rules WHERE rule_number=%s AND source=%s", (DISCOVERY_RULE, SOURCE))
            now = datetime.now()
            for r in rows:
                c.execute(
                    "INSERT INTO rules (rule_number, sort, indicator, subrulename, def1_value, "
                    "b_min, b_max, value_condition, active, source, created_at, updated_at) "
                    "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,0,%s,%s,%s)",
                    (DISCOVERY_RULE, r["sort"], r["indicator"], r["subrulename"], r["def1_value"],
                     r["b_min"], r["b_max"], r["value_condition"], SOURCE, now, now))
        conn.commit()
        conn.close()
        rules_history.record(
            {DISCOVERY_RULE: f"discovery-RD coin-agnostische rule (gedeelde banden, schaal-invariant); "
                             f"net-winst-basis; INACTIEF (active=0)."}, source=SOURCE)
        print(f"\n  >>> Vastgelegd: rule {DISCOVERY_RULE} ({len(rows)} subregels, active=0, gedeelde banden) "
              f"+ audit. Niets vuurt tot activatie.")
        print("      Activeren vereist een aparte stap: RuleEngine rule 30 laten laden + ongepoort laten")
        print("      vuren (buiten brain_volume_found om) + coin_strategies (sell) per munt + active=1.")
    else:
        print("\n  >>> DRY-RUN — niets geschreven. Draai met --write om rule 30 (inactief) vast te leggen.")


def _price_at(A, t):
    i = bisect.bisect_right(A.vdt, t)
    return A.vpx[i - 1] if i > 0 else None


def _occupied(conn, sym):
    """Bestaande 20-23 executed-trades als bezette vensters [buy, sell] (de bot heeft voorrang)."""
    with conn.cursor() as c:
        c.execute("SELECT datetime, selling_datetime FROM coin_fires WHERE trading_symbol_id=%s "
                  "AND rule IN (20,21,22,23) AND is_executed=1 AND selling_datetime IS NOT NULL "
                  "ORDER BY datetime", (sym,))
        return [(r["datetime"], r["selling_datetime"]) for r in c.fetchall()]


def activate(write=False):
    """Activeer rule 30 ONAFHANKELIJK van de volume-poort: rule 30 vult de gaten waar 20-23 niet in een
    positie zitten (20-23 onaangeroerd). Geen volledige refire. Idempotent op rule=30. Terug te draaien."""
    if not os.path.exists(RULE_PATH):
        print(f"GEEN rule gevonden ({RULE_PATH}). Draai eerst: python -m discovery.pooled")
        return
    data = json.load(open(RULE_PATH))
    subrules = [(c, s, lo, hi) for (c, s, lo, hi) in data["subrules"]]
    print(f"\n{'=' * 78}\n  ACTIVEER rule {DISCOVERY_RULE} — ongepoort, vult de idle-gaten van 20-23\n{'=' * 78}")

    from parent_crossgroup import AsOf
    from sell_engine import SellEngine
    from promising import PromisingEngine
    from db import brain as _brain
    from discovery.data import min_volume

    conn = _brain()
    for nm, blob in data["coins"].items():
        sym = blob["symbol"]
        A = AsOf(sym)
        vb = min_volume(sym)                 # relvol-basislijn (zelfde als de discovery-engine)
        eng = SellEngine(sym)
        prom = PromisingEngine(sym, "asc")
        occ = _occupied(conn, sym)
        occ_buys = [b for b, _s in occ]

        def in_occupied(t):
            import bisect as _b
            j = _b.bisect_right(occ_buys, t) - 1
            return j >= 0 and occ[j][1] is not None and t <= occ[j][1]

        def next_occ_buy(t):
            import bisect as _b
            j = _b.bisect_right(occ_buys, t)
            return occ_buys[j] if j < len(occ_buys) else None

        # rule 30 fires = ongepoorte verdict over alle ticks (eigen evaluatie, geen vf nodig)
        fires = [t for t in A.vdt if _verdict(A, subrules, t, True, vb)]
        rows, open_until, n_exec, n_shadow, n_good = [], None, 0, 0, 0
        for t in fires:
            buy = _price_at(A, t)
            if buy is None or buy <= 0:
                continue
            shadow = in_occupied(t) or (open_until is not None and t <= open_until)
            if shadow:
                n_shadow += 1
                rows.append((t, 0, buy, None, None, None, 0, None))
                continue
            cap = next_occ_buy(t)                       # rule 30 exit uiterlijk bij de volgende 20-23-koop
            sell = eng.sell(t, buy, 20, hard_sell_dt=cap)   # rule 30 sell-gedrag = rule 20 (kopie bij write)
            open_until = sell["selling_date"] if sell else t
            pr = prom.promising(t)
            good = 1 if (pr and pr.get("verdict") == "buy") else 0
            n_good += good
            n_exec += 1
            if sell:
                bu = bisect.bisect_left(A.vdt, t)
                hi = bisect.bisect_right(A.vdt, sell["selling_date"])
                seg = [p for p in A.vpx[bu:hi] if p is not None]
                best_up = round((max(seg) - buy) / buy * 100, 3) if seg else None
                rows.append((t, 1, buy, sell["selling_price"], sell["selling_date"], sell["profit_loss"], good, best_up))
            else:
                rows.append((t, 1, buy, None, None, None, good, None))
        pls = [r[5] for r in rows if r[1] == 1 and r[5] is not None]
        sig = sum(pls)
        slecht = sum(1 for p in pls if p < 0)
        print(f"\n  --- {nm} (sym {sym}) ---")
        print(f"      rule 30: {n_exec} trades in de idle-gaten ({n_shadow} shadows tijdens 20-23/30-posities)")
        print(f"      Σ {sig:+.0f}% | slecht {slecht}/{len(pls)} ({100*slecht//max(len(pls),1)}%) | "
              f"in promising {n_good}")
        if write:
            with conn.cursor() as c:
                c.execute("DELETE FROM coin_fires WHERE trading_symbol_id=%s AND rule=%s", (sym, DISCOVERY_RULE))
                for (t, ex, buy, sp, sd, pl, good, bu) in rows:
                    c.execute(
                        "INSERT INTO coin_fires (trading_symbol_id, symbol, datetime, rule, in_good_period, "
                        "is_executed, buy_price, selling_price, selling_datetime, profit_loss, best_upside, "
                        "created_at, updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,NOW(),NOW())",
                        (sym, nm, t, DISCOVERY_RULE, good, ex, buy, sp, sd, pl, bu))
            conn.commit()
            print(f"      [geschreven] rule 30 coin_fires voor {nm} (20-23 onaangeroerd)")
    if write:
        # sell-instellingen rule 30 = kopie van rule 20 (globaal + per munt) + rule actief
        with conn.cursor() as c:
            c.execute("DELETE FROM strategies WHERE rule_number=%s", (DISCOVERY_RULE,))
            c.execute("INSERT INTO strategies (rule_number, sl_settings, created_at, updated_at) "
                      "SELECT %s, sl_settings, NOW(), NOW() FROM strategies WHERE rule_number=20", (DISCOVERY_RULE,))
            c.execute("DELETE FROM coin_strategies WHERE rule_number=%s", (DISCOVERY_RULE,))
            c.execute("INSERT INTO coin_strategies (trading_symbol_id, rule_number, sl_settings, created_at, updated_at) "
                      "SELECT trading_symbol_id, %s, sl_settings, NOW(), NOW() FROM coin_strategies WHERE rule_number=20",
                      (DISCOVERY_RULE,))
            c.execute("UPDATE rules SET active=1 WHERE rule_number=%s AND source=%s", (DISCOVERY_RULE, SOURCE))
        conn.commit()
        print(f"\n  >>> Rule {DISCOVERY_RULE} GEACTIVEERD (active=1), sell-instellingen = kopie rule 20, "
              f"trades in de idle-gaten geschreven. 20-23 ONAANGEROERD. Terug: DELETE coin_fires WHERE rule=30 + active=0.")
    else:
        print("\n  >>> DRY-RUN — niets geschreven. Draai met --activate --write om rule 30 te activeren.")
    conn.close()


def main():
    ap = argparse.ArgumentParser(description="Vastleggen/activeren van de coin-agnostische rule")
    ap.add_argument("--write", action="store_true", help="schrijf naar brain (anders dry-run)")
    ap.add_argument("--activate", action="store_true",
                    help="activeer de rule (vult idle-gaten van 20-23, ongepoort); --write om te committen")
    ap.add_argument("--rule", type=int, default=30, help="rule-nummer (30, 31, …; default 30)")
    ap.add_argument("--path", default=None, help="bron-json (default .cache/pooled_rule_<rule>.json; rule 30 = pooled_rule.json)")
    args = ap.parse_args()
    _set_rule(args.rule, args.path if args.path else (RULE_PATH if args.rule == 30 else None))
    if args.activate:
        activate(write=args.write)
    else:
        report_and_write(write=args.write)


if __name__ == "__main__":
    main()
