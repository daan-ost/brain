"""
READ-ONLY analyse — 'wanneer stoppen we met een coin?' (te weinig beweging).

Bouwt uit de RUWE volumeud-ticks (brain.indicators) per coin per maand een set
mogelijke STOP-signalen, en zet die naast de trade-uitkomst (grondwaarheid) om te
toetsen of een signaal de winst-omslag EERDER of tegelijk ziet.

Niets wordt geschreven. Geen rule/engine-wijziging. Puur meten.

Signalen (allemaal uit volumeud: datetime, value, price, volume_found):
  A. ACTIVITEIT (puur tellen, geen vooruitkijken)
     - n_ticks            : aantal volumeud-ticks (hoe levendig is de coin)
     - vf1                 : aantal kandidaat-ticks (volume_found=1)
     - avg_relvol/max      : relatief volume = value / min_volume
     - n_spikes            : ticks met relvol >= SPIKE (grote volume-pieken)
  B. BEWEEGLIJKHEID (prijs)
     - day_range_pct       : (hoog-laag)/laag per dag, mediaan over de maand
     - up60_p90            : 90e-percentiel van de stijging binnen 60 min na een tick
     - frac_up{X}          : fractie ticks die binnen 60 min nog >= X% stijgen
       -> dit is de kern: heeft de coin ZELF nog winstkansen, los van de rules?
"""
import sys
import pymysql
import numpy as np
import pandas as pd

MIN_VOL = {"DOGEAI": 15169.0, "NOS": 510.0}   # rule 20/21 min_volume per coin
SPIKE = 8.0          # relvol-drempel voor een 'grote' volume-piek (orde van multiplier_volume_sum_min)
FWD_MIN = 60         # forward-window voor de beweeglijkheid (1u hold, = de sell-horizon)
UP_LEVELS = (1.0, 2.0, 3.0)   # % stijging-drempels voor frac_up


def load(sym):
    c = pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                        database="brain", cursorclass=pymysql.cursors.Cursor)
    cur = c.cursor()
    cur.execute("""SELECT datetime, value, price, volume_found
                   FROM indicators WHERE symbol=%s AND indicator='volumeud'
                   ORDER BY datetime""", (sym,))
    rows = cur.fetchall()
    c.close()
    df = pd.DataFrame(rows, columns=["dt", "value", "price", "vf"])
    df["dt"] = pd.to_datetime(df["dt"])
    df["value"] = df["value"].astype(float)
    df["price"] = df["price"].astype(float)
    df["vf"] = df["vf"].astype(int)
    # LOKAAL glitch-filter: TradingView levert af en toe een kapotte prijs (bv 23044 bij
    # een coin van ~0.02). Een GLOBALE drempel kan niet — de prijs trendt over maanden.
    # Dus: rolling mediaan (101 ticks, gecentreerd) en gooi ticks weg die >3x of <1/3 daarvan
    # liggen. Vangt de glitch, laat de trend intact.
    med = df["price"].rolling(101, center=True, min_periods=11).median()
    ratio = df["price"] / med
    bad = (ratio > 3) | (ratio < 1 / 3)
    df = df[~bad.fillna(False)].reset_index(drop=True)
    return df


def forward_upside(df, fwd_min=FWD_MIN):
    """Per tick: max stijging (%) van de prijs binnen de komende fwd_min minuten.
    Two-pointer over de tijd-gesorteerde reeks. Leak-vrij t.o.v. trade-selectie:
    het meet de beweging van de coin zelf, niet welke ticks de rules kozen."""
    t = df["dt"].values.astype("datetime64[s]")
    p = df["price"].values
    n = len(df)
    win = np.timedelta64(fwd_min * 60, "s")
    up = np.zeros(n)
    j = 0
    # voor elke i: kijk vooruit tot t[j] > t[i]+win, houd de max prijs bij
    from collections import deque
    # eenvoudige aanpak: sliding max met een monotone deque op index-volgorde
    dq = deque()  # indices met afnemende prijs
    # we lopen i van n-1 terug en houden een max over [i, i waar t<=t[i]+win]
    # makkelijker: voorwaartse two-pointer met een max-structuur
    # gebruik een simpele O(n*k) met numpy-slice want k (ticks/uur) is klein (~40)
    end = 0
    for i in range(n):
        if end < i + 1:
            end = i + 1
        limit = t[i] + win
        while end < n and t[end] <= limit:
            end += 1
        if end > i + 1:
            pmax = p[i + 1:end].max()
            up[i] = (pmax - p[i]) / p[i] * 100.0 if p[i] > 0 else 0.0
        else:
            up[i] = 0.0
    return up


def analyse(sym):
    df = load(sym)
    mv = MIN_VOL[sym]
    df["relvol"] = df["value"] / mv
    df["up60"] = forward_upside(df)
    df["ym"] = df["dt"].dt.to_period("M").astype(str)
    df["day"] = df["dt"].dt.date

    # per-dag prijsrange, ROBUUST: (p95 - p5) / mediaan, niet min/max (glitch-bestendig)
    day = df.groupby("day")["price"].agg(
        p5=lambda s: np.percentile(s, 5), p95=lambda s: np.percentile(s, 95),
        pmed="median",
    )
    day["range_pct"] = (day["p95"] - day["p5"]) / day["pmed"] * 100.0
    day_range = df["day"].map(day["range_pct"])
    df["day_range_pct"] = day_range.values

    rows = []
    for ym, g in df.groupby("ym"):
        ndays = g["day"].nunique()
        r = dict(
            ym=ym,
            n_ticks=len(g),
            ticks_per_dag=round(len(g) / ndays, 0),
            vf1=int(g["vf"].sum()),
            vf1_per_dag=round(g["vf"].sum() / ndays, 1),
            avg_relvol=round(g["relvol"].mean(), 2),
            max_relvol=round(g["relvol"].max(), 1),
            n_spikes=int((g["relvol"] >= SPIKE).sum()),
            day_range_pct=round(g.groupby("day")["day_range_pct"].first().median(), 2),
            up60_p90=round(np.percentile(g["up60"], 90), 2),
        )
        for x in UP_LEVELS:
            r[f"up{int(x)}"] = round((g["up60"] >= x).mean() * 100, 1)  # % ticks
        rows.append(r)
    return pd.DataFrame(rows)


def grondwaarheid(sym):
    c = pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                        database="brain", cursorclass=pymysql.cursors.DictCursor)
    cur = c.cursor()
    cur.execute("""SELECT DATE_FORMAT(datetime,'%%Y-%%m') ym, COUNT(*) trades,
                          ROUND(SUM(profit_loss),1) sum_pl, ROUND(AVG(profit_loss),3) avg_pl
                   FROM coin_fires WHERE symbol=%s AND is_executed=1
                   GROUP BY ym ORDER BY ym""", (sym,))
    g = {r["ym"]: r for r in cur.fetchall()}
    c.close()
    return g


if __name__ == "__main__":
    pd.set_option("display.width", 200)
    pd.set_option("display.max_columns", 30)
    for sym in (sys.argv[1:] or ["DOGEAI", "NOS"]):
        sig = analyse(sym)
        gw = grondwaarheid(sym)
        sig["trades"] = sig["ym"].map(lambda y: gw.get(y, {}).get("trades", 0))
        sig["sum_pl"] = sig["ym"].map(lambda y: float(gw.get(y, {}).get("sum_pl", 0) or 0))
        sig["avg_pl"] = sig["ym"].map(lambda y: float(gw.get(y, {}).get("avg_pl", 0) or 0))
        print(f"\n================= {sym} =================")
        print(sig.to_string(index=False))
