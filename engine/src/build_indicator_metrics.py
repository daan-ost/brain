#!/usr/bin/env python3
"""
Fill indicator_metrics (the calculation cache) for a coin: per (datetime, indicator, lookback)
all ~29 window calculations. Scope = every datetime inside a promising period + every trade + every
OK-marked moment (coin_moment_labels decision='yes' — the owner's confirmed good entries).
Reads ONLY brain (indicators + coin_periods + coin_fires + coin_moment_labels + coin_rule_settings); writes the brain
table AND a Parquet mirror (engine/data/metrics/). Idempotent per symbol.

Usage: build_indicator_metrics.py [symbol_id ...]   (default: 2525 244)
"""
import bisect
import os
import sys

import duckdb
import pandas as pd

from db import brain
from calc import window_metrics, WINDOW_METRIC_KEYS

import hashlib

FORCE = "--force" in sys.argv                          # overrule skip-on-unchanged (Fase 1)
SYMS = [int(a) for a in sys.argv[1:] if not a.startswith("--")] or [2525, 244]
INDICATORS = ["vzo", "phobos", "obv-x-value", "mfi", "volumeud"]
MAX_LB = 20
COLS = ["trading_symbol_id", "symbol", "datetime", "indicator", "lookback", *WINDOW_METRIC_KEYS]
HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, "..", "data", "metrics")
os.makedirs(OUT, exist_ok=True)

conn = brain()


def q(sql, args=()):
    with conn.cursor() as c:
        c.execute(sql, args); return c.fetchall()


# Fase 1 (schaalplan, docs/findings/optimize-scaling-plan-2026-06-25.md): skip-on-unchanged. De cache
# is rule/uitkomst-ONAFHANKELIJK — alleen de SCOPE-drivers bepalen welke (datetime,indicator,lookback)
# berekend worden: de indicators-reeks (regel 46-50), de coin_fires-datetimes (58), de coin_periods-
# vensters (59-61), de ok-label-momenten (62-65) en min_volume (49, herschaalt volumeud). Plus een
# code-versie-hash zodat een wijziging in INDICATORS/MAX_LB/WINDOW_METRIC_KEYS herbouw triggert. Gelijke
# fingerprint + bestaand parquet + geen --force → skip (geen window_metrics, geen DELETE/INSERT).
q("CREATE TABLE IF NOT EXISTS indicator_metrics_state ("
  "trading_symbol_id INT UNSIGNED NOT NULL PRIMARY KEY, fingerprint VARCHAR(64) NOT NULL, "
  "row_count INT UNSIGNED NULL, built_at DATETIME NOT NULL, "
  "created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, "
  "updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)")
_CODE_VER = hashlib.md5((",".join(INDICATORS) + f"|{MAX_LB}|" + ",".join(WINDOW_METRIC_KEYS)).encode()).hexdigest()[:10]


def _metrics_fingerprint(SYM):
    p = []
    r = q("SELECT COUNT(*) n, COALESCE(MAX(datetime),'') mx FROM indicators WHERE trading_symbol_id=%s", (SYM,))[0]
    p.append(f"ind:{r['n']}:{r['mx']}")
    # coin_fires-SCOPE = de set datetimes (incl. shadows; geen is_executed-filter op regel 58). SUM(CRC32)
    # vangt een gewijzigde datetime-set; profit_loss telt NIET mee (de metrics zijn uitkomst-onafhankelijk).
    r = q("SELECT COUNT(*) n, COALESCE(SUM(CRC32(datetime)),0) cx FROM coin_fires WHERE trading_symbol_id=%s", (SYM,))[0]
    p.append(f"fires:{r['n']}:{r['cx']}")
    r = q("SELECT COUNT(*) n, COALESCE(MIN(period_from),'') pf, COALESCE(MAX(period_to),'') pt FROM coin_periods WHERE trading_symbol_id=%s", (SYM,))[0]
    p.append(f"per:{r['n']}:{r['pf']}:{r['pt']}")
    r = q("SELECT COUNT(*) n, COALESCE(MAX(datetime),'') mx FROM coin_moment_labels WHERE trading_symbol_id=%s AND decision='yes'", (SYM,))[0]
    p.append(f"lbl:{r['n']}:{r['mx']}")
    r = q("SELECT COALESCE(MIN(min_volume),'') mv FROM coin_rule_settings WHERE trading_symbol_id=%s AND min_volume IS NOT NULL", (SYM,))[0]
    p.append(f"mv:{r['mv']}")
    p.append(f"code:{_CODE_VER}")
    return hashlib.md5("|".join(p).encode()).hexdigest()


