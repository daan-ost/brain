#!/usr/bin/env python3
"""
Orakeltest voor de loosen-cache (Epic J). Bewijst:
 1. build_sole + proposals_from_sole == analyse_symbol (de split is correct)
 2. loosen_fingerprint is invariant onder sell-changes, verandert bij rule/coin_periods-changes
 3. cached_build_sole: cold == warm == direct (bit-identieke voorstellen)
 4. incrementeel == volledig (met T_safe-grens)
"""
import json
import os
import shutil
import sys
from collections import defaultdict
from datetime import datetime, timedelta

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import opt_lib as o
from db import brain
from opt_diag import DiagEngine, promising_verdicts
from rq2_earlier import build_sole, proposals_from_sole, analyse_symbol, bad_edge_loosen
import loosen_cache
import fires_cache

PASSED = 0
FAILED = 0


def ok(test, cond, detail=""):
    global PASSED, FAILED
    if cond:
        PASSED += 1
        print(f"  PASS  {test}")
    else:
        FAILED += 1
        print(f"  FAIL  {test}  {detail}")


def proposals_key(r):
    return (r["sym"], r["subrule_index"], r["loosen_bound"], r["new_threshold"],
            r["admitted_good"], r["new_bad"])


# ──────────────────────── Test 1: split correctheid ────────────────────────

def test_split():
    """build_sole + proposals_from_sole geeft dezelfde voorstellen als analyse_symbol.
    Draait op 1 munt × 1 rule (de scan is duur, ~2 min per combinatie)."""
    print("\n=== Test 1: split correctheid ===")
    sym = o.optimize_coin_ids()[0]
    rule = 21

    direct = analyse_symbol(sym, rule)
    direct_keys = {proposals_key(r) for r in direct}

    eng = DiagEngine(sym)
    periods, _ = promising_verdicts(sym)
    spans = [(p["period_from"], p["period_to"], p["id"]) for p in periods]

    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT datetime, period_id, best_upside FROM coin_fires "
                  "WHERE trading_symbol_id=%s AND rule=%s AND is_executed=1", (sym, rule))
        exec_fires = c.fetchall()
    conn.close()
    fires_by_period = defaultdict(list)
    for r in exec_fires:
        fires_by_period[r["period_id"]].append((r["datetime"], r["best_upside"]))

    sole = build_sole(sym, rule, eng, spans)
    subs = eng.rules[rule]
    eng.close()
    split_results = proposals_from_sole(sym, rule, sole, subs, fires_by_period)
    split_keys = {proposals_key(r) for r in split_results}

    ok(f"rule {rule} sym {sym}: {len(direct)} voorstellen, split == direct",
       direct_keys == split_keys,
       f"direct={direct_keys} split={split_keys}")


# ──────────────────────── Test 2: fingerprint-invariantie ────────────────────────

