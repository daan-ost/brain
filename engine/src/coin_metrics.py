"""
Per (coin, dag) de kansrijkheid-maten berekenen en opslaan in coin_daily_metrics (brain).

De KANSRIJK-score is de UPSIDE-KANS: het % van de momenten op een dag waarop de prijs binnen de
komende 60 min nog >=3% stijgt. Gekozen omdat dit cross-coin het sterkst met winst-per-trade
correleert (DOGEAI 0,94 / NOS 0,50) — sterker dan std-log-returns en veel sterker dan volume.
Volume zegt iets over het AANTAL trades, niet over of ze winst maken; daarom is n_ticks hier alleen
een liquiditeit-veld (filter), geen sorteersleutel.

  up_pct  = % volumeud-ticks die dag met forward-60min upside >= UP_THRESHOLD%   (de kansrijk-maat)
  vol_pct = std(1-min log-returns) * 100                                          (beweeglijkheid, prijs)
  n_ticks = aantal volumeud-ticks die dag                                         (liquiditeit/activiteit)
  up_7d / vol_7d = 7-daags voortschrijdend gemiddelde; up_7d is de sorteersleutel.

Read uit indicators (volumeud: datetime, value, price). Schrijft naar coin_daily_metrics.
Idempotent: bestaande dagen worden niet overschreven tenzij force=True. Nooit naar bot_signals.
Leak-vrij: de forward-60min upside van elke tick ligt in het verleden op het moment dat je sorteert;
voor een LIVE ranking gebruik je dus alleen dagen waarvan het 60-min venster al verstreken is.
"""
import sys
from datetime import timedelta

import numpy as np
import pandas as pd

from db import brain

UP_THRESHOLD = 3.0        # % stijging binnen het venster die als "kans" telt (= goede-trade drempel)
FWD_MIN = 60              # forward-venster in minuten (de 1-uur hold-horizon)
MIN_TICKS_PER_DAY = 120   # minder ticks op een dag -> geen meting (te dun)
WIN_7D = 7
MIN_PERIODS_7D = 3
GLITCH = 3.0             # lokaal prijs-glitch filter: tick weg als prijs > GLITCH x of < 1/GLITCH x rolling mediaan


def _forward_upside(dt, price, fwd_min=FWD_MIN):
    """Per tick: max % stijging van de prijs binnen de komende fwd_min minuten (two-pointer)."""
    t = np.asarray(dt, dtype="datetime64[s]")
    p = np.asarray(price, float)
    n = len(p)
    win = np.timedelta64(fwd_min * 60, "s")
    up = np.zeros(n)
    end = 0
    for i in range(n):
        if end < i + 1:
            end = i + 1
        limit = t[i] + win
        while end < n and t[end] <= limit:
            end += 1
        if end > i + 1 and p[i] > 0:
            up[i] = (p[i + 1:end].max() - p[i]) / p[i] * 100.0
    return up


def _load_volumeud(conn, sym):
    with conn.cursor() as c:
        c.execute("SELECT datetime, value, price FROM indicators "
                  "WHERE trading_symbol_id=%s AND indicator='volumeud' AND price IS NOT NULL "
                  "ORDER BY datetime", (sym,))
        rows = c.fetchall()
    if not rows:
        return None
    df = pd.DataFrame(rows)
    df["price"] = df["price"].astype(float)
    # lokaal glitch-filter (TradingView levert af en toe een kapotte prijs); rolling mediaan, trend blijft
    med = df["price"].rolling(101, center=True, min_periods=11).median()
    ratio = df["price"] / med
    df = df[~((ratio > GLITCH) | (ratio < 1 / GLITCH)).fillna(False)].reset_index(drop=True)
    return df


