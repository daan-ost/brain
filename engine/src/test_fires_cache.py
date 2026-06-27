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


def test_per_rule_identity(frm, to):
    """Plan B: de per-rule cache geeft EXACT dezelfde all_fires als de all-rules-lus (gesorteerde concat),
    cold == warm == direct."""
    re = RuleEngine(SYM)
    try:
        rules = sorted(re.rules.keys())
        direct = _compute_fn(re, frm, to)()
        crf = lambda rule: re.fires(rule, frm, to)
        cold, n_warm, n_rules = fc.cached_fires_per_rule(SYM, frm, to, rules, crf, force=True)
        assert n_warm == 0, "force=True moet alle rules cold"
        assert cold == direct, f"per-rule cold != all-rules direct: {len(cold)} vs {len(direct)}"
        warm, n_warm2, _ = fc.cached_fires_per_rule(SYM, frm, to, rules, crf)
        assert n_warm2 == n_rules, f"tweede call moet alle {n_rules} rules warm zijn (was {n_warm2})"
        assert warm == direct, "per-rule warm (parquet round-trip) != direct"
        assert all(isinstance(d, _dt.datetime) and isinstance(r, int) for d, r in warm), "verkeerde types"
        print(f"  E per-rule cold==warm==direct: PASS ({len(direct)} fires, {n_rules} rules)")
    finally:
        re.close()


def test_per_rule_isolation(frm, to):
    """Plan B-kern: één rule's BAND wijzigen verandert ALLEEN die rule's fingerprint; de andere rules
    blijven warm (hun fp ongewijzigd). Dat is de hele winst — anders re-firen de zware discovery-rules mee."""
    re = RuleEngine(SYM)
    rules = sorted(re.rules.keys())
    re.close()
    fps0 = {r: fc.rule_fires_fingerprint(SYM, r, frm, to) for r in rules}
    target = 20 if 20 in rules else rules[0]
    c = brain()
    snap = None
    try:
        with c.cursor() as cur:
            cur.execute("SELECT id, b_min FROM rules WHERE active=1 AND rule_number=%s "
                        "AND b_min IS NOT NULL ORDER BY sort LIMIT 1", (target,))
            snap = cur.fetchone()
            assert snap, f"geen subregel met b_min op rule {target} om te testen"
            cur.execute("UPDATE rules SET b_min=%s WHERE id=%s", (float(snap["b_min"]) - 7.77, snap["id"]))
        c.commit()
        fps1 = {r: fc.rule_fires_fingerprint(SYM, r, frm, to) for r in rules}
        assert fps1[target] != fps0[target], f"rule {target} band-wijziging veranderde z'n fingerprint NIET"
        others_changed = [r for r in rules if r != target and fps1[r] != fps0[r]]
        assert not others_changed, f"andere rules' fp veranderde mee (zou niet mogen): {others_changed}"
        print(f"  F per-rule isolatie: PASS (rule {target} wijzigde, {len(rules)-1} andere rules ongemoeid)")
    finally:
        with c.cursor() as cur:
            if snap:
                cur.execute("UPDATE rules SET b_min=%s WHERE id=%s", (snap["b_min"], snap["id"]))
        c.commit()
        c.close()


def test_determinism(frm, to):
    a = fc.fires_fingerprint(SYM, frm, to)
    b = fc.fires_fingerprint(SYM, frm, to)
    assert a == b, "fingerprint niet deterministisch"
    assert fc.fires_fingerprint(SYM, frm, to + _dt.timedelta(days=1)) != a, "venster zit niet in de fingerprint"
    print("  D determinisme + venster-gevoeligheid: PASS")


def test_cleanup_isolates_per_rule(frm, to):
    """Cleanup mag GEEN cache van een ander (frm,to)-venster weggooien. Anders wist een test op smal
    venster de productie-cache van persist_to_brain en omgekeerd — dat ondermijnt Plan B in beide
    richtingen. Bewijst: na een run op smal venster bestaan de files van een eerdere run op ander
    venster (gemockt) nóg steeds.
    Was vóór deze fix STUK: de cleanup gooide elk niet-in-keep bestand voor de munt weg."""
    import os, glob
    re = RuleEngine(SYM)
    try:
        rules = sorted(re.rules.keys())
        # Run 1: smal venster — schrijf cache, leg paden vast
        crf = lambda rule: re.fires(rule, frm, to)
        _, _, _ = fc.cached_fires_per_rule(SYM, frm, to, rules, crf, force=True)
        paths_smal = sorted(glob.glob(os.path.join(fc.FIRES_DIR, f"fires_{SYM}_r*_w*__*.parquet")))
        assert len(paths_smal) == len(rules), f"smal: verwachtte {len(rules)} files, kreeg {len(paths_smal)}"
        # Run 2: ander venster (verschoven met +1 dag) — moet eigen files schrijven en de smal-files LATEN STAAN
        frm2, to2 = frm + _dt.timedelta(days=1), to + _dt.timedelta(days=1)
        crf2 = lambda rule: re.fires(rule, frm2, to2)
        _, _, _ = fc.cached_fires_per_rule(SYM, frm2, to2, rules, crf2, force=True)
        paths_na = sorted(glob.glob(os.path.join(fc.FIRES_DIR, f"fires_{SYM}_r*_w*__*.parquet")))
        # Verwacht: 2 fp-files per rule (smal + ander), dus 2*N totaal
        assert len(paths_na) == 2 * len(rules), (
            f"cleanup gooide cross-venster files weg: verwachtte {2*len(rules)}, kreeg {len(paths_na)} "
            f"(smal {len(paths_smal)} → na {len(paths_na)})")
        # Smal-files moeten er ALLEMAAL nog zijn
        kept_smal = [p for p in paths_smal if os.path.exists(p)]
        assert kept_smal == paths_smal, f"smal-venster files verdwenen: {set(paths_smal) - set(kept_smal)}"
        print(f"  G cleanup respecteert ander venster: PASS ({len(rules)} smal + {len(rules)} ander = {len(paths_na)} files)")
    finally:
        # Beide vensters' caches opruimen zodat het geen rommel achterlaat in fires-dir
        for p in glob.glob(os.path.join(fc.FIRES_DIR, f"fires_{SYM}_r*_w*__*.parquet")):
            try: os.remove(p)
            except OSError: pass
        re.close()


if __name__ == "__main__":
    frm, to = _window()
    print(f"test_fires_cache — fires-memoïsatie vangnet (NOS, venster {frm} .. {to})")
    test_bit_identity(frm, to)
    test_per_rule_identity(frm, to)
    test_per_rule_isolation(frm, to)
    test_sell_change_does_not_invalidate(frm, to)
    test_min_volume_change_invalidates(frm, to)
    test_determinism(frm, to)
    test_cleanup_isolates_per_rule(frm, to)
    print("ALLE TESTS PASS")
