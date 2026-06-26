#!/usr/bin/env python3
"""Orakel-vangnet voor fires_cache (Plan A — fires-memoïsatie). Bewijst:
  A. cold-compute == warm-load == directe rule_engine.fires()-lus, BIT-IDENTIEK (de hele bestaansreden:
     de cache mag de trade-set niet veranderen).
  B. een VERKOOP-wijziging (coin_strategies) invalideert de fires-fingerprint NIET (de winst bij sell-tuning).
  C. een FIRES-input (coin_rule_settings.min_volume) invalideert WEL.
  D. fingerprint deterministisch + warm-flag klopt.

Plain-assert (geen pytest). Draai: ../.venv/bin/python test_fires_cache.py
Smal tijdvenster zodat de discovery-rules niet de volle reeks scannen (round-trip-identiteit is venster-
onafhankelijk). Mutaties in B/C gaan via snapshot→wijzig→assert→restore in try/finally (geen rommel)."""
import datetime as _dt
import sys

from db import brain
import fires_cache as fc
from rule_engine import RuleEngine

SYM = 244  # NOS (lichtste munt)


def _window():
    """Pak een venster van ~5 dagen aan het eind van NOS' reeks — genoeg fires om zinnig te zijn, snel."""
    c = brain()
    with c.cursor() as cur:
        cur.execute("SELECT MIN(datetime) lo, MAX(datetime) hi FROM indicators "
                    "WHERE trading_symbol_id=%s AND indicator='volumeud'", (SYM,))
        r = cur.fetchone()
    c.close()
    hi = r["hi"]
    return hi - _dt.timedelta(days=5), hi + _dt.timedelta(seconds=1)


def _compute_fn(re, frm, to):
    def fn():
        af = []
        for rule in sorted(re.rules.keys()):
            for dt in re.fires(rule, frm, to):
                af.append((dt, rule))
        af.sort()
        return af
    return fn


def test_bit_identity(frm, to):
    re = RuleEngine(SYM)
    try:
        compute = _compute_fn(re, frm, to)
        direct = compute()
        cold, warm_flag = fc.cached_all_fires(SYM, frm, to, compute, force=True)
        assert warm_flag is False, "force=True moet cold (geen cache-hit) zijn"
        assert cold == direct, f"cold != direct: {len(cold)} vs {len(direct)}"
        warm, warm_flag2 = fc.cached_all_fires(SYM, frm, to, compute)
        assert warm_flag2 is True, "tweede call moet warm (cache-hit) zijn"
        assert warm == direct, "warm (parquet round-trip) != direct — datetime/rule verschoven!"
        # types: datetimes zijn echte python-datetimes, rules ints (persist_to_brain rekent erop)
        assert all(isinstance(d, _dt.datetime) and isinstance(r, int) for d, r in warm), "verkeerde types"
        print(f"  A bit-identity: PASS ({len(direct)} fires, cold==warm==direct)")
    finally:
        re.close()


def test_sell_change_does_not_invalidate(frm, to):
    """Schrijf een tijdelijke coin_strategies-override (de SELL-tabel) → fingerprint moet GELIJK blijven."""
    fp0 = fc.fires_fingerprint(SYM, frm, to)
    c = brain()
    snap = None
    try:
        with c.cursor() as cur:
            cur.execute("SELECT id, sl_settings FROM coin_strategies WHERE trading_symbol_id=%s AND rule_number=20", (SYM,))
            snap = cur.fetchone()
            if snap:
                cur.execute("UPDATE coin_strategies SET sl_settings=%s WHERE id=%s",
                            ('{"hp_setting6": "999"}', snap["id"]))
            else:
                cur.execute("INSERT INTO coin_strategies (trading_symbol_id, rule_number, sl_settings, created_at, updated_at) "
                            "VALUES (%s, 20, %s, NOW(), NOW())", (SYM, '{"hp_setting6": "999"}'))
        c.commit()
        fp1 = fc.fires_fingerprint(SYM, frm, to)
        assert fp0 == fp1, "VERKOOP-wijziging veranderde de fires-fingerprint — cache zou onnodig invalideren!"
        print("  B sell-change → fingerprint ONGEWIJZIGD: PASS")
    finally:
        with c.cursor() as cur:
            if snap:
                cur.execute("UPDATE coin_strategies SET sl_settings=%s WHERE id=%s", (snap["sl_settings"], snap["id"]))
            else:
                cur.execute("DELETE FROM coin_strategies WHERE trading_symbol_id=%s AND rule_number=20 AND sl_settings=%s",
                            (SYM, '{"hp_setting6": "999"}'))
        c.commit()
        c.close()


def test_min_volume_change_invalidates(frm, to):
    """Wijzig coin_rule_settings.min_volume (een fires-input) → fingerprint moet VERANDEREN."""
    fp0 = fc.fires_fingerprint(SYM, frm, to)
    c = brain()
    snap = None
    try:
        with c.cursor() as cur:
            cur.execute("SELECT id, min_volume FROM coin_rule_settings WHERE trading_symbol_id=%s AND min_volume IS NOT NULL LIMIT 1", (SYM,))
            snap = cur.fetchone()
            assert snap, "geen coin_rule_settings-rij om te testen"
            newv = float(snap["min_volume"]) + 12345.0
            cur.execute("UPDATE coin_rule_settings SET min_volume=%s WHERE id=%s", (newv, snap["id"]))
        c.commit()
        fp1 = fc.fires_fingerprint(SYM, frm, to)
        assert fp0 != fp1, "min_volume-wijziging veranderde de fingerprint NIET — fires-input gemist!"
        print("  C min_volume-change → fingerprint VERANDERD: PASS")
    finally:
        with c.cursor() as cur:
            if snap:
                cur.execute("UPDATE coin_rule_settings SET min_volume=%s WHERE id=%s", (snap["min_volume"], snap["id"]))
        c.commit()
        c.close()


def test_determinism(frm, to):
    a = fc.fires_fingerprint(SYM, frm, to)
    b = fc.fires_fingerprint(SYM, frm, to)
    assert a == b, "fingerprint niet deterministisch"
    assert fc.fires_fingerprint(SYM, frm, to + _dt.timedelta(days=1)) != a, "venster zit niet in de fingerprint"
    print("  D determinisme + venster-gevoeligheid: PASS")


if __name__ == "__main__":
    frm, to = _window()
    print(f"test_fires_cache — fires-memoïsatie vangnet (NOS, venster {frm} .. {to})")
    test_bit_identity(frm, to)
    test_sell_change_does_not_invalidate(frm, to)
    test_min_volume_change_invalidates(frm, to)
    test_determinism(frm, to)
    print("ALLE TESTS PASS")
