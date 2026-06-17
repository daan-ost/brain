#!/usr/bin/env python3
"""
Rebuild a coin's fires + promising periods entirely in brain (fire-rebuild A). Reads ONLY brain
(indicators + rules); legacy is touched solely to attach the legacy result as an offline
comparison reference.

Fires = OUR rule_engine over brain.indicators (all moments the rules fire). Position dedup:
one trade at a time — the first fire opens a position (until OUR sell-engine exits, max 1h);
fires during that window are shadows (not executed). Per-fire good/bad = OUR promising verdict.

Idempotent: clears the symbol's rows first. Writes coin_periods + coin_fires.
Usage: persist_to_brain.py [symbol_id] [from] [to] [gap_minutes]
"""
import bisect
import datetime as _dt
import json
import os
import sys

from align import align_legacy_dt
from config import CLUSTER_GAP_MINUTES, FORWARD_MINUTES
from db import brain, legacy
from promising import PromisingEngine
from cluster_promising import scan_periods, best_entry
from rule_engine import RuleEngine, RULES
from sell_engine import SellEngine
from buy_confirm import confirm_buy, params_by_rule

# Multi-horizon upside checkpoints (minutes) shown per buy-moment in the Promising labeler.
HORIZONS = [5, 10, 15, 30, 45, 60]

SYM = int(sys.argv[1]) if len(sys.argv) > 1 else 2525
FROM = sys.argv[2] if len(sys.argv) > 2 else None
TO = sys.argv[3] if len(sys.argv) > 3 else None
GAP = int(sys.argv[4]) if len(sys.argv) > 4 else CLUSTER_GAP_MINUTES
# Changelog-reden voor klasse-overgangen door deze refire. Default = gewone heranalyse; de sell-tuning
# routine zet CHANGELOG_REASON='tuning-routine-<rule>-<knob>' zodat de wijziging te herleiden is.
CHANGELOG_REASON = (os.environ.get("CHANGELOG_REASON") or "sell-engine-rerun")[:80]
_pf = lambda s: _dt.datetime.strptime(s, "%Y-%m-%d %H:%M:%S") if s and len(s) > 10 else (_dt.datetime.strptime(s, "%Y-%m-%d") if s else None)
FROM_dt, TO_dt = _pf(FROM), _pf(TO)

dst = brain()
dst.autocommit(False)
with dst.cursor() as c:
    c.execute("SELECT symbol FROM coins WHERE id=%s", (SYM,))
    row = c.fetchone()
SYMBOL = row["symbol"] if row else str(SYM)
LABEL = f"promising_v2_gap{GAP}"

# Snapshot vorige profit_loss voordat we de tabel legen — nodig voor de changelog.
with dst.cursor() as c:
    c.execute("SELECT datetime, profit_loss FROM coin_fires WHERE trading_symbol_id=%s AND is_executed=1", (SYM,))
    prev_pl = {r["datetime"]: r["profit_loss"] for r in c.fetchall()}

# ---------------- promising periods (brain) ----------------
eng = PromisingEngine(SYM, "asc")
periods, _, _ = scan_periods(eng, FROM, TO, GAP)

with dst.cursor() as c:
    c.execute("DELETE FROM coin_fires WHERE trading_symbol_id=%s", (SYM,))
    c.execute("DELETE FROM coin_periods WHERE trading_symbol_id=%s", (SYM,))

spans = []
with dst.cursor() as c:
    for per in periods:
        be = best_entry(per)
        c.execute(
            "INSERT INTO coin_periods (trading_symbol_id, symbol, period_from, period_to, best_entry, "
            "best_upside, best_lowest10, peak_datetime, n_moments, gap_minutes, label_version, created_at, updated_at) "
            "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,NOW(),NOW())",
            (SYM, SYMBOL, per[0][0], per[-1][0], be[0], round(be[1], 3), round(be[2], 3), be[3], len(per), GAP, LABEL))
        spans.append((per[0][0], per[-1][0], c.lastrowid))


def period_of(dt):
    for a, b, pid in spans:
        if a <= dt <= b:
            return pid
    return None


# ---------------- our fires (brain rules over brain indicators) ----------------
rule_eng = RuleEngine(SYM)
sell_eng = SellEngine(SYM)
DT, PX = sell_eng.DT, sell_eng.PX


