#!/usr/bin/env python3
"""
Runs the sell-engine over EVERY promising moment (max60 >= 3% AND early-dip >= -0.5% — the labeler's
PromisingLabeler::isPromising definition) "as if bought there", and stores the outcome in
brain.coin_moment_sells so the Promising labeler can show the realised P&L next to the buy-quality for
ANY promising moment, not just the rule-fires.

Default = DRY-RUN (counts + a few samples, writes NOTHING). Pass --run to compute + write.
NOTE: the per-moment SL rule is a placeholder (the fire's rule at that datetime, else 20) — refine with
the per-coin/per-rule tuning routine. The promising-scan is O(n*window); add a suffix-max before a
full-history run. Keep PROM_REACH/PROM_DIP in sync with PromisingLabeler.

Usage: sell_promising.py <symbol_id> [--run]
"""
import bisect
import datetime as _dt
import sys

from config import FORWARD_MINUTES
from db import brain
from sell_engine import SellEngine

PROM_REACH = 3.0       # == PromisingLabeler::PROM_REACH
PROM_DIP = -0.5        # == PromisingLabeler::PROM_DIP
DEFAULT_RULE = 20

args = [a for a in sys.argv[1:] if not a.startswith("--")]
SYM = int(args[0]) if args else 2525
RUN = "--run" in sys.argv

sell_eng = SellEngine(SYM)
DT, PX = sell_eng.DT, sell_eng.PX
n = len(DT)

dst = brain()
with dst.cursor() as c:
    c.execute("SELECT datetime, rule, symbol FROM coin_fires WHERE trading_symbol_id=%s", (SYM,))
    fires = c.fetchall()
    # Handmatig ok-gemarkeerde momenten ALTIJD meenemen, ongeacht de promising-criteria.
    # Anders mist een ok-moment dat onder PROM_REACH (3%) of vroeger-dan PROM_DIP (-0.5%) ligt
    # een sell-resultaat in het detailscherm. Zie integrity check_sell_coverage.
    c.execute("SELECT datetime FROM coin_moment_labels "
              "WHERE trading_symbol_id=%s AND decision='yes' AND source='manual'", (SYM,))
    ok_dts = {r["datetime"] for r in c.fetchall()}
rule_at = {r["datetime"]: r["rule"] for r in fires}
SYMBOL = fires[0]["symbol"] if fires else str(SYM)
dt_idx = {d: i for i, d in enumerate(DT)}


def metrics(i):
    """buy, max60%, early-dip% (first ~10 ticks) — the labeler's promising inputs."""
    buy = PX[i]
    hi = bisect.bisect_right(DT, DT[i] + _dt.timedelta(minutes=FORWARD_MINUTES))
    seg = PX[i:hi] or [buy]
    low = min(PX[i:i + 10] or [buy])
    return buy, (max(seg) - buy) / buy * 100, (low - buy) / buy * 100


promising = [i for i in range(n) if (lambda b, mx, dip: mx >= PROM_REACH and dip >= PROM_DIP)(*metrics(i))]
# Voeg de ok-momenten toe die niet al in `promising` zitten (uniek + gesorteerd).
ok_extra = sorted({dt_idx[d] for d in ok_dts if d in dt_idx} - set(promising))
all_idx = sorted(set(promising) | set(ok_extra))
print(f"=== sell_promising — {SYMBOL} ({SYM}): {len(promising)} promising + {len(ok_extra)} ok-extra "
      f"= {len(all_idx)} momenten ===")

if not RUN:
    print("DRY-RUN (niets geschreven). Voorbeelden:")
    for i in all_idx[:5]:
        buy, mx, dip = metrics(i)
        print(f"  {DT[i]}  buy={buy}  max60={mx:.2f}%  dip={dip:.2f}%  rule={rule_at.get(DT[i], DEFAULT_RULE)}")
    print("Pas --run toe om te berekenen + schrijven — NA verbetering van de sell-engine (Epic S).")
    sell_eng.close(); dst.close(); sys.exit(0)

# --run: bereken de sell per promising moment + schrijf naar coin_moment_sells (idempotent: rebuild)
dst.autocommit(False)
written = 0
with dst.cursor() as c:
    c.execute("DELETE FROM coin_moment_sells WHERE trading_symbol_id=%s", (SYM,))
    for i in all_idx:
        buy = PX[i]
        s = sell_eng.sell(DT[i], buy, rule_at.get(DT[i], DEFAULT_RULE))
        if not s:
            continue
        minutes = int((s["selling_date"] - DT[i]).total_seconds() / 60) if s.get("selling_date") else None
        c.execute(
            "INSERT INTO coin_moment_sells (trading_symbol_id, symbol, datetime, buy_price, selling_price, "
            "selling_datetime, profit_loss, hi_pl, lo_pl, best_sell_price, best_sell_datetime, minutes_in_trade, "
            "sell_version, computed_at, created_at, updated_at) "
            "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,NOW(),NOW(),NOW())",
            (SYM, SYMBOL, DT[i], buy, s["selling_price"], s["selling_date"], s["profit_loss"],
             s["hi"], s["lo"], s.get("hi_price"), s.get("hi_dt"), minutes, "v1"))
        written += 1
dst.commit()
print(f"  geschreven: {written} sell-resultaten naar coin_moment_sells")
sell_eng.close(); dst.close()