def test_fingerprint():
    """Sell-change → fingerprint ongewijzigd; rule/coin_periods-change → veranderd."""
    print("\n=== Test 2: fingerprint-invariantie ===")
    sym = o.optimize_coin_ids()[0]
    rule = 21

    fp_base = loosen_cache.loosen_fingerprint(sym, rule)
    ok("fingerprint is een 16-char hex string", len(fp_base) == 16 and all(c in "0123456789abcdef" for c in fp_base))

    fp_again = loosen_cache.loosen_fingerprint(sym, rule)
    ok("fingerprint stabiel (2× zelfde)", fp_base == fp_again)

    # sell-change: wijzig coin_strategies.sl_settings tijdelijk
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT id, sl_settings FROM coin_strategies WHERE trading_symbol_id=%s AND rule_number=%s LIMIT 1",
                  (sym, rule))
        row = c.fetchone()
    if row:
        orig_sl = row["sl_settings"]
        with conn.cursor() as c:
            c.execute("UPDATE coin_strategies SET sl_settings='TEST_CHANGE' WHERE id=%s", (row["id"],))
        conn.commit()
        fp_sell = loosen_cache.loosen_fingerprint(sym, rule)
        ok("sell-change → fingerprint ONGEWIJZIGD", fp_sell == fp_base)
        with conn.cursor() as c:
            c.execute("UPDATE coin_strategies SET sl_settings=%s WHERE id=%s", (orig_sl, row["id"]))
        conn.commit()
    else:
        ok("sell-change → fingerprint ONGEWIJZIGD (geen coin_strategies rij, skip)", True)
    conn.close()

    # rule-def-change: wijzig b_min van een subrule tijdelijk
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT id, b_min FROM rules WHERE rule_number=%s AND active=1 LIMIT 1", (rule,))
        rrow = c.fetchone()
    if rrow:
        orig_bmin = rrow["b_min"]
        with conn.cursor() as c:
            c.execute("UPDATE rules SET b_min=b_min+0.001 WHERE id=%s", (rrow["id"],))
        conn.commit()
        fp_rule = loosen_cache.loosen_fingerprint(sym, rule)
        ok("rule-def-change → fingerprint VERANDERD", fp_rule != fp_base, f"was {fp_base}, now {fp_rule}")
        with conn.cursor() as c:
            c.execute("UPDATE rules SET b_min=%s WHERE id=%s", (orig_bmin, rrow["id"]))
        conn.commit()
    conn.close()

    # coin_periods-change: voeg een tijdelijke dummy-period toe
    conn = brain()
    with conn.cursor() as c:
        c.execute("INSERT INTO coin_periods (trading_symbol_id, period_from, period_to, best_entry, best_upside) "
                  "VALUES (%s, '2099-01-01', '2099-01-02', '2099-01-01', 0)", (sym,))
        dummy_id = c.lastrowid
    conn.commit()
    fp_cp = loosen_cache.loosen_fingerprint(sym, rule)
    ok("coin_periods-change → fingerprint VERANDERD", fp_cp != fp_base, f"was {fp_base}, now {fp_cp}")
    with conn.cursor() as c:
        c.execute("DELETE FROM coin_periods WHERE id=%s", (dummy_id,))
    conn.commit()
    conn.close()


# ──────────────────────── Test 3: cold == warm == direct ────────────────────────

def test_cache_hit():
    """cached_build_sole: koude run == warme run == directe build_sole, bit-identieke voorstellen."""
    print("\n=== Test 3: cold == warm == direct ===")
    sym = o.optimize_coin_ids()[0]
    rule = 21

    # opruimen
    if os.path.isdir(loosen_cache.LOOSEN_DIR):
        shutil.rmtree(loosen_cache.LOOSEN_DIR)

    eng = DiagEngine(sym)
    periods, _ = promising_verdicts(sym)
    spans = [(p["period_from"], p["period_to"], p["id"]) for p in periods]

    # directe build_sole (referentie)
    sole_direct = build_sole(sym, rule, eng, spans)

    # cold
    eng2 = DiagEngine(sym)
    sole_cold, was_cached = loosen_cache.cached_build_sole(sym, rule, eng2, spans)
    eng2.close()
    ok("cold: was_cached=False", not was_cached)

    # warm
    eng3 = DiagEngine(sym)
    sole_warm, was_cached = loosen_cache.cached_build_sole(sym, rule, eng3, spans)
    eng3.close()
    ok("warm: was_cached=True", was_cached)

    # vergelijk voorstellen
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT datetime, period_id, best_upside FROM coin_fires "
                  "WHERE trading_symbol_id=%s AND rule=%s AND is_executed=1", (sym, rule))
        exec_fires = c.fetchall()
    conn.close()
    fires_by_period = defaultdict(list)
    for r in exec_fires:
        fires_by_period[r["period_id"]].append((r["datetime"], r["best_upside"]))

    subs = eng.rules[rule]
    eng.close()

    props_direct = sorted(proposals_from_sole(sym, rule, sole_direct, subs, fires_by_period),
                          key=lambda r: proposals_key(r))
    props_cold = sorted(proposals_from_sole(sym, rule, sole_cold, subs, fires_by_period),
                        key=lambda r: proposals_key(r))
    props_warm = sorted(proposals_from_sole(sym, rule, sole_warm, subs, fires_by_period),
                        key=lambda r: proposals_key(r))

    direct_keys = [proposals_key(r) for r in props_direct]
    cold_keys = [proposals_key(r) for r in props_cold]
    warm_keys = [proposals_key(r) for r in props_warm]

    ok("cold == direct (voorstellen)", cold_keys == direct_keys,
       f"direct={direct_keys} cold={cold_keys}")
    ok("warm == direct (voorstellen)", warm_keys == direct_keys,
       f"direct={direct_keys} warm={warm_keys}")


