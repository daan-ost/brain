"""
Regime-gate VALIDATIE (Epic G, Fase 2) — toetst of de wekelijkse aan/uit-gate de benchmark écht volgt
of toevallig/overgefit is. Drie disciplines uit docs/methodology/rule-discovery.md §4b:

  1. NULLIJN-vergelijking : verslaat de gate de triviale "nooit stoppen" (altijd-aan) en "altijd-uit"?
  2. TOEVAL-TOETS         : schud de week-resultaten in de tijd door elkaar, draai de gate opnieuw; is de
                           echte overeenkomst beter dan die van duizenden geschudde varianten? (p-waarde)
  3. APART-GEHOUDEN TEST  : overeenkomst op de vroege 70% vs de late 30% per munt (werkt het in de toekomst?).
  4. MUNT-ERUIT-LATEN     : drempels stapsgewijs kiezen op 3 munten, toetsen op de 4e (overdraagbaarheid).

Score = gewogen overeenkomst per WEEK (benchmark hard telt 1,0; soft 0,4). Leak-vrij speelt hier niet:
dit is een terugtoets tegen een vaste, met-de-hand gelabelde benchmark.
"""
import json
from pathlib import Path

import numpy as np
import pandas as pd

from db import brain

WINDOW = 4
STOP_FLOOR = 20.0
STOP_CONFIRM = 2
RESTART_FLOOR = 30.0
RESTART_CONFIRM = 3
SOFT_WEIGHT = 0.4
N_PERM = 3000
SEED = 42

BENCH = Path(__file__).resolve().parent.parent / "data" / "regime_benchmark.json"


def weekly_per_coin(bench):
    """Per munt een DataFrame met week-maandag, Σpl, n-trades, benchmark-state + gewicht."""
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT trading_symbol_id sid, DATE(datetime) d, SUM(profit_loss) pl, COUNT(*) n "
                  "FROM coin_fires WHERE is_executed=1 AND profit_loss IS NOT NULL GROUP BY sid, d")
        rows = c.fetchall()
    conn.close()
    daily = {}
    for r in rows:
        daily.setdefault(r["sid"], {})[pd.Timestamp(r["d"])] = (float(r["pl"]), int(r["n"]))

    out = {}
    for sid_str, coin in bench.items():
        sid = int(sid_str)
        if sid not in daily:
            continue
        af = pd.Timestamp(coin["active"][0] + "-01")
        at = (pd.Timestamp(coin["active"][1] + "-01") + pd.offsets.MonthEnd(0))
        weeks = pd.date_range(af - pd.Timedelta(days=af.weekday()), at, freq="W-MON")
        d = daily[sid]
        recs = []
        for wk in weeks:
            pl = sum(d.get(wk + pd.Timedelta(days=i), (0.0, 0))[0] for i in range(7))
            n = sum(d.get(wk + pd.Timedelta(days=i), (0.0, 0))[1] for i in range(7))
            bstate, w = _bench_at(coin["intervals"], wk)
            if bstate is None:
                continue
            recs.append((wk, pl, n, bstate, w))
        out[coin["symbol"]] = pd.DataFrame(recs, columns=["wk", "pl", "n", "bench", "w"])
    return out


def _bench_at(intervals, day):
    for iv in intervals:
        if pd.Timestamp(iv["from"]) <= day <= pd.Timestamp(iv["to"]):
            return iv["state"], (SOFT_WEIGHT if iv["confidence"] == "soft" else 1.0)
    return None, 0.0


def run_gate(pl, n, stop_floor=STOP_FLOOR, restart_floor=RESTART_FLOOR):
    """Wekelijkse gate met hysterese. 'pre' = vóór de eerste trade-week. Retour: lijst states."""
    states, state, started, below, above, hist = [], "on", False, 0, 0, []
    for p, cnt in zip(pl, n):
        if not started:
            if cnt > 0:
                started = True
            else:
                states.append("pre"); continue
        hist.append(p); hist = hist[-WINDOW:]
        roll = sum(hist)
        if state == "on":
            below = below + 1 if roll < stop_floor else 0; above = 0
            if below >= STOP_CONFIRM: state, below = "off", 0
        else:
            above = above + 1 if roll >= restart_floor else 0; below = 0
            if above >= RESTART_CONFIRM: state, above = "on", 0
        states.append(state)
    return states


def agreement(states, df, mask=None):
    """Gewogen overeenkomst gate↔benchmark over de weken (pre-weken tellen niet mee)."""
    corr = tot = 0.0
    idx = range(len(states)) if mask is None else [i for i in range(len(states)) if mask[i]]
    for i in idx:
        if states[i] == "pre":
            continue
        w = df["w"].iloc[i]
        tot += w
        if states[i] == df["bench"].iloc[i]:
            corr += w
    return (corr / tot * 100) if tot else 0.0, tot