def price_at(dt):
    i = bisect.bisect_right(DT, dt)
    return PX[i - 1] if i > 0 else None


def best_upside_at(dt, buy):
    """Max favorable excursion (%) within the hold [dt, dt+FORWARD_MINUTES] vs buy — the best
    price you could have sold at. This is the trade's quality, independent of our sell-engine."""
    if not buy:
        return None
    lo = bisect.bisect_left(DT, dt)
    hi = bisect.bisect_right(DT, dt + _dt.timedelta(minutes=FORWARD_MINUTES))
    if lo >= hi:
        return None
    mx = max(PX[lo:hi])
    return round((mx - buy) / buy * 100, 3)


def horizons_at(dt, buy):
    """Per-horizon upside (buy-moment quality, sell-INdependent): for each HORIZON, the max
    favorable excursion within [dt, dt+h min] vs buy, plus the peak price + time inside that
    window. Also returns lowest10 = the early dip (%) over the first ~10 ticks. TRUE time windows
    (not the legacy count/12 index fractions)."""
    if not buy:
        return None, None
    lo = bisect.bisect_left(DT, dt)
    out = {}
    for h in HORIZONS:
        hi = bisect.bisect_right(DT, dt + _dt.timedelta(minutes=h))
        if lo >= hi:
            continue                                     # geen forward-data: horizon weglaten (geen null-key)
        seg = PX[lo:hi]
        mx = max(seg)
        k = lo + seg.index(mx)                       # first datetime the peak is reached
        out[str(h)] = {"up": round((mx - buy) / buy * 100, 3),
                       "peak_px": mx,
                       "peak_at": DT[k].strftime("%Y-%m-%d %H:%M:%S")}
    early = PX[lo:lo + 10] or [buy]                  # first ~10 ticks (volumeud is event-driven)
    lowest10 = round((min(early) - buy) / buy * 100, 3)
    return out, lowest10


all_fires = []
for rule in RULES:
    for dt in rule_eng.fires(rule, FROM_dt, TO_dt):
        all_fires.append((dt, rule))
all_fires.sort()

# legacy reference (offline): (rule, datetime) -> result, profit_loss
leg = legacy()
with leg.cursor() as c:
    c.execute("SELECT datetime, rule, result, profit_loss FROM wp_trading_simulation "
              "WHERE trading_symbol_id=%s AND rule IN (20,21,22,23)", (SYM,))
    # legacy buys = signal tick + 5s (live wait) — subtract it + snap to our grid (DT) so the join hits
    legmap = {(r["rule"], align_legacy_dt(r["datetime"], DT)): r for r in c.fetchall()}
leg.close()

# Handmatige overrides per (trade-datetime): harde verkoopdatum + handmatige klasse-stand.
# - hard_sell_dt: sell-engine respecteert dit (verkoop op die datum of eerder).
# prev_pl staat al hierboven; we gebruiken het samen met de nieuwe profit voor de changelog.
with dst.cursor() as c:
    c.execute("SELECT datetime, hard_sell_datetime, manual_set_at, manual_klasse FROM coin_moment_labels "
              "WHERE trading_symbol_id=%s AND source='manual'", (SYM,))
    overrides = {r["datetime"]: r for r in c.fetchall()}


def klasse_of(pl):
    if pl is None: return None
    return "slecht" if pl < 0 else ("middel" if pl < 3 else "goed")

