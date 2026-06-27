#!/usr/bin/env python3
"""Consensus-precisie check (read-only). Vraag: breekt "koop alleen waar ≥2 ontdekte rules (30-34)
SAMEN vuren" het verlies-zware patroon? Vergelijkt de uitkomst van executed discovery-trades waar op
hetzelfde moment 1 rule vuurde vs ≥2 rules vuurden.

Maat: gerealiseerde profit_loss (CLAUDE.md: nooit best_upside). goed = pl>=3, slecht = pl<0.
Toeval-toets: schud de consensus-labels N× en kijk of de waargenomen slecht%-daling boven toeval valt.
Per munt + gepoold. Eerder (2 munten) brak dit het patroon op DOGEAI (slecht 63->46%, p=0,000) maar te
zwak op NOS — nu met FARTCOIN+MUMU erbij toetsen of het generaliseert.

Usage: consensus_check.py [n_perm]   (default 5000)
"""
import sys
import numpy as np

from db import brain

DISCOVERY = (30, 31, 32, 34)
NAME = {2525: "DOGEAI", 244: "NOS", 8427: "FARTCOIN", 2735: "MUMU"}
N_PERM = int(sys.argv[1]) if len(sys.argv) > 1 else 5000
RNG = np.random.default_rng(20260626)


def load(sym):
    """Per executed discovery-trade: (profit_loss, consensus) waar consensus = #distinct discovery-rules
    die op DEZELFDE datetime een coin_fire hebben (executed OF shadow — 'samen vuren' op de tick)."""
    c = brain()
    with c.cursor() as cur:
        # consensus-telling per datetime: hoeveel distinct discovery-rules vuren daar
        cur.execute(
            "SELECT datetime, COUNT(DISTINCT rule) k FROM coin_fires "
            "WHERE trading_symbol_id=%s AND rule IN (30,31,32,34) GROUP BY datetime", (sym,))
        cons = {r["datetime"]: int(r["k"]) for r in cur.fetchall()}
        # executed discovery-trades met hun uitkomst
        cur.execute(
            "SELECT datetime, profit_loss FROM coin_fires WHERE trading_symbol_id=%s "
            "AND rule IN (30,31,32,34) AND is_executed=1 AND profit_loss IS NOT NULL", (sym,))
        rows = cur.fetchall()
    c.close()
    pl = np.array([float(r["profit_loss"]) for r in rows])
    k = np.array([cons.get(r["datetime"], 1) for r in rows])
    return pl, k


def stats(pl):
    n = len(pl)
    if n == 0:
        return dict(n=0, good=0, bad=0, badpct=0.0, sigma=0.0)
    return dict(n=n, good=int((pl >= 3).sum()), bad=int((pl < 0).sum()),
                badpct=round(float((pl < 0).mean()) * 100, 1), sigma=round(float(pl.sum()), 1))


def perm_test(pl, k):
    """H0: consensus-label (≥2 vs 1) staat los van de uitkomst. Statistiek = slecht% bij 1-rule MINUS
    slecht% bij ≥2-rule (positief = consensus heeft minder slecht). Schud k, herbereken, p = fractie
    geschudde verschillen >= waargenomen."""
    is_multi = k >= 2
    if is_multi.sum() < 5 or (~is_multi).sum() < 5:
        return None
    bad = (pl < 0).astype(float)
    obs = bad[~is_multi].mean() - bad[is_multi].mean()
    cnt = 0
    m = is_multi.sum()
    idx = np.arange(len(pl))
    for _ in range(N_PERM):
        perm = RNG.permutation(idx)
        mm = perm[:m]
        multi_mask = np.zeros(len(pl), bool); multi_mask[mm] = True
        diff = bad[~multi_mask].mean() - bad[multi_mask].mean()
        if diff >= obs - 1e-12:
            cnt += 1
    return dict(obs_drop=round(obs * 100, 1), p=round((cnt + 1) / (N_PERM + 1), 4))


def report(label, pl, k):
    single = stats(pl[k == 1])
    multi = stats(pl[k >= 2])
    print(f"\n=== {label} ===")
    print(f"  1 rule  : {single['n']:>4} trades | goed {single['good']:>3} / slecht {single['bad']:>4} "
          f"({single['badpct']}% slecht) | Σ {single['sigma']}%")
    print(f"  ≥2 rules: {multi['n']:>4} trades | goed {multi['good']:>3} / slecht {multi['bad']:>4} "
          f"({multi['badpct']}% slecht) | Σ {multi['sigma']}%")
    pt = perm_test(pl, k)
    if pt is None:
        print("  toeval-toets: te weinig data in een van de groepen")
    else:
        sig = "SIGNIFICANT" if pt["p"] < 0.05 else "niet sig."
        print(f"  slecht% daalt {single['badpct']}→{multi['badpct']} (Δ{pt['obs_drop']}pp) | toeval-toets p={pt['p']} [{sig}]")
    # ook de selectiviteit: hoeveel % van de trades is consensus?
    if single['n'] + multi['n']:
        print(f"  consensus-aandeel: {multi['n']}/{single['n']+multi['n']} "
              f"({multi['n']/(single['n']+multi['n'])*100:.0f}% van de discovery-trades vuurt met ≥2)")


def main():
    print(f"Consensus-precisie check — koop alleen waar ≥2 van rules {DISCOVERY} samen vuren")
    print(f"(toeval-toets: {N_PERM} schuddingen; maat = gerealiseerde profit_loss)")
    pooled_pl, pooled_k = [], []
    for sym in (2525, 244, 8427, 2735):
        pl, k = load(sym)
        pooled_pl.append(pl); pooled_k.append(k)
        report(NAME[sym], pl, k)
    report("GEPOOLD (4 munten)", np.concatenate(pooled_pl), np.concatenate(pooled_k))


if __name__ == "__main__":
    main()
