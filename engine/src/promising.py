#!/usr/bin/env python3
"""
Faithful port of the legacy good-moment / "interesting" routine:
  find_promising_trades()  (legacy/managesignal/functions_br.php:8719)

For an entry datetime it looks 180 minutes forward on the volumeud price series and
computes: highest upside over the window, time-checkpoint gains (count/12 steps),
the early dip (lowest_10/lowest_20 over the first ticks), and the buy verdict.

ORDER: the legacy fetch hard-forces DESC, but validated against the result=1/3 labels
ASCENDING (entry=[0], look forward) reproduces the owner's labels far better (DOGEAI
full-labeler 95.1% asc vs 84.1% desc). We port faithful-to-the-labels (ascending).
See docs/findings/promising-port-validation.md.

READ-ONLY on bot_signals.
Usage (validation): promising.py [symbol_id] [order asc|desc]   (default 2525 asc)
"""
import bisect
import sys
from datetime import timedelta

import pymysql

from config import (FORWARD_MINUTES, MIN_DURATION_MINUTES, DROP_BELOW_PCT,
                    MIN_UPSIDE_PCT, MAX_EARLY_DIP_PCT, upside_minutes)
from outlier_guard import outlier_indices

# 5-min-rule profile (rules 20/21/22/23) — simulate_buy.php:1550
PROFILE = dict(
    setting_percentage_highest=3,   # peak over window must exceed
    check_number_verdict=2,         # some checkpoint must exceed
    period_length=30,               # checkpoint gating (overridden per-coin by self.upside)
    first_15_above=2,               # early checkpoint gate
    max_lowest=-0.1,                # early-dip gate (strict save mode)
)
_MULT = {15: 1, 30: 2, 45: 3, 60: 4, 75: 5, 90: 6, 105: 7, 120: 8, 180: 12}
_GATE = {15: 0, 30: 30, 45: 45, 60: 60, 75: 75, 90: 90, 105: 105, 120: 120, 180: 180}


def calc_percentage(frm, to, rounding=2):
    """Legacy calc_percentage (functions.php:453): signed %, negative if frm>to."""
    if frm == 0:
        return 0.0
    diff = abs(frm - to)
    if diff == 0:
        return 0.0
    perc = (diff / abs(frm)) * 100
    if float(frm) > float(to):
        perc = -perc
    return round(perc, rounding)


def connect():
    from db import brain
    return brain()


