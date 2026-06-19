"""
READ-ONLY — dagelijkse reeks voor de 'eerder zien?'-vraag.

Bouwt per dag de beweeglijkheid (frac ticks die binnen 60 min >=2% stijgen) als 7-daags
voortschrijdend gemiddelde, en zet die naast de winst van de trades in datzelfde venster.
Doel: loopt het ruw-data signaal VOOR de winst-omslag, of synchroon? En hoe stabiel is
het signaal vs de ruizige winst-telling?
"""
import sys
import pymysql
import numpy as np
import pandas as pd
from coin_activity import load, forward_upside, MIN_VOL


def daily(sym):
    df = load(sym)
    df["relvol"] = df["value"] / MIN_VOL[sym]
    df["up60"] = forward_upside(df)
    df["day"] = pd.to_datetime(df["dt"].dt.date)
    g = df.groupby("day")
    d = pd.DataFrame({
        "n_ticks": g.size(),
        "vf1": g["vf"].sum(),
        "up2_pct": g["up60"].apply(lambda s: (s >= 2).mean() * 100),
        "up3_pct": g["up60"].apply(lambda s: (s >= 3).mean() * 100),
        "range_pct": (g["price"].max() - g["price"].min()) / g["price"].min() * 100,
    })
    d = d.asfreq("D")  # vul ontbrekende dagen
    # 7-daags voortschrijdend gemiddelde (het 'trend'-signaal)
    for col in ["up2_pct", "up3_pct", "range_pct", "vf1"]:
        d[col + "_7d"] = d[col].rolling(7, min_periods=3).mean()
    return d


def trade_pl_daily(sym):
    c = pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                        database="brain", cursorclass=pymysql.cursors.DictCursor)
    cur = c.cursor()
    cur.execute("""SELECT DATE(datetime) d, COUNT(*) n, SUM(profit_loss) pl, AVG(profit_loss) avg_pl
                   FROM coin_fires WHERE symbol=%s AND is_executed=1 GROUP BY d ORDER BY d""", (sym,))
    rows = cur.fetchall(); c.close()
    t = pd.DataFrame(rows)
    t["d"] = pd.to_datetime(t["d"])
    t = t.set_index("d").asfreq("D")
    t["n"] = t["n"].fillna(0)
    t["pl"] = t["pl"].astype(float).fillna(0)
    # 14-daags voortschrijdend gemiddelde winst/trade (genoeg trades om niet pure ruis te zijn)
    t["avg_pl_14d"] = t["pl"].rolling(14, min_periods=1).sum() / t["n"].rolling(14, min_periods=1).sum().replace(0, np.nan)
    return t


def first_cross_below(series, thr):
    """Eerste datum waarop de reeks (na ooit boven thr te zijn geweest) onder thr zakt."""
    above = series > thr
    seen_above = False
    for dt, v in series.items():
        if pd.isna(v):
            continue
        if v > thr:
            seen_above = True
        elif seen_above and v <= thr:
            return dt
    return None


if __name__ == "__main__":
    for sym in (sys.argv[1:] or ["DOGEAI", "NOS"]):
        d = daily(sym)
        t = trade_pl_daily(sym)
        print(f"\n================= {sym}: 7-daags beweeglijkheid vs 14-daags winst/trade =================")
        merged = d.join(t[["n", "avg_pl_14d"]], how="left")
        # toon elke 5e dag, compact
        show = merged.iloc[::5][["up2_pct_7d", "up3_pct_7d", "range_pct_7d", "vf1_7d", "avg_pl_14d"]]
        with pd.option_context("display.max_rows", 200, "display.width", 160):
            print(show.round(2).to_string())
