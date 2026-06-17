#!/usr/bin/env python3
"""
Tests voor de sell-tuning routine (FASE 7). Plat assert-script in de stijl van de andere
validate_*.py — geen pytest-dependency. Draai: ../.venv/bin/python test_sell_tuning.py

Dekt de kern-invarianten:
  - FAITHFUL MERGE: lege coin_strategies = byte-identiek aan de globale strategies (geen drift).
  - OVERRIDE + ERVEN + ISOLATIE: een override zet één knob, erft de rest, raakt geen andere rule.
  - METRICS: de netto-ruil + flips (winst→verlies) + redding + affected-telling kloppen.
  - HOLDOUT-POORT (verdict): SAFE/OVERFIT/ZWAK/INERT/UNSAFE classificeren correct.
  - OVERRIDE-RESPECT: de apply-laag schrijft alleen coin_strategies; handmatige labels (manual_set_at)
    worden nooit aangeraakt, en een throwaway-override wordt schoon opgeruimd.
"""
import json

from sell_engine import merge_sl
from sell_lock import parse_sl
from sell_tuning import metrics, verdict
import sell_apply
from db import brain


GLOB = {
    20: json.dumps({"min_sl1": "0.988", "minimal_profit": "0.8", "hp_setting6": "4"}),
    21: json.dumps({"min_sl1": "0.988", "minimal_profit": "0.8"}),
}


def test_faithful_merge():
    """Lege override-laag → exact parse_sl van de globale rules (faithful)."""
    assert merge_sl(GLOB, {}) == {20: parse_sl(GLOB[20]), 21: parse_sl(GLOB[21])}


def test_override_inherits_rest():
    """Een override op één knob verandert die knob en erft de rest van de globale rule."""
    m = merge_sl(GLOB, {20: json.dumps({"minimal_profit": "0.5"})})
    assert m[20]["minimal_profit"] == 0.5      # gewijzigd
    assert m[20]["min_sl1"] == 0.988           # geërfd
    assert m[20]["hp6"] == 4.0                  # geërfd


def test_override_isolation():
    """Een override op rule 20 raakt rule 21 niet."""
    m = merge_sl(GLOB, {20: json.dumps({"min_sl1": "0.99"})})
    assert m[21] == parse_sl(GLOB[21])


def test_metrics():
    """Netto-ruil, flips, redding, uitloop en affected op een handgebouwde set."""
    m = metrics([(-1.0, 2.0), (5.0, -1.0), (1.0, 1.0), (1.0, 4.0)])
    assert m["n"] == 4
    assert m["affected"] == 3                   # (1,1) verandert niet
    assert m["won"] == 6.0                       # 3 + 3
    assert m["lost"] == 6.0                       # 6
    assert m["netto"] == 0.0
    assert m["losers_base"] == 1 and m["losers_tuned"] == 1
    assert m["redding"] == 1                      # (-1 → 2)
    assert m["uitloop"] == 1                      # (1 → 4): middel → goed
    assert m["flips"] == 1                        # (5 → -1): winst → verlies


def _m(netto, affected, flips=0, lb=4, lt=3, n=10):
    return {"n": n, "affected": affected, "netto": netto, "flips": flips,
            "losers_base": lb, "losers_tuned": lt}


def test_verdict_safe():
    assert verdict(_m(3, 5), _m(1, 4)) == "SAFE"


def test_verdict_overfit():
    """Wint op train, zakt op holdout = OVERFIT (de recall-seed-tighten-les)."""
    assert verdict(_m(3, 5), _m(-2, 4)) == "OVERFIT"


def test_verdict_zwak():
    """Train-effect maar 0 holdout-trades geraakt = geen bewijs = ZWAK (niet SAFE)."""
    assert verdict(_m(3, 5), _m(0, 0)) == "ZWAK"


def test_verdict_inert():
    assert verdict(_m(0, 0), _m(0, 0)) == "INERT"


def test_verdict_unsafe_flip():
    """Holdout breekt een winnaar (flips>0) → nooit SAFE."""
    assert verdict(_m(3, 5), _m(1, 4, flips=1)) == "UNSAFE"


def test_verdict_unsafe_extra_verliezers():
    """Holdout maakt extra verliezers (losers_tuned > losers_base) → nooit SAFE."""
    assert verdict(_m(3, 5), _m(1, 4, lb=3, lt=5)) == "UNSAFE"


def test_override_respect_and_cleanup():
    """De apply-laag schrijft alleen coin_strategies; handmatige labels blijven onaangeroerd, en een
    throwaway-override wordt schoon opgeruimd. Coin 999/rule 99 bestaat niet → geen engine-impact."""
    conn = brain()
    try:
        manual_before = sell_apply.manual_count(conn, 244)        # echte munt
        assert sell_apply.read_override(conn, 999, 99) is None     # schone start

        sell_apply.write_override(conn, 999, 99, "minimal_profit", 1.23)
        sell_apply.write_override(conn, 999, 99, "min_sl1", 0.99)   # 2e knob mergt erbij
        got = json.loads(sell_apply.read_override(conn, 999, 99))
        assert got == {"minimal_profit": "1.23", "min_sl1": "0.99"}, got

        # handmatige labels NIET aangeraakt door de override-schrijf
        assert sell_apply.manual_count(conn, 244) == manual_before

        # restore met prev=None → rij weg (schoon)
        sell_apply.restore_override(conn, 999, 99, None)
        assert sell_apply.read_override(conn, 999, 99) is None
    finally:
        with conn.cursor() as c:                                   # vangnet: laat niets achter
            c.execute("DELETE FROM coin_strategies WHERE trading_symbol_id=999")
        conn.commit()
        conn.close()


def run():
    tests = [v for k, v in sorted(globals().items()) if k.startswith("test_") and callable(v)]
    fails = 0
    for t in tests:
        try:
            t()
            print(f"  PASS  {t.__name__}")
        except Exception as e:
            fails += 1
            print(f"  FAIL  {t.__name__} — {type(e).__name__}: {e}")
    print(f"\n{len(tests) - fails}/{len(tests)} geslaagd.")
    return fails


if __name__ == "__main__":
    import sys
    sys.exit(1 if run() else 0)
