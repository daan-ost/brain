#!/usr/bin/env python3
"""
min_volume_study.py — Epic K, Feature 2 (O1 + O2). READ-ONLY meet-fase.

min_volume is de volume-schaal per munt (relvol = volumeud / min_volume). In de praktijk ~p90 van de
volumeud-reeks. Dit script meet — puur via SELECT op `indicators`, muteert NIETS — twee dingen die het
koude-start-recept voor een verse munt bepalen:

  O1  Convergentie: hoe snel nadert de percentiel-schatting uit de eerste 12u / 1d / 3d / 1w de
      eind-schatting (volledige historie)? -> minimale data vóór de eerste betrouwbare min_volume.
  O2  Listing-piek: is het volume in de eerste dag(en) systematisch hoger dan in het stabiele regime?
      Zo ja, dan overschat een vroege percentiel-schatting min_volume (-> te weinig kandidaten) en is
      een correctie of wachttijd nodig.

Output: een compacte tabel per munt + een JSON-dump (out/min_volume_study.json) voor het findings-doc.

Usage: min_volume_study.py [symbol_id ...]   (default: alle munten met een positieve buy-rule min_volume)
"""
import json
import os
import sys
from datetime import timedelta

import numpy as np

from db import brain

WINDOWS = [("12u", 12), ("1d", 24), ("3d", 72), ("1w", 168)]   # afkap-vensters in uren na de eerste tick
PCTS = [85, 90, 95]                                            # percentielen om te volgen (p90 = praktijk)
CONV_TOL = 10.0                                               # |afwijking| < deze % = "geconvergeerd"
OUT = "out/min_volume_study.json"


def coins_with_rules(conn):
    with conn.cursor() as c:
        c.execute("SELECT DISTINCT crs.trading_symbol_id sid, co.symbol FROM coin_rule_settings crs "
                  "JOIN coins co ON co.id=crs.trading_symbol_id "
                  "WHERE crs.rule_number IN (20,21,22,23) AND crs.min_volume>0 ORDER BY sid")
        return [(r["sid"], r["symbol"]) for r in c.fetchall()]


def load_series(conn, sym):
    with conn.cursor() as c:
        c.execute("SELECT datetime, value FROM indicators WHERE trading_symbol_id=%s "
                  "AND indicator='volumeud' AND value IS NOT NULL ORDER BY datetime", (sym,))
        return [(r["datetime"], float(r["value"])) for r in c.fetchall()]


def cur_min_volume(conn, sym):
    """De huidige (live) volume-schaal = de laagste min_volume over de buy-rules (= relvol_base)."""
    with conn.cursor() as c:
        c.execute("SELECT MIN(min_volume) mv FROM coin_rule_settings WHERE trading_symbol_id=%s "
                  "AND rule_number IN (20,21,22,23) AND min_volume>0", (sym,))
        r = c.fetchone()
    return float(r["mv"]) if r and r["mv"] else None


def o1_convergence(series):
    """Eind-percentielen + per venster de vroege schatting en de afwijking t.o.v. eind (%)."""
    vals_all = [v for _, v in series]
    end = {p: float(np.percentile(vals_all, p)) for p in PCTS}
    t0 = series[0][0]
    rows = []
    for name, hrs in WINDOWS:
        cutoff = t0 + timedelta(hours=hrs)
        sub = [v for dt, v in series if dt <= cutoff]
        row = {"window": name, "n": len(sub)}
        for p in PCTS:
            est = float(np.percentile(sub, p)) if sub else None
            row[f"p{p}"] = est
            row[f"dev{p}"] = ((est - end[p]) / end[p] * 100.0) if (est and end[p]) else None
        rows.append(row)
    return end, rows


def first_converged_window(rows, p=90):
    """Eerste venster waarop |afwijking| < CONV_TOL; anders None (>1w)."""
    for r in rows:
        d = r.get(f"dev{p}")
        if d is not None and abs(d) < CONV_TOL:
            return r["window"]
    return None


