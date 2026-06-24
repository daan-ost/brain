#!/usr/bin/env python3
"""
whitespace.py — rangschik de segment-catalogus op WITTE PROMISING-GROEPEN (Daans criterium).

Daans vraag: van de ~34 segmenten (pysubgroup, doel=promising), focus op het segment dat de meeste
promising-groepen dekt WAAR NOG GEEN LIVE TRADE OP ZIT. Een promising-groep = een handmatig gemarkeerde
goede instap-periode (rises); "bedekt" = er valt al minstens één live executed trade (rule 20-30) in het
groep-venster. "Wit" = nog door geen enkele rule gepakt → daar zit de nog-niet-gerealiseerde dekking.

Per segment tonen we:
  - wit      : # promising-groepen dat het segment dekt EN nog geen 20-30-trade heeft  (PRIMAIRE sortering)
  - dekt     : # promising-groepen dat het segment in totaal dekt
  - prec%    : % promising onder de matches (hoe "schoon" het segment is)
  - sel%     : % van ALLE ticks waarop het vuurt (selectiviteit; lager = scherper)
  - Σpl_wit  : som GEREALISEERDE profit_loss (sell-engine) van de beste tick per witte groep — wat je er
               realistisch uit zou halen (NOOIT best_upside; alleen gerealiseerd, conform afspraak)

ALLEEN-LEZEN. Schrijft niets. Draaien (vanuit engine/src):
    python -m discovery.whitespace            # DOGEAI + NOS
    python -m discovery.whitespace 2525 DOGEAI
"""
import os
os.environ.setdefault("NUMBA_DISABLE_JIT", "1")

import sys

import numpy as np

from db import brain
from discovery.data import build_matrix, COINS
from discovery.segment import discover, fmt_subrules


def live_trade_times(symbol, rules=(20, 21, 22, 23, 30)):
    """Datetimes van de live EXECUTED trades (de opgegeven rules) — de 'bezette' momenten."""
    ph = ",".join(["%s"] * len(rules))
    with brain().cursor() as c:
        c.execute(f"SELECT datetime FROM coin_fires WHERE trading_symbol_id=%s AND is_executed=1 "
                  f"AND rule IN ({ph}) ORDER BY datetime", (symbol, *rules))
        return [r["datetime"] for r in c.fetchall()]


def best_realized_pl(dd, gi):
    """Beste GEREALISEERDE sell-engine pl over de ticks van groep gi (rule-20 sell-gedrag)."""
    g = dd.groups[gi]
    best = None
    for t in g:
        i = __import__("bisect").bisect_right(dd.A.vdt, t)
        buy = dd.A.vpx[i - 1] if i > 0 else None
        if not buy or buy <= 0:
            continue
        r = dd.eng.sell(t, buy, 20)
        if r is not None and abs(r["profit_loss"]) <= 200:
            pl = float(r["profit_loss"])
            best = pl if best is None or pl > best else best
    return best if best is not None else 0.0


def analyse(symbol, name):
    dd = build_matrix(symbol, name)
    cat = discover(dd, target="promising")

    # reeds bedekte promising-groepen = groepen met >=1 live 20-30-trade in hun venster
    covered = dd.groups_hit(live_trade_times(symbol))
    n_groups = len(dd.groups)
    n_white_total = n_groups - len(covered)

    # per segment: welke groepen dekt het, en hoeveel daarvan zijn nog wit
    rows = []
    for s in cat:
        hit = dd.groups_hit(dd.survivors(s["subrules"]))
        white = hit - covered
        sig_white = sum(best_realized_pl(dd, gi) for gi in white)
        rows.append(dict(seg=s, wit=len(white), dekt=len(hit), white_set=white,
                         prec=s["precision"], sel=s["selectivity"], sig=sig_white))
    rows.sort(key=lambda r: (-r["wit"], -r["sig"]))

    print(f"\n{'='*92}\n  {name}: {len(cat)} segmenten | {n_groups} promising-groepen, "
          f"{len(covered)} al bedekt door 20-30, {n_white_total} nog WIT\n{'='*92}")
    print(f"  {'wit':>4s} {'dekt':>5s} {'prec%':>6s} {'sel%':>7s} {'Σpl_wit':>8s}  segment")
    for r in rows[:12]:
        print(f"  {r['wit']:4d} {r['dekt']:5d} {100*r['prec']:5.0f}% {100*r['sel']:7.3f} "
              f"{r['sig']:+8.0f}  {fmt_subrules(r['seg']['subrules'])}")
    return dd, rows, covered


def main():
    syms = COINS
    if len(sys.argv) > 1:
        syms = [(int(sys.argv[1]), sys.argv[2] if len(sys.argv) > 2 else sys.argv[1])]
    for sym, nm in syms:
        analyse(sym, nm)


if __name__ == "__main__":
    main()
