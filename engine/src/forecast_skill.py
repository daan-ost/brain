#!/usr/bin/env python3
"""
Forecast-skill proef: kan een statistisch model (AutoARIMA) op de DAG-prijsreeks van een coin
nog iets toevoegen boven een domme baseline (Naive = "vandaag = gisteren"). Als ja → er zit een
patroon, de markt is voorspelbaar. Als nee → pure ruis, de coin is dood.

Per dag:
  - prijzen (volumeud-ticks met price) uit brain.indicators
  - resample naar 1-min mediaan, vul gaten met forward-fill
  - split 80/20: AutoARIMA op trein, voorspel test
  - MAPE (mean absolute percentage error) voor AutoARIMA en Naive
  - skill = 1 - MAPE_arima/MAPE_naive   (>0 = ARIMA wint, ≤0 = ruis)
  - realised volatility = std van log-returns die dag (los signaal)

Output: engine/out/forecast_skill_<sym>.csv en een per-maand-samenvatting op stdout.
Read-only — niets in brain wordt aangepast.

Run: python -m src.forecast_skill 2525 2025-03-01 2025-07-31
"""
import sys
import warnings
from datetime import date, timedelta

import numpy as np
import pandas as pd
from statsforecast import StatsForecast
from statsforecast.models import AutoARIMA, Naive

from db import brain

warnings.filterwarnings("ignore")


def day_prices(conn, sym, d):
    """1-min prijsreeks voor één dag — mediaan binnen de minuut, forward-fill gaten."""
    with conn.cursor() as c:
        c.execute("SELECT datetime, price FROM indicators "
                  "WHERE trading_symbol_id=%s AND indicator='volumeud' AND price IS NOT NULL "
                  "  AND datetime >= %s AND datetime < %s ORDER BY datetime",
                  (sym, d, d + timedelta(days=1)))
        rows = c.fetchall()
    if len(rows) < 60:
        return None
    df = pd.DataFrame(rows)
    df["price"] = df["price"].astype(float)
    df = df.set_index("datetime").resample("1min")["price"].median().ffill().dropna()
    return df if len(df) >= 120 else None


def day_skill(series):
    """Return (mape_arima, mape_naive, skill, n_test, real_vol_pct)."""
    n = len(series)
    cut = int(n * 0.8)
    train, test = series.iloc[:cut], series.iloc[cut:]
    if len(test) < 10:
        return None
    df = pd.DataFrame({"unique_id": ["doge"] * len(train), "ds": train.index, "y": train.values})
    sf = StatsForecast(models=[AutoARIMA(season_length=1), Naive()], freq="1min", n_jobs=1)
    sf.fit(df)
    fc = sf.predict(h=len(test))
    pred_arima = fc["AutoARIMA"].values
    pred_naive = fc["Naive"].values
    actual = test.values
    eps = 1e-12
    mape_a = float(np.mean(np.abs((actual - pred_arima) / (actual + eps))) * 100)
    mape_n = float(np.mean(np.abs((actual - pred_naive) / (actual + eps))) * 100)
    skill = 1 - (mape_a / mape_n) if mape_n > 0 else 0.0
    logret = np.diff(np.log(series.values + eps))
    real_vol = float(np.std(logret) * 100)
    return mape_a, mape_n, skill, len(test), real_vol


def run(sym, frm, to):
    conn = brain()
    rows = []
    d = frm
    while d <= to:
        s = day_prices(conn, sym, d)
        if s is not None:
            r = day_skill(s)
            if r is not None:
                mape_a, mape_n, skill, n_test, vol = r
                rows.append({"date": d, "n": len(s), "n_test": n_test,
                             "mape_arima": round(mape_a, 4), "mape_naive": round(mape_n, 4),
                             "skill": round(skill, 4), "vol_pct": round(vol, 4)})
                print(f"  {d}  mape_arima={mape_a:6.3f}  mape_naive={mape_n:6.3f}  "
                      f"skill={skill:+.3f}  vol={vol:.3f}%")
        d += timedelta(days=1)
    conn.close()

    if not rows:
        print("Geen dagen — check data range.")
        return
    df = pd.DataFrame(rows)
    out = f"out/forecast_skill_{sym}.csv"
    df.to_csv(out, index=False)
    print(f"\n→ {out}  ({len(df)} dagen)\n")

    df["ym"] = pd.to_datetime(df["date"]).dt.strftime("%Y-%m")
    agg = df.groupby("ym").agg(
        days=("date", "count"),
        med_skill=("skill", "median"),
        pct_win=("skill", lambda s: (s > 0).mean() * 100),
        med_mape_arima=("mape_arima", "median"),
        med_mape_naive=("mape_naive", "median"),
        med_vol_pct=("vol_pct", "median"),
    ).reset_index()
    print("Per maand (mediaan):")
    print("  maand    dagen  skill   %win   mape_arima  mape_naive  vol%")
    for r in agg.itertuples():
        print(f"  {r.ym}   {r.days:3d}    {r.med_skill:+.3f}  {r.pct_win:4.0f}%  "
              f"   {r.med_mape_arima:6.3f}      {r.med_mape_naive:6.3f}   {r.med_vol_pct:.3f}")


if __name__ == "__main__":
    sym = int(sys.argv[1]) if len(sys.argv) > 1 else 2525
    frm = date.fromisoformat(sys.argv[2]) if len(sys.argv) > 2 else date(2025, 3, 1)
    to = date.fromisoformat(sys.argv[3]) if len(sys.argv) > 3 else date(2025, 7, 14)
    print(f"=== forecast-skill — symbol {sym}  {frm} .. {to} ===\n")
    run(sym, frm, to)
