"""
Economische backtest (Epic G, critical-eye #1): wat levert de actieve-periode-filter ECHT op in geld,
na slippage — i.p.v. overeenkomst met de handmatige benchmark.

Twee vergelijkingen, elk bij slippage-aftrek per trade van 0,2% en 0,4% (koop+verkoop samen):

  A. ONBEPERKT (huidige aanname: oneindig parallelle posities) — Σ netto-winst, alle trades vs alleen actief.
  B. ÉÉN GLOBALE POSITIE (Daans rotatiemodel) — één trade tegelijk over álle munten. Greedy op tijd:
     neem een trade alleen als de positie vrij is; trades die starten terwijl je nog in een positie zit,
     vervallen. "Alles" = elke munt mag de plek pakken; "gated" = alleen actieve munten zijn kandidaat.
     Dit meet of een dode munt de positie-plek blokkeert voor een betere munt.

Σ is additief in % (zoals alle schermen). KANTTEKENING (critical-eye P3): de actief/inactief-indeling komt
van de volledige-historie-gate, die in off-perioden op ECHTE trades herstart; live gebeurt dat op
schaduw-trades. De herstart-cijfers zijn dus backtest-optimistisch. Het stop-effect (de dode staarten weg)
is robuust; het herstart-effect is een bovengrens.
"""
import pandas as pd
from db import brain

WINDOW, STOP_FLOOR, STOP_CONFIRM, RESTART_FLOOR, RESTART_CONFIRM = 4, 20.0, 2, 30.0, 3
SLIPPAGES = [0.2, 0.4]


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


def load():
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT t.trading_symbol_id sid, COALESCE(co.symbol,t.trading_symbol_id) sym, "
                  "t.datetime buy, t.selling_datetime sell, t.profit_loss pl FROM coin_fires t "
                  "LEFT JOIN coins co ON co.id=t.trading_symbol_id "
                  "WHERE t.is_executed=1 AND t.profit_loss IS NOT NULL")
        rows = c.fetchall()
    conn.close()
    df = pd.DataFrame(rows)
    df["buy"] = pd.to_datetime(df["buy"])
    # selling_datetime kan ontbreken -> neem koop + 60 min (max hold) zodat de positie-plek vrijkomt
    df["sell"] = pd.to_datetime(df["sell"]).fillna(df["buy"] + pd.Timedelta(minutes=60))
    df["pl"] = pd.to_numeric(df["pl"], errors="coerce")
    df["wk"] = df["buy"].dt.normalize() - pd.to_timedelta(df["buy"].dt.weekday, unit="D")
    # actief/inactief per trade via de wekelijkse gate
    active = {}
    for sym, g in df.groupby("sym"):
        wk = g.groupby("wk").agg(pl=("pl", "sum"), n=("pl", "size")).sort_index()
        full = pd.date_range(wk.index.min(), wk.index.max(), freq="W-MON")
        wk = wk.reindex(full, fill_value=0)
        st = dict(zip(wk.index, run_gate(wk["pl"].tolist(), wk["n"].tolist())))
        for idx, row in g.iterrows():
            active[idx] = st.get(row["wk"], "on") == "on"
    df["active"] = pd.Series(active)
    return df


def one_position(sub, h):
    """Greedy één-positie op tijd. sub: DataFrame met buy, sell, pl (al gefilterd op de kandidaat-pool)."""
    s = sub.sort_values("buy")
    free_at = None; total = 0.0; taken = 0; losers = 0
    for buy, sell, pl in zip(s["buy"], s["sell"], s["pl"]):
        if free_at is not None and buy < free_at:
            continue  # plek bezet
        net = pl - h
        total += net; taken += 1; losers += int(net < 0)
        free_at = sell
    return total, taken, losers


def main():
    df = load()
    print("Economische backtest — Σ netto-winst (%) na slippage. KANTTEKENING: herstart backtest-optimistisch (P3).\n")

    for h in SLIPPAGES:
        df["net"] = df["pl"] - h
        print("=" * 74)
        print(f"SLIPPAGE {h}% per trade")
        print("=" * 74)

        # A. ONBEPERKT (oneindig parallelle posities)
        print("\nA. Onbeperkt (alle posities parallel) — Σ netto per munt")
        print(f"   {'munt':<10}{'trades alle':>12}{'Σ alle':>10}{'trades act':>12}{'Σ actief':>10}{'Δ':>9}")
        ta = sa = tg = sg = 0
        for sym, g in df.groupby("sym"):
            na = len(g); s_all = g["net"].sum()
            ga = g[g["active"]]; ng = len(ga); s_act = ga["net"].sum()
            ta += na; sa += s_all; tg += ng; sg += s_act
            print(f"   {sym:<10}{na:>12}{s_all:>9.0f}%{ng:>12}{s_act:>9.0f}%{s_act-s_all:>+8.0f}")
        print(f"   {'TOTAAL':<10}{ta:>12}{sa:>9.0f}%{tg:>12}{sg:>9.0f}%{sg-sa:>+8.0f}")

        # B. ÉÉN GLOBALE POSITIE (rotatie)
        print("\nB. Één globale positie (greedy op tijd) — de echte rotatie-test")
        t_all, k_all, l_all = one_position(df, h)
        t_g, k_g, l_g = one_position(df[df["active"]], h)
        print(f"   {'pool':<14}{'genomen trades':>16}{'verliezers':>12}{'Σ netto':>12}")
        print(f"   {'alle munten':<14}{k_all:>16}{l_all:>12}{t_all:>11.0f}%")
        print(f"   {'alleen actief':<14}{k_g:>16}{l_g:>12}{t_g:>11.0f}%")
        print(f"   {'verschil':<14}{k_g-k_all:>+16}{l_g-l_all:>+12}{t_g-t_all:>+11.0f}%")
        print()


if __name__ == "__main__":
    main()
