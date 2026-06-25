"""
Regime-gate backtest (Epic G, Fase 2): test de aan/uit-gate op verschillende cadansen (dagelijks,
3-daags, wekelijks) en scoort elke tegen de benchmark (engine/data/regime_benchmark.json).

LEAK-VRIJ: op elk beslismoment T gebruikt de gate alleen het trade-resultaat van de laatste WINDOW
dagen (t/m T). Een trade is binnen ~1u verkocht, dus aan het eind van dag T is zijn resultaat bekend.
De benchmark is alleen het SCORE-doel (niet een input van de gate).

Signaal = rollend Σ profit_loss over WINDOW dagen. Hysterese: UIT na STOP_DAYS dagen-equivalent onder
STOP_FLOOR; AAN pas na RESTART_DAYS onder/boven RESTART_FLOOR. De confirm-vensters staan in DAGEN
(gelijk over cadansen), zodat de vergelijking puur de cadans isoleert, niet de demping-lengte.

Scoring volgt Daans filosofie: te laat stoppen (doorsudderen in de afloop) weegt zwaarder dan een
gemiste late opleving. soft-benchmark-perioden tellen lichter.
"""
import json
import sys
from datetime import timedelta
from pathlib import Path

import pandas as pd

from db import brain

WINDOW = 28          # rollend venster in dagen (~1 maand)
STOP_FLOOR = 20.0    # onder deze rollende % -> kandidaat-uit
RESTART_FLOOR = 30.0 # boven deze rollende % -> kandidaat-aan (hoger = demping)
STOP_DAYS = 14       # zwakte-dagen aaneen vóór stop
RESTART_DAYS = 21    # sterke dagen aaneen vóór herstart

LATE_PENALTY = 2.0   # doorsudderen (benchmark UIT, gate AAN) telt 2x
MISS_PENALTY = 1.0   # gemiste opleving (benchmark AAN, gate UIT) telt 1x
FLIP_PENALTY = 0.5   # per schakeling (tegen geflikker)
SOFT_WEIGHT = 0.4    # soft-benchmark weegt lichter dan hard (1.0)

BENCH = Path(__file__).resolve().parent.parent / "data" / "regime_benchmark.json"


def load_daily_pl():
    """Per munt: pandas Series van Σ profit_loss per kalenderdag (alleen executed trades)."""
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT trading_symbol_id sid, DATE(datetime) d, SUM(profit_loss) pl "
                  "FROM coin_fires WHERE is_executed=1 AND profit_loss IS NOT NULL "
                  "GROUP BY sid, d ORDER BY sid, d")
        rows = c.fetchall()
    conn.close()
    out = {}
    for r in rows:
        out.setdefault(r["sid"], {})[pd.Timestamp(r["d"])] = float(r["pl"])
    series = {}
    for sid, m in out.items():
        s = pd.Series(m).sort_index()
        full = pd.date_range(s.index.min(), s.index.max(), freq="D")
        series[sid] = s.reindex(full, fill_value=0.0)
    return series


def simulate(daily, cadence):
    """Run de gate op deze cadans. Retour: daily Series van 'on'/'off' over de actieve range."""
    stop_ticks = max(1, round(STOP_DAYS / cadence))
    restart_ticks = max(1, round(RESTART_DAYS / cadence))
    days = daily.index
    ticks = days[::cadence]
    state, below, above = "on", 0, 0
    tick_state = {}
    for t in ticks:
        lo = t - timedelta(days=WINDOW - 1)
        roll = daily.loc[lo:t].sum()
        if state == "on":
            below = below + 1 if roll < STOP_FLOOR else 0
            above = 0
            if below >= stop_ticks:
                state, below = "off", 0
        else:
            above = above + 1 if roll >= RESTART_FLOOR else 0
            below = 0
            if above >= restart_ticks:
                state, above = "on", 0
        tick_state[t] = state
    # expand naar dagelijks: elke dag neemt de stand van de laatste tick <= die dag
    ts = pd.Series(tick_state).sort_index().reindex(days, method="ffill")
    return ts


def bench_state_for(intervals, day):
    for iv in intervals:
        if pd.Timestamp(iv["from"]) <= day <= pd.Timestamp(iv["to"]):
            return iv["state"], (SOFT_WEIGHT if iv["confidence"] == "soft" else 1.0)
    return None, 0.0


def score(daily_series, bench, cadence):
    corr = late = miss = total = flips = 0.0
    per_coin = {}
    for sid, daily in daily_series.items():
        coin = bench.get(str(sid))
        if not coin:
            continue
        gate = simulate(daily, cadence)
        c_corr = c_late = c_miss = 0.0
        for day, g in gate.items():
            bstate, w = bench_state_for(coin["intervals"], day)
            if bstate is None:
                continue
            total += w
            if g == bstate:
                corr += w; c_corr += w
            elif bstate == "off" and g == "on":
                late += w; c_late += w
            else:
                miss += w; c_miss += w
        f = int((gate != gate.shift()).sum()) - 1  # aantal omslagen
        f = max(0, f)
        flips += f
        per_coin[coin["symbol"]] = dict(corr=c_corr, late=c_late, miss=c_miss, flips=f)
    agree = corr / total * 100 if total else 0
    penalty = LATE_PENALTY * late + MISS_PENALTY * miss + FLIP_PENALTY * flips
    return dict(agree=agree, late=late, miss=miss, flips=int(flips), penalty=penalty, per_coin=per_coin)


def main():
    daily_series = load_daily_pl()
    bench = json.loads(BENCH.read_text())["coins"]
    print(f"Regime-gate cadans-vergelijking — window={WINDOW}d, stop<{STOP_FLOOR} ({STOP_DAYS}d), "
          f"restart>={RESTART_FLOOR} ({RESTART_DAYS}d)\n")
    print(f"{'cadans':<12}{'overeenkomst':>13}{'te-laat-door':>14}{'gemist':>9}{'schakels':>10}{'strafscore':>12}")
    print("-" * 70)
    results = {}
    for cad, name in [(1, "dagelijks"), (3, "3-daags"), (7, "wekelijks")]:
        r = score(daily_series, bench, cad)
        results[name] = r
        print(f"{name:<12}{r['agree']:>11.1f}%{r['late']:>14.1f}{r['miss']:>9.1f}"
              f"{r['flips']:>10}{r['penalty']:>12.1f}")
    print("\nPer munt (te-laat-door dagen = doorsudderen in de afloop, gewogen):")
    for cad in ["dagelijks", "3-daags", "wekelijks"]:
        print(f"\n  {cad}:")
        for sym, pc in results[cad]["per_coin"].items():
            print(f"    {sym:<9} laat={pc['late']:5.1f}  gemist={pc['miss']:5.1f}  schakels={pc['flips']}")
    best = min(results.items(), key=lambda kv: kv[1]["penalty"])
    print(f"\nLaagste strafscore (= beste benchmark-match): {best[0]}")


if __name__ == "__main__":
    main()