for SYM in SYMS:
    row = q("SELECT symbol FROM coins WHERE id=%s", (SYM,))
    SYMBOL = row[0]["symbol"] if row else str(SYM)

    # Fase 1: skip als niets in de scope is gewijzigd én het parquet er nog is.
    fp_now = _metrics_fingerprint(SYM)
    fpath = os.path.join(OUT, f"indicator_metrics_{SYM}.parquet")
    prev = q("SELECT fingerprint FROM indicator_metrics_state WHERE trading_symbol_id=%s", (SYM,))
    if not FORCE and prev and prev[0]["fingerprint"] == fp_now and os.path.exists(fpath):
        print(f"{SYMBOL} ({SYM}): scope ongewijzigd ({fp_now[:10]}…) — cache overgeslagen. Forceer met --force.")
        continue

    mv = q("SELECT min_volume FROM coin_rule_settings WHERE trading_symbol_id=%s AND min_volume IS NOT NULL "
           "ORDER BY min_volume LIMIT 1", (SYM,))
    VOL_BASE = float(mv[0]["min_volume"]) if mv else 1.0

    # per-indicator as-of series (volumeud normalised to relative volume)
    series = {}
    for r in q("SELECT indicator, datetime, value FROM indicators WHERE trading_symbol_id=%s AND value IS NOT NULL "
               "ORDER BY datetime", (SYM,)):
        s = series.setdefault(r["indicator"], {"dt": [], "v": []})
        v = float(r["value"]) / VOL_BASE if r["indicator"] == "volumeud" else float(r["value"])
        s["dt"].append(r["datetime"]); s["v"].append(v)

    vdt = series.get("volumeud", {}).get("dt", [])

    # in-scope datetimes: every volumeud dt inside a promising period + every trade + every OK-marked
    # moment (coin_moment_labels decision='yes' — the owner's confirmed good entries, see
    # brain-promising-labeler). We always compute laag 2 for the ok-moments too, even if they fall
    # outside a promising period; snap to the nearest volumeud tick at/<= the label datetime.
    dts = set(r["datetime"] for r in q("SELECT datetime FROM coin_fires WHERE trading_symbol_id=%s", (SYM,)))
    for p in q("SELECT period_from, period_to FROM coin_periods WHERE trading_symbol_id=%s", (SYM,)):
        lo = bisect.bisect_left(vdt, p["period_from"]); hi = bisect.bisect_right(vdt, p["period_to"])
        dts.update(vdt[lo:hi])
    for r in q("SELECT datetime FROM coin_moment_labels WHERE trading_symbol_id=%s AND decision='yes'", (SYM,)):
        i = bisect.bisect_right(vdt, r["datetime"])
        if i > 0:
            dts.add(vdt[i - 1])                 # the volumeud tick at/just before the labeled moment
    dts = sorted(dts)

    def asof(ind, T, n):
        s = series.get(ind)
        if not s:
            return []
        i = bisect.bisect_right(s["dt"], T)
        return s["v"][max(0, i - n):i][::-1]      # newest-first

    rows = []
    for T in dts:
        for ind in INDICATORS:
            w = asof(ind, T, MAX_LB)
            if not w:
                continue
            for n in range(1, MAX_LB + 1):
                m = window_metrics(w[:n])
                if not m:
                    continue
                rows.append((SYM, SYMBOL, T, ind, n, *[m.get(k) for k in WINDOW_METRIC_KEYS]))

    df = pd.DataFrame(rows, columns=COLS)
    # write brain (idempotent) — bulk insert
    with conn.cursor() as c:
        c.execute("DELETE FROM indicator_metrics WHERE trading_symbol_id=%s", (SYM,))
        ph = ",".join(["%s"] * len(COLS))
        ins = f"INSERT INTO indicator_metrics ({','.join('`'+c2+'`' for c2 in COLS)}) VALUES ({ph})"
        c.executemany(ins, rows)
    conn.commit()
    # write Parquet mirror
    duckdb.sql("COPY df TO '%s' (FORMAT PARQUET)" % fpath)
    # Fase 1: leg de scope-fingerprint vast — de volgende run skipt als niets in de scope wijzigt.
    q("INSERT INTO indicator_metrics_state (trading_symbol_id, fingerprint, row_count, built_at) "
      "VALUES (%s,%s,%s,NOW()) ON DUPLICATE KEY UPDATE "
      "fingerprint=VALUES(fingerprint), row_count=VALUES(row_count), built_at=VALUES(built_at)",
      (SYM, fp_now, len(rows)))
    conn.commit()
    print(f"{SYMBOL} ({SYM}): {len(dts)} datetimes -> {len(rows):,} rows (brain + {os.path.relpath(fpath, HERE)})")

conn.close()