def const_agreement(value, df):
    corr = tot = 0.0
    for i in range(len(df)):
        w = df["w"].iloc[i]; tot += w
        if value == df["bench"].iloc[i]:
            corr += w
    return (corr / tot * 100) if tot else 0.0


def main():
    bench = json.loads(BENCH.read_text())["coins"]
    coins = weekly_per_coin(bench)
    rng = np.random.default_rng(SEED)

    print("=" * 72)
    print("FASE 2 — statistische toetsing regime-gate tegen benchmark")
    print("=" * 72)

    # 1) NULLIJN + echte gate
    print("\n1) OVEREENKOMST vs NULLIJNEN (gewogen % weken; nullijn = niets weten)")
    print(f"   {'munt':<10}{'gate':>8}{'altijd-aan':>12}{'altijd-uit':>12}{'winst vs beste nullijn':>24}")
    pooled = {"gate": [0.0, 0.0], "on": [0.0, 0.0], "off": [0.0, 0.0]}
    real_states = {}
    for sym, df in coins.items():
        st = run_gate(df["pl"].tolist(), df["n"].tolist())
        real_states[sym] = st
        g, tot = agreement(st, df)
        aon = const_agreement("on", df)
        aoff = const_agreement("off", df)
        edge = g - max(aon, aoff)
        for k, v in [("gate", g), ("on", aon), ("off", aoff)]:
            pooled[k][0] += v * tot; pooled[k][1] += tot
        print(f"   {sym:<10}{g:>7.1f}%{aon:>11.1f}%{aoff:>11.1f}%{edge:>+22.1f}")
    pg = pooled["gate"][0]/pooled["gate"][1]; pon = pooled["on"][0]/pooled["on"][1]; poff = pooled["off"][0]/pooled["off"][1]
    print(f"   {'POOLED':<10}{pg:>7.1f}%{pon:>11.1f}%{poff:>11.1f}%{pg-max(pon,poff):>+22.1f}")

    # 2) TOEVAL-TOETS — schud week-resultaten, draai gate opnieuw
    print(f"\n2) TOEVAL-TOETS — {N_PERM} keer week-resultaten geschud per munt (p = kans op ≥ echte score)")
    for sym, df in coins.items():
        real, _ = agreement(real_states[sym], df)
        pl = df["pl"].to_numpy(); nn = df["n"].tolist()
        ge = 0
        for _ in range(N_PERM):
            sh = rng.permutation(pl)
            st = run_gate(sh.tolist(), nn)
            a, _ = agreement(st, df)
            if a >= real - 1e-9:
                ge += 1
        p = (ge + 1) / (N_PERM + 1)
        flag = "✓ p<0,05" if p < 0.05 else ("~ p<0,10" if p < 0.10 else "✗ niet sign.")
        print(f"   {sym:<10} echte overeenkomst {real:5.1f}%   p={p:.3f}   {flag}")

    # 3) APART-GEHOUDEN TESTPERIODE — vroege 70% vs late 30%
    print("\n3) APART-GEHOUDEN TESTPERIODE — overeenkomst vroege 70% vs late 30% (werkt het in de toekomst?)")
    for sym, df in coins.items():
        st = real_states[sym]
        cut = int(len(df) * 0.7)
        early = [i < cut for i in range(len(df))]
        late = [i >= cut for i in range(len(df))]
        ae, te = agreement(st, df, early)
        al, tl = agreement(st, df, late)
        print(f"   {sym:<10} vroeg {ae:5.1f}%   laat {al:5.1f}%   {'(stabiel)' if abs(ae-al)<12 else '(let op: verschuift)'}")

    # 4) MUNT-ERUIT-LATEN — drempels op 3 munten, toetsen op de 4e
    print("\n4) MUNT-ERUIT-LATEN — drempels gekozen op 3 munten, getoetst op de 4e (overdraagbaar?)")
    grid = [(sf, rf) for sf in (10, 15, 20, 25) for rf in (25, 30, 40) if rf > sf]
    for held in coins:
        best, best_score = None, -1
        for sf, rf in grid:
            tot_c = tot_w = 0.0
            for sym, df in coins.items():
                if sym == held:
                    continue
                st = run_gate(df["pl"].tolist(), df["n"].tolist(), sf, rf)
                a, t = agreement(st, df)
                tot_c += a * t; tot_w += t
            sc = tot_c / tot_w
            if sc > best_score:
                best_score, best = sc, (sf, rf)
        dfh = coins[held]
        oos, _ = agreement(run_gate(dfh["pl"].tolist(), dfh["n"].tolist(), *best), dfh)
        ins, _ = agreement(real_states[held], dfh)  # met de standaard 20/30
        print(f"   {held:<10} beste drempels op de andere 3 = {best}  →  op {held}: {oos:.1f}%  (default 20/30: {ins:.1f}%)")

    print("\n" + "=" * 72)


if __name__ == "__main__":
    main()