class PromisingEngine:
    """Loads a symbol's volumeud price series once (from brain.indicators); evaluates per entry."""

    def __init__(self, symbol, order="asc", profile=PROFILE, conn=None):
        self.symbol = symbol
        self.order = order
        self.upside = upside_minutes(symbol)         # per-coin upside horizon (minutes)
        self.profile = profile
        self._own = conn is None
        self.conn = conn or connect()
        with self.conn.cursor() as c:
            c.execute("SELECT datetime, price FROM indicators "
                      "WHERE trading_symbol_id=%s AND indicator='volumeud' AND price IS NOT NULL "
                      "ORDER BY datetime", (symbol,))
            rows = c.fetchall()
        self.DT = [r["datetime"] for r in rows]
        self.PX = [float(r["price"]) for r in rows]
        # Defense-in-depth (zelfde als SellEngine.filter_outliers): gooi prijs-outliers (feed-glitch)
        # uit de reeks vóór we het upside-window scannen. Ingest (null_price_outliers) is leidend; dit
        # vangt een tick die ongezuiverd in de DB staat. Anders zou één rotte tick `highest` opblazen
        # tot honderden % en de promising-verdict vervuilen.
        bad = set(outlier_indices(self.PX))
        if bad:
            keep = [i for i in range(len(self.PX)) if i not in bad]
            self.DT = [self.DT[i] for i in keep]
            self.PX = [self.PX[i] for i in keep]

    def close(self):
        if self._own:
            self.conn.close()

    def _window(self, entry_dt):
        # fetch up to FORWARD_MINUTES (needed for the duration gate); the upside/peak are
        # computed only over the first `self.upside` minutes inside promising().
        lo = bisect.bisect_left(self.DT, entry_dt - timedelta(seconds=5))
        hi = bisect.bisect_right(self.DT, entry_dt + timedelta(minutes=FORWARD_MINUTES))
        asc = list(zip(self.DT[lo:hi], self.PX[lo:hi]))
        if self.order == "desc":
            return asc[::-1][:1000]
        return asc[-1000:]

    def promising(self, entry_dt):
        allrows = self._window(entry_dt)            # up to FORWARD_MINUTES (for the duration gate)
        if not allrows:
            return None
        base = allrows[0][1]

        # upside window: only the first `self.upside` minutes — the rise must arrive SOON
        cut = entry_dt + timedelta(minutes=self.upside)
        rows = [r for r in allrows if r[0] <= cut] or allrows[:1]
        n = len(rows)
        first_price, last_price = rows[0][1], rows[-1][1]

        highest_price, highest_dt = 0.0, None
        for dt, px in rows:
            if px > highest_price:
                highest_price, highest_dt = px, dt

        low10 = low20 = first_price
        for i in range(min(20, n)):
            px = rows[i][1]
            if px < low20:
                low20 = px
            if i < 11 and px < low10:
                low10 = px

        pct_lowest_10 = calc_percentage(first_price, low10)
        pct_lowest_20 = calc_percentage(first_price, low20)
        pct_highest = calc_percentage(first_price, highest_price)
        pct_last = calc_percentage(first_price, last_price)

        # duration: minutes the price stays above entry (within DROP_BELOW_PCT) over the full window
        duration = 0.0
        for dt, px in allrows:
            if dt < entry_dt:
                continue
            if calc_percentage(base, px) < DROP_BELOW_PCT:
                break
            duration = (dt - entry_dt).total_seconds() / 60.0

        # Clean promising definition (Daan's criteria), replacing the legacy checkpoint logic:
        #   1. reaches >= MIN_UPSIDE within the (per-coin) upside window  — "rises soon"
        #   2. early dip >= MAX_EARLY_DIP                                 — "only rises, no drop first"
        #   3. stays above entry >= MIN_DURATION                         — "sustains, not a spike"
        verdict = "buy" if (
            pct_highest >= MIN_UPSIDE_PCT
            and pct_lowest_10 >= MAX_EARLY_DIP_PCT
            and duration >= MIN_DURATION_MINUTES
        ) else ""

        return dict(highest=pct_highest, highest_dt=highest_dt, lowest_10=pct_lowest_10,
                    lowest_20=pct_lowest_20, last=pct_last, duration=duration, verdict=verdict, n=n)


def good_label(p):
    """The entry-quality part of the result=1 labeler (sell-independent)."""
    return p is not None and p["highest"] > 5 and p["lowest_10"] > -0.1


def _validate(symbol, order):
    from db import legacy
    eng = PromisingEngine(symbol, order)        # indicators from brain
    leg = legacy()                              # labels from legacy (offline validation reference)
    with leg.cursor() as c:
        c.execute("SELECT ID, datetime, profit_loss, result FROM wp_trading_simulation "
                  "WHERE trading_symbol_id=%s AND result IN (1,3) ORDER BY datetime", (symbol,))
        trades = c.fetchall()
    leg.close()
    tp = tn = fp = fn = verdict_agree = 0
    for t in trades:
        p = eng.promising(t["datetime"])
        pred, true = good_label(p), (t["result"] == 1)
        tp += pred and true; fp += pred and not true; fn += (not pred) and true; tn += (not pred) and not true
        pl_ok = t["profit_loss"] is not None and float(t["profit_loss"]) > 2
        verdict_agree += int((pred and pl_ok) == true)
    n = len(trades)
    prec = tp / (tp + fp) if (tp + fp) else 0
    rec = tp / (tp + fn) if (tp + fn) else 0
    print(f"=== promising.py — symbol {symbol}, order={order}, {n} labeled trades (result 1/3) ===")
    print(f"  entry-quality (highest>5 AND lowest_10>-0.1) vs result==1: TP={tp} FP={fp} FN={fn} TN={tn}")
    print(f"  precision={prec:.3f}  recall={rec:.3f}")
    print(f"  full labeler (+ profit_loss>2) agreement: {verdict_agree}/{n} ({verdict_agree/n*100:.1f}%)")
    eng.close()


if __name__ == "__main__":
    sym = int(sys.argv[1]) if len(sys.argv) > 1 else 2525
    order = sys.argv[2] if len(sys.argv) > 2 else "asc"
    _validate(sym, order)