def o2_listing_peak(series, end_p90):
    """Listing-piek op de BOVENSTAART (p90 per dag), want volumeud is een net-signaal (kan negatief) —
    de mediaan is ~0 en zegt niets over de schaal. piek_d0 = p90(dag 0) / eind_p90; >1 = de eerste dag
    overschat de schaal (launch-hype) -> een vroege min_volume zou te hoog zijn -> te weinig kandidaten."""
    t0 = series[0][0]
    span_d = (series[-1][0] - t0).total_seconds() / 86400.0
    daily_p90 = []
    for d in range(7):
        seg = [v for dt, v in series if t0 + timedelta(days=d) <= dt < t0 + timedelta(days=d + 1)]
        daily_p90.append(float(np.percentile(seg, 90)) if len(seg) >= 10 else None)
    d0 = daily_p90[0]
    first3 = [x for x in daily_p90[:3] if x is not None]
    return {"span_d": round(span_d, 1),
            "piek_d0": (d0 / end_p90) if (d0 and end_p90) else None,
            "piek_max3": (max(first3) / end_p90) if (first3 and end_p90) else None,
            "daily_p90": [round(x, 1) if x is not None else None for x in daily_p90]}


def pct_rank(vals, x):
    """Percentielrang van x in de verdeling: % van de waarden <= x."""
    if not vals or x is None:
        return None
    return 100.0 * sum(1 for v in vals if v <= x) / len(vals)


def fmt(x, w=12):
    if x is None:
        return "—".rjust(w)
    if abs(x) >= 1e6:
        return f"{x:,.0f}".rjust(w)
    return f"{x:,.2f}".rjust(w) if abs(x) < 1000 else f"{x:,.0f}".rjust(w)


def main():
    args = [int(a) for a in sys.argv[1:]]
    conn = brain()
    coins = [(s, n) for (s, n) in coins_with_rules(conn) if not args or s in args]

    print(f"\nEpic K — O1 (convergentie) + O2 (listing-piek). {len(coins)} munten. READ-ONLY.\n")
    print("O1: dev = afwijking van de p90-schatting in dat venster t.o.v. de eind-p90 (volledige historie).")
    print("O2: piek = p90 van de eerste dag(en) / eind-p90 (>1 = launch-hype overschat de schaal).\n")
    hdr = (f"{'munt':<11}{'n_tot':>8}{'span_d':>7}{'%neg':>6}{'eind_p90':>14}{'rank':>5}"
           f"{'dev12u':>8}{'dev1d':>8}{'dev3d':>8}{'dev1w':>8}{'piek_d0':>8}{'piekmax3':>9}")
    print(hdr)
    print("-" * len(hdr))

    def dev(rows, w):
        d = next((r["dev90"] for r in rows if r["window"] == w), None)
        return f"{d:+.0f}%" if d is not None else "—"

    report = {}
    for sym, name in coins:
        series = load_series(conn, sym)
        if len(series) < 10:
            print(f"{name:<11}{len(series):>8}   te weinig data")
            report[name] = {"sid": sym, "n": len(series), "skip": "te weinig data"}
            continue
        end, rows = o1_convergence(series)
        o2 = o2_listing_peak(series, end[90])
        mv = cur_min_volume(conn, sym)
        vals_all = [v for _, v in series]
        rank = pct_rank(vals_all, mv)
        pctneg = 100.0 * sum(1 for v in vals_all if v < 0) / len(vals_all)
        rank_s = f"{rank:.0f}" if rank is not None else "—"
        pd0 = f"{o2['piek_d0']:.2f}" if o2["piek_d0"] else "—"
        pm3 = f"{o2['piek_max3']:.2f}" if o2["piek_max3"] else "—"
        print(f"{name:<11}{len(series):>8}{o2['span_d']:>7.0f}{pctneg:>6.0f}{fmt(end[90]):>14}{rank_s:>5}"
              f"{dev(rows,'12u'):>8}{dev(rows,'1d'):>8}{dev(rows,'3d'):>8}{dev(rows,'1w'):>8}{pd0:>8}{pm3:>9}")
        report[name] = {"sid": sym, "n_tot": len(series), "span_d": o2["span_d"], "pct_neg": round(pctneg, 1),
                        "end_pct": end, "huidig_mv": mv, "mv_rank_pct": rank,
                        "conv90_window": first_converged_window(rows, 90) or ">1w",
                        "o1_windows": rows, "o2": o2}

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, "w") as f:
        json.dump(report, f, indent=2, default=str)
    print(f"\nJSON-dump: engine/src/{OUT}")
    conn.close()


if __name__ == "__main__":
    main()