# ──────────────────────── Test 4: incrementeel == volledig ────────────────────────

def test_incremental():
    """Incrementele cache geeft dezelfde sole als een volledige scan."""
    print("\n=== Test 4: incrementeel == volledig ===")
    sym = o.optimize_coin_ids()[0]
    rule = 21

    if os.path.isdir(loosen_cache.LOOSEN_DIR):
        shutil.rmtree(loosen_cache.LOOSEN_DIR)

    eng = DiagEngine(sym)
    periods, _ = promising_verdicts(sym)
    spans = [(p["period_from"], p["period_to"], p["id"]) for p in periods]

    # volledige referentie
    sole_full = build_sole(sym, rule, eng, spans)

    # simuleer een eerdere run met een afgeknipte last_max (1 dag eerder)
    new_max = fires_cache.series_max_datetime(sym)
    if new_max is None:
        ok("incrementeel (geen data, skip)", True)
        eng.close()
        return

    fake_last_max = new_max - timedelta(days=1)
    fake_pfx_cksum = fires_cache.prefix_indicators_checksum(sym, up_to=fake_last_max)

    # bouw de prefix-sole ZONDER to-limiet (zoals de echte koude start doet), zodat de prefix
    # momenten in de overlap-zone [t_safe, fake_last_max] bevat — de merge moet die correct
    # vervangen door tail-momenten
    from config import FORWARD_MINUTES
    t_safe_fake = fake_last_max - timedelta(minutes=FORWARD_MINUTES)
    sole_prefix = build_sole(sym, rule, eng, spans)

    # sla de prefix op als "vorige cache"
    os.makedirs(loosen_cache.LOOSEN_DIR, exist_ok=True)
    fake_fp = "0000000000000000"
    fake_path = loosen_cache._sole_path(sym, rule, fake_fp)
    meta = {"last_max": fake_last_max.isoformat(), "prefix_checksum": fake_pfx_cksum,
            "non_data_sig": loosen_cache._non_data_sig(sym, rule)}
    loosen_cache._write_atomic(loosen_cache._serialize_sole(sole_prefix, meta), fake_path)

    # nu incrementeel
    eng2 = DiagEngine(sym)
    sole_inc, status = loosen_cache.cached_build_sole_incremental(sym, rule, eng2, spans)
    eng2.close()
    ok(f"incrementeel status={status}", status == "incremental", f"got {status}")

    # vergelijk voorstellen
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT datetime, period_id, best_upside FROM coin_fires "
                  "WHERE trading_symbol_id=%s AND rule=%s AND is_executed=1", (sym, rule))
        exec_fires = c.fetchall()
    conn.close()
    fires_by_period = defaultdict(list)
    for r in exec_fires:
        fires_by_period[r["period_id"]].append((r["datetime"], r["best_upside"]))
    subs = eng.rules[rule]
    eng.close()

    props_full = sorted(proposals_from_sole(sym, rule, sole_full, subs, fires_by_period),
                        key=lambda r: proposals_key(r))
    props_inc = sorted(proposals_from_sole(sym, rule, sole_inc, subs, fires_by_period),
                       key=lambda r: proposals_key(r))

    full_keys = [proposals_key(r) for r in props_full]
    inc_keys = [proposals_key(r) for r in props_inc]

    ok("incrementeel == volledig (voorstellen)", inc_keys == full_keys,
       f"full={full_keys} inc={inc_keys}")

    # informatief: hoeveel momenten in de staart zaten (kan 0 zijn als er geen sole-blocked ticks
    # in de laatste dag zitten — dat is geen fout, alleen minder sterk bewijs van T_safe)
    n_tail = sum(1 for k, v in sole_inc.items()
                 for m in v["moments"]
                 if (m["dt"] if isinstance(m["dt"], datetime) else datetime.fromisoformat(m["dt"])) >= t_safe_fake)
    print(f"  INFO  staart-momenten: {n_tail} (≥ {t_safe_fake})")


# ──────────────────────── main ────────────────────────

if __name__ == "__main__":
    test_split()
    test_fingerprint()
    test_cache_hit()
    test_incremental()
    print(f"\n{'='*50}")
    print(f"  {PASSED} PASS / {FAILED} FAIL")
    if FAILED:
        sys.exit(1)
