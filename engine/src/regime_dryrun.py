"""
Dry-run (Epic G/H): hoeveel trades vallen weg als we de actieve-periode-filter toepassen?
Per munt: goed/middel/slecht ALLE trades -> trades in ACTIEVE periode (rest = voorkomen/weggevallen).

Gate = de gevalideerde v2 (wekelijks, rollend 4w, uit<20 na 2w, aan>=30 na 3w, start bij 1e trade-week).
Classificatie = zoals het Trades-scherm: goed >=3%, middel 0..3%, slecht <0% (gerealiseerde profit_loss).
LEAK-VRIJ niet relevant: terugtoets op de bestaande historie.
"""
import pandas as pd
from db import brain

WINDOW, STOP_FLOOR, STOP_CONFIRM, RESTART_FLOOR, RESTART_CONFIRM = 4, 20.0, 2, 30.0, 3


def run_gate(pl, n):
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
            below = below + 1 if roll < STOP_FLOOR else 0; above = 0
            if below >= STOP_CONFIRM: state, below = "off", 0
        else:
            above = above + 1 if roll >= RESTART_FLOOR else 0; below = 0
            if above >= RESTART_CONFIRM: state, above = "on", 0
        states.append(state)
    return states


def cls(pl):
    return "goed" if pl >= 3 else ("middel" if pl >= 0 else "slecht")


def main():
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT t.trading_symbol_id sid, COALESCE(co.symbol, t.trading_symbol_id) sym, "
                  "t.datetime dt, t.profit_loss pl FROM coin_fires t "
                  "LEFT JOIN coins co ON co.id=t.trading_symbol_id "
                  "WHERE t.is_executed=1 AND t.profit_loss IS NOT NULL")
        rows = c.fetchall()
    conn.close()
    df = pd.DataFrame(rows)
    df["dt"] = pd.to_datetime(df["dt"])
    df["pl"] = pd.to_numeric(df["pl"], errors="coerce")
    df["wk"] = df["dt"].dt.normalize() - pd.to_timedelta(df["dt"].dt.weekday, unit="D")
    df["k"] = df["pl"].apply(cls)

    print(f"{'munt':<9}{'klasse':<8}{'alle':>6}{'actief':>8}{'weg':>6}{'  Σ alle':>10}{'Σ actief':>10}")
    print("-" * 60)
    tot = {}
    grand = {"all": {}, "act": {}}
    for sym, g in df.groupby("sym"):
        # wekelijkse reeks (vul gaten met 0) + gate
        wk = g.groupby("wk").agg(pl=("pl", "sum"), n=("pl", "size")).sort_index()
        full = pd.date_range(wk.index.min(), wk.index.max(), freq="W-MON")
        wk = wk.reindex(full, fill_value=0)
        states = run_gate(wk["pl"].tolist(), wk["n"].tolist())
        state_by_wk = dict(zip(wk.index, states))
        g = g.copy()
        g["active"] = g["wk"].map(lambda w: state_by_wk.get(w, "on") == "on")

        for k in ["goed", "middel", "slecht"]:
            sub = g[g["k"] == k]
            alle = len(sub); act = int(sub["active"].sum()); weg = alle - act
            s_all = sub["pl"].sum(); s_act = sub[sub["active"]]["pl"].sum()
            print(f"{sym:<9}{k:<8}{alle:>6}{act:>8}{weg:>6}{s_all:>9.0f}%{s_act:>9.0f}%")
            grand["all"].setdefault(k, [0, 0.0]); grand["act"].setdefault(k, [0, 0.0])
            grand["all"][k][0] += alle; grand["all"][k][1] += s_all
            grand["act"][k][0] += act; grand["act"][k][1] += s_act
        # munt-totaal Σ
        tot[sym] = (g["pl"].sum(), g[g["active"]]["pl"].sum())
        print(f"{sym:<9}{'Σ TOT':<8}{len(g):>6}{int(g['active'].sum()):>8}"
              f"{len(g)-int(g['active'].sum()):>6}{g['pl'].sum():>9.0f}%{g[g['active']]['pl'].sum():>9.0f}%")
        print("-" * 60)

    print("\nTOTAAL (alle munten):")
    for k in ["goed", "middel", "slecht"]:
        a, sa = grand["all"][k]; b, sb = grand["act"][k]
        print(f"  {k:<7} {a:>5} -> {b:<5} ({a-b:>4} weg)   Σ {sa:>6.0f}% -> {sb:>6.0f}%")
    sall = sum(v[0] for v in tot.values()); sact = sum(v[1] for v in tot.values())
    print(f"  Σwinst totaal: {sall:.0f}% -> {sact:.0f}%  (alle perioden -> alleen actief)")


if __name__ == "__main__":
    main()