# greedy single-position dedup + koop-bevestiging + our sell-engine P&L
fp_params = params_by_rule(dst)                  # futureprice/x_rows per rule (koop-bevestiging)
open_until = open_at = None
n_exec = n_shadow = n_good = n_cancelled = 0
with dst.cursor() as c:
    for idx, (dt, rule) in enumerate(all_fires):
        signal_price = price_at(dt)
        legrow = legmap.get((rule, dt))
        legres = legrow["result"] if legrow else None
        legpl = legrow["profit_loss"] if legrow else None

        if open_until is not None and dt <= open_until:
            # SHADOW — signaal tijdens een open positie (telt niet als trade). Instap = signaalmoment.
            executed, shadow_parent, sell = 0, open_at, None
            buy_dt, buy = dt, signal_price
            n_shadow += 1
        else:
            # KOOP-BEVESTIGING (futureprice): het signaal vuurde, maar live wacht de bot ≤window min.
            # Pas als de prijs binnen dat venster weer BOVEN de signaalprijs komt (de "kruising") is de
            # trade bevestigd. Komt hij er niet boven (of zakt eerst onder b_min / te snel via x_rows) →
            # afgeblazen: nooit echt gekocht, geen positie, niet opslaan. De kruising is alléén de TRIGGER;
            # de instap is de SIGNAALprijs/-tijd (zo doet legacy het ook). Rules zonder futureprice (bv. 21)
            # kopen direct op het signaal.
            fp = fp_params.get(rule)
            confirmed = signal_price is not None
            if fp and signal_price:
                confirmed = confirm_buy(DT, PX, dt, signal_price, fp["bmin"], fp["window"], fp["xrows"]) is not None
            if not confirmed:
                n_cancelled += 1
                continue
            buy_dt, buy = dt, signal_price            # instap op de signaalprijs/-tijd (kruising = enkel trigger)
            ov = overrides.get(dt) or {}
            hard_dt = ov.get("hard_sell_datetime")
            sell = sell_eng.sell(dt, signal_price, rule, hard_sell_dt=hard_dt)
            open_until = sell["selling_date"] if sell else dt
            open_at = dt
            executed, shadow_parent = 1, None
            n_exec += 1
            # Log klasse-verandering door de heranalyse (slecht->goed etc.). Handmatige klasse
            # is leidend — die overschrijft de berekende, dus alleen de berekende loggen we hier.
            new_pl = sell["profit_loss"] if sell else None
            old_pl = float(prev_pl[dt]) if dt in prev_pl and prev_pl[dt] is not None else None
            old_k, new_k = klasse_of(old_pl), klasse_of(new_pl)
            if old_k is not None and new_k is not None and old_k != new_k:
                with dst.cursor() as cc:
                    cc.execute("INSERT INTO coin_fires_changelog (trading_symbol_id, symbol, datetime, "
                               "field, old_value, new_value, reason, created_at, updated_at) "
                               "VALUES (%s,%s,%s,'klasse',%s,%s,%s,NOW(),NOW())",
                               (SYM, SYMBOL, dt, old_k, new_k, CHANGELOG_REASON))

        pr = eng.promising(dt)
        good = 1 if (pr and pr["verdict"] == "buy") else 0
        n_good += good
        # best_sell = de hoogste prijs BINNEN onze hold [koop, onze verkoop]. Komt uit de sell-engine
        # (hi_price/hi_dt). best_upside/horizons meten vanaf de ECHTE instap (kruisprijs bij executed,
        # signaalprijs bij shadow). Voor shadows leeg (geen eigen sell).
        best_for_trade = {"price": sell["hi_price"], "datetime": sell["hi_dt"]} if sell else None
        best_up = best_upside_at(buy_dt, buy)          # trade quality vanaf de instap
        hz, low10 = horizons_at(buy_dt, buy)           # per-horizon upside + early dip vanaf de instap
        c.execute(
            "INSERT INTO coin_fires (trading_symbol_id, symbol, datetime, rule, in_good_period, is_executed, "
            "shadow_parent, period_id, buy_price, selling_price, best_sell_price, best_sell_datetime, "
            "best_upside, horizons, lowest10, selling_datetime, profit_loss, "
            "legacy_result, legacy_profit_loss, created_at, updated_at) "
            "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,NOW(),NOW())",
            (SYM, SYMBOL, dt, rule, good, executed, shadow_parent, period_of(dt),
             buy, sell["selling_price"] if sell else None,
             best_for_trade["price"] if best_for_trade else None,
             best_for_trade["datetime"] if best_for_trade else None,
             best_up, json.dumps(hz) if hz else None, low10,
             sell["selling_date"] if sell else None,
             sell["profit_loss"] if sell else None,
             int(legres) if legres is not None else None,
             float(legpl) if legpl is not None else None))

dst.commit()
print(f"=== persist_to_brain (rebuild A) — {SYMBOL} ({SYM}) ===")
print(f"periods: {len(periods)}  |  fires: {len(all_fires)}  "
      f"({n_exec} executed / {n_shadow} shadow / {n_cancelled} afgeblazen door koop-bevestiging)  "
      f"|  in promising: {n_good}")
eng.close(); rule_eng.close(); sell_eng.close(); dst.close()
