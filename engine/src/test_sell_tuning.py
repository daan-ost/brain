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
from sell_tuning import metrics, verdict, split_per_rule, MIN_SPLIT
import sell_apply
import opt_lib as ol
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


def test_verdict_geen_holdout_klein():
    """Een helft kleiner dan MIN_SPLIT = geen geldige apart-gehouden testperiode → GEEN_HOLDOUT (nooit
    SAFE). Vangt de scheve mini-holdout die anders een vals SAFE-stempel kreeg."""
    assert verdict(_m(3, 5, n=MIN_SPLIT), _m(1, 4, n=MIN_SPLIT - 1)) == "GEEN_HOLDOUT"
    assert verdict(_m(3, 5, n=MIN_SPLIT - 1), _m(1, 4, n=MIN_SPLIT)) == "GEEN_HOLDOUT"
    assert verdict(_m(3, 5, n=MIN_SPLIT), _m(1, 4, n=MIN_SPLIT)) == "SAFE"     # precies op de grens = OK


def test_split_per_rule():
    """Per-regel mediaan-split: een regel die pas laat begint krijgt zijn EIGEN oudste/nieuwste helft,
    i.p.v. (bij de oude globale knip) volledig in de holdout te belanden met een lege train."""
    # rule 20 vroeg (t=1..4), rule 21 laat (t=5..8) — chronologisch gesorteerd zoals load_trades levert.
    rows = [{"datetime": t, "rule": (20 if t <= 4 else 21)} for t in range(1, 9)]
    splits, med = split_per_rule(rows)
    assert [splits[i] for i in (0, 1, 2, 3)] == ["train", "train", "holdout", "holdout"]   # rule 20
    assert [splits[i] for i in (4, 5, 6, 7)] == ["train", "train", "holdout", "holdout"]   # rule 21: NIET alles holdout
    assert med[20] == 3 and med[21] == 7        # eerste holdout-tick per regel


def test_signflip_pvalue():
    """De sign-flip toeval-toets (bug 4): consistente netto-winst = lage p, gemengde ruis = hoge p, te
    weinig geraakte trades = floor > 0.05 (kan niet certificeren), netto-negatief = p 1.0."""
    assert ol.signflip_pvalue([1.5] * 12)["p"] < 0.01                       # 12× positief = sterk
    assert ol.signflip_pvalue([2.0, -1.8, 1.9, -2.1, 1.7, -1.9])["p"] > 0.05  # gemengd = ruis
    thin = ol.signflip_pvalue([1.0, 1.0, 1.0, 1.0])                          # 4 trades → floor 1/16
    assert thin["floor"] > 0.05 and thin["n"] == 4
    assert ol.signflip_pvalue([0.0, 0.0]) is None                            # geen effect = niet toetsbaar


def test_toeval_filter():
    """_toeval_filter (bug 4) houdt alleen het Šidák-overlevende voorstel: ruis-p afgewezen, te dun (hoge
    floor) = kan niet certificeren, sterk = behouden. Pure dict-logica, geen DB."""
    emit = lambda m, level="info", rule=None, data=None: None
    best = {
        (2525, 20): {"coin_name": "DOGEAI", "rule": 20, "knob": "hp_setting6", "from": 4.0, "to": 6.0,
                     "perm_p": 0.0005, "perm_floor": 0.001, "perm_n": 30},    # sterk → KEEP
        (2525, 21): {"coin_name": "DOGEAI", "rule": 21, "knob": "hp_setting6", "from": 4.0, "to": 3.0,
                     "perm_p": 0.30, "perm_floor": 0.001, "perm_n": 25},       # ruis → afgewezen
        (244, 23): {"coin_name": "NOS", "rule": 23, "knob": "hp_setting6", "from": 4.0, "to": 3.0,
                    "perm_p": 0.0625, "perm_floor": 0.0625, "perm_n": 4},      # te dun → kan niet certificeren
    }
    kept = sell_apply._toeval_filter(best, 3, emit)
    assert set(kept) == {(2525, 20)}


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