def _build_for_coin(conn, sym):
    df = _load_volumeud(conn, sym)
    if df is None or df.empty:
        return []
    df["up60"] = _forward_upside(df["datetime"].values, df["price"].values)
    df["date"] = pd.to_datetime(df["datetime"]).dt.date

    # vol_pct per dag: std van 1-min log-returns (resample 1-min mediaan, ffill <=5 min)
    pser = df.set_index("datetime")["price"].resample("1min").median().ffill(limit=5).dropna()
    pser_day = pser.groupby(pser.index.date)
    vol_by_day = {}
    for d, g in pser_day:
        if len(g) >= MIN_TICKS_PER_DAY:
            vol_by_day[d] = float(np.std(np.diff(np.log(g.values + 1e-12))) * 100)

    recs = []
    for d, g in df.groupby("date"):
        if len(g) < MIN_TICKS_PER_DAY:
            continue
        recs.append({"date": d,
                     "up_pct": float((g["up60"] >= UP_THRESHOLD).mean() * 100),
                     "vol_pct": vol_by_day.get(d),
                     "n_ticks": int(len(g))})
    if not recs:
        return []
    out = pd.DataFrame(recs).sort_values("date").reset_index(drop=True)
    out["up_7d"] = out["up_pct"].rolling(WIN_7D, min_periods=MIN_PERIODS_7D).mean()
    out["vol_7d"] = out["vol_pct"].rolling(WIN_7D, min_periods=MIN_PERIODS_7D).mean()
    return out.to_dict(orient="records")


def _existing_dates(conn, sym):
    with conn.cursor() as c:
        c.execute("SELECT date FROM coin_daily_metrics WHERE trading_symbol_id=%s", (sym,))
        return {r["date"] for r in c.fetchall()}


def _nan(x):
    return None if x is None or (isinstance(x, float) and np.isnan(x)) else x


def _write(conn, sym, rows, force=False):
    if not rows:
        return 0
    skip = set() if force else _existing_dates(conn, sym)
    new_rows = [r for r in rows if r["date"] not in skip]
    if not new_rows:
        return 0
    with conn.cursor() as c:
        for r in new_rows:
            c.execute(
                "INSERT INTO coin_daily_metrics "
                "(trading_symbol_id, date, up_pct, vol_pct, n_ticks, up_7d, vol_7d, created_at, updated_at) "
                "VALUES (%s,%s,%s,%s,%s,%s,%s,NOW(),NOW()) "
                "ON DUPLICATE KEY UPDATE up_pct=VALUES(up_pct), vol_pct=VALUES(vol_pct), "
                "  n_ticks=VALUES(n_ticks), up_7d=VALUES(up_7d), vol_7d=VALUES(vol_7d), updated_at=NOW()",
                (sym, r["date"], _nan(r["up_pct"]), _nan(r["vol_pct"]), r["n_ticks"],
                 _nan(r.get("up_7d")), _nan(r.get("vol_7d"))))
    conn.commit()
    return len(new_rows)


def run(force=False, verbose=True):
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT DISTINCT trading_symbol_id sym FROM indicators "
                  "WHERE indicator='volumeud' AND price IS NOT NULL ORDER BY sym")
        syms = [r["sym"] for r in c.fetchall()]
    total = 0
    ranking = []
    for sym in syms:
        rows = _build_for_coin(conn, sym)
        total += _write(conn, sym, rows, force=force)
        if rows:
            last = rows[-1]
            ranking.append({"sym": sym, "date": str(last["date"]),
                            "up_7d": round(_nan(last.get("up_7d")) or 0, 2),
                            "vol_7d": round(_nan(last.get("vol_7d")) or 0, 3),
                            "n_ticks": last["n_ticks"]})
        if verbose:
            print(f"coin {sym}: {len(rows)} dagen, laatste up_7d="
                  f"{rows[-1].get('up_7d') if rows else None}")
    conn.close()
    ranking.sort(key=lambda r: r["up_7d"], reverse=True)   # meest kansrijk eerst
    return {"days_added": total, "coins": len(syms), "ranking": ranking}


if __name__ == "__main__":
    res = run(force="--force" in sys.argv, verbose=True)
    print(f"\nKansrijkheid-ranking (laatste up_7d per coin, meest kansrijk eerst):")
    for i, r in enumerate(res["ranking"], 1):
        print(f"  {i}. coin {r['sym']:5}  up_7d={r['up_7d']:5.1f}%  vol_7d={r['vol_7d']:.3f}  "
              f"n_ticks={r['n_ticks']}  ({r['date']})")
