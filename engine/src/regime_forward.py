"""
Vooruit-voorspellende toets (Epic G, critical-eye #2 / P2) — NIET-circulair.

De benchmark is uit resultaten afgeleid, dus "gate matcht benchmark" is deels tautologisch. Deze toets
omzeilt dat: voorspelt de gate-stand op moment T (berekend op ALLEEN data t/m T) het trade-resultaat van
de weken NÁ T (die niet in de beslissing zaten)?

  predictor  = gate-stand[T]   (rollend venster eindigt op T → leak-vrij)
  doel       = resultaat[T+1] (volgende week) en resultaat[T+1..T+4] (volgende 4 weken)

Als 'aan'-weken gevolgd worden door duidelijk hogere toekomst-resultaten dan 'uit'-weken, heeft de gate
echte voorspelkracht — onafhankelijk van de handmatige labels. Toeval-toets: schud de standen door elkaar
(per munt) en kijk of de echte aan/uit-kloof groter is dan bij duizenden geschudde varianten.

Vergelijking met een NAÏEVE drempel (rollend ≥ lat, zonder demping/asymmetrie) laat zien of de demping
vooruit iets toevoegt of alleen ruis dempt. Doel-resultaten zijn RUW (zonder slippage); met slippage
worden de 'uit'-weken alleen maar slechter.
"""
import numpy as np
import pandas as pd

from db import brain

WINDOW, STOP_FLOOR, STOP_CONFIRM, RESTART_FLOOR, RESTART_CONFIRM = 4, 20.0, 2, 30.0, 3
N_PERM, SEED = 3000, 42


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


def naive_gate(pl, n):
    """Geen demping/asymmetrie: aan zodra rollend >= lat, anders uit."""
    states, started, hist = [], False, []
    for p, cnt in zip(pl, n):
        if not started:
            if cnt > 0:
                started = True
            else:
                states.append("pre"); continue
        hist.append(p); hist = hist[-WINDOW:]
        states.append("on" if sum(hist) >= STOP_FLOOR else "off")
    return states


def weekly_per_coin():
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT trading_symbol_id sid, COALESCE(co.symbol,trading_symbol_id) sym, "
                  "DATE(datetime) d, SUM(profit_loss) pl, COUNT(*) n FROM coin_fires t "
                  "LEFT JOIN coins co ON co.id=t.trading_symbol_id "
                  "WHERE is_executed=1 AND profit_loss IS NOT NULL GROUP BY sid, sym, d")
        rows = c.fetchall()
    conn.close()
    df = pd.DataFrame(rows)
    df["d"] = pd.to_datetime(df["d"]); df["pl"] = pd.to_numeric(df["pl"], errors="coerce")
    out = {}
    for sym, g in df.groupby("sym"):
        g = g.copy()
        g["wk"] = g["d"].dt.normalize() - pd.to_timedelta(g["d"].dt.weekday, unit="D")
        wk = g.groupby("wk").agg(pl=("pl", "sum"), n=("pl", "size")).sort_index()
        full = pd.date_range(wk.index.min(), wk.index.max(), freq="W-MON")
        out[sym] = wk.reindex(full, fill_value=0)
    return out


def forward_pairs(states, pl, horizon):
    """(stand[T], Σresultaat[T+1..T+horizon]) voor elke T met genoeg toekomst en stand in {on,off}."""
    pairs = []
    for i in range(len(states)):
        if states[i] == "pre" or i + 1 >= len(pl):
            continue
        fut = pl[i + 1:i + 1 + horizon]
        if len(fut) == 0:
            continue
        pairs.append((states[i], float(np.sum(fut))))
    return pairs


def gap(pairs):
    on = [v for s, v in pairs if s == "on"]
    off = [v for s, v in pairs if s == "off"]
    if not on or not off:
        return None, None, None
    return float(np.mean(on)), float(np.mean(off)), float(np.mean(on) - np.mean(off))


def main():
    coins = weekly_per_coin()
    rng = np.random.default_rng(SEED)

    print("VOORUIT-VOORSPELLENDE TOETS (niet-circulair) — doelresultaten RUW (zonder slippage)\n")

    for horizon, lbl in [(1, "volgende week"), (4, "volgende 4 weken")]:
        print("=" * 70)
        print(f"Horizon: {lbl}")
        print("=" * 70)
        print(f"{'munt':<10}{'aan→toekomst':>15}{'uit→toekomst':>15}{'kloof (aan−uit)':>18}")
        pooled = []
        per_state = {}
        for sym, wk in coins.items():
            pl = wk["pl"].tolist(); n = wk["n"].tolist()
            st = run_gate(pl, n)
            pr = forward_pairs(st, pl, horizon)
            per_state[sym] = (st, pl)
            pooled += pr
            mon, moff, gp = gap(pr)
            if gp is None:
                print(f"{sym:<10}{'(altijd zelfde stand)':>48}")
            else:
                print(f"{sym:<10}{mon:>13.1f}%{moff:>13.1f}%{gp:>+16.1f}")
        mon, moff, real_gap = gap(pooled)
        print(f"{'POOLED':<10}{mon:>13.1f}%{moff:>13.1f}%{real_gap:>+16.1f}")

        # toeval-toets: schud standen per munt, herbereken pooled kloof
        ge = 0
        for _ in range(N_PERM):
            shuf = []
            for sym, (st, pl) in per_state.items():
                idx = [i for i in range(len(st)) if st[i] != "pre"]
                lbls = [st[i] for i in idx]
                rng.shuffle(lbls)
                st2 = list(st)
                for j, i in enumerate(idx):
                    st2[i] = lbls[j]
                shuf += forward_pairs(st2, pl, horizon)
            _, _, g2 = gap(shuf)
            if g2 is not None and g2 >= real_gap - 1e-9:
                ge += 1
        p = (ge + 1) / (N_PERM + 1)
        print(f"\n  toeval-toets: p={p:.3f}  {'✓ voorspelt de toekomst (niet toeval)' if p < 0.05 else '✗ niet significant'}")
        print("  (kanttekening: standen lopen in lange reeksen → effectieve N < #weken; effectgrootte is leidend)")

        # naïeve drempel ter vergelijking (alleen voor horizon 4)
        if horizon == 4:
            naive_pooled = []
            for sym, wk in coins.items():
                pl = wk["pl"].tolist(); n = wk["n"].tolist()
                naive_pooled += forward_pairs(naive_gate(pl, n), pl, horizon)
            _, _, ng = gap(naive_pooled)
            print(f"\n  Naïeve drempel (geen demping) kloof: {ng:+.1f}  vs  gate {real_gap:+.1f}  "
                  f"→ demping voegt {real_gap-ng:+.1f} toe aan vooruit-scheiding")
        print()


if __name__ == "__main__":
    main()
