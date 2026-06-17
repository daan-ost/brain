#!/usr/bin/env python3
"""
Tests voor de koop-bevestiging (buy_confirm). Plat assert-script (geen pytest), draai:
  ../.venv/bin/python test_buy_confirm.py

Dekt de bevestigings-logica: kruising boven het signaal binnen het venster, de dip-dan-herstel-case,
de b_min-abort, futureprice_x_rows, geen-forward-data, en dat 'exact op het signaal' niet telt.
"""
import datetime as _dt

from buy_confirm import confirm_buy

T = [_dt.datetime(2025, 1, 1, 12, 0) + _dt.timedelta(minutes=i) for i in range(8)]
S = 100.0


def test_kruist_boven():
    """Komt binnen het venster boven het signaal → bevestigd op die tick."""
    assert confirm_buy(T, [100, 99, 101, 102, 103, 104, 105, 106], T[0], S) == (T[2], 101)


def test_dip_dan_herstel():
    """Daan's voorbeeld: dipt naar -0,5% (t1), herstelt naar +0,3% boven signaal (t2) → bevestigd.
    Met b_min=-1.2 blaast de -0,5%-dip NIET af."""
    assert confirm_buy(T, [100, 99.5, 100.3, 101, 102, 103, 104, 105], T[0], S, fp_bmin=-1.2) == (T[2], 100.3)


def test_nooit_boven():
    """Blijft binnen het venster onder het signaal → afgeblazen."""
    assert confirm_buy(T, [100, 99, 99.5, 99, 99, 99, 99, 99], T[0], S) is None


def test_exact_op_signaal_telt_niet():
    """Raakt het signaal maar komt er niet BOVEN → niet bevestigd (strikt groter)."""
    assert confirm_buy(T, [100, 100, 100, 100, 100, 100, 100, 100], T[0], S) is None


def test_bmin_abort():
    """Zakt eerst onder signaal*(1+b_min/100) = 98,8 vóór de kruising → afgeblazen."""
    assert confirm_buy(T, [100, 98, 101, 102, 103, 104, 105, 106], T[0], S, fp_bmin=-1.2) is None


def test_xrows_snelle_drop():
    """Zakt -1,0% binnen de eerste 2 tics (drempel -0,7) → afgeblazen, ook al kruist hij later."""
    assert confirm_buy(T, [100, 99, 101, 102, 103, 104, 105, 106], T[0], S, xrows=(2, -0.7)) is None


def test_xrows_milde_drop_ok():
    """Zakt maar -0,3% binnen 2 tics (boven drempel -0,7) en kruist daarna → bevestigd."""
    assert confirm_buy(T, [100, 99.7, 101, 102, 103, 104, 105, 106], T[0], S, xrows=(2, -0.7)) == (T[2], 101)


def test_venster_grens():
    """Komt pas NA het venster (3 min) boven het signaal → afgeblazen."""
    # tics op 0,1,2,3,4 min; binnen 3 min (t1,t2,t3) blijft hij onder; pas t4 (4 min) erboven.
    assert confirm_buy(T, [100, 99, 99, 99, 101, 102, 103, 104], T[0], S, window_min=3) is None


def test_geen_forward_data():
    assert confirm_buy(T, [100, 99], T[7], S) is None


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
