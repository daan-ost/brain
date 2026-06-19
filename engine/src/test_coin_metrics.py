#!/usr/bin/env python3
"""
Tests voor coin_metrics — de kansrijk-score (forward-upside). Plat assert-script (geen pytest), draai:
  ../.venv/bin/python test_coin_metrics.py

Dekt _forward_upside: constante prijs → 0, stijging → het juiste %, en het 60-min venster (een piek
ná het venster telt niet mee).
"""
import datetime as _dt
import numpy as np

from coin_metrics import _forward_upside, UP_THRESHOLD


def _dts(mins):
    base = _dt.datetime(2025, 1, 1, 12, 0)
    return np.array([base + _dt.timedelta(minutes=m) for m in mins], dtype="datetime64[s]")


def test_constante_prijs_geen_upside():
    dt = _dts([0, 1, 2, 3])
    up = _forward_upside(dt, [100, 100, 100, 100])
    assert np.allclose(up, 0.0), up


def test_stijging_binnen_venster():
    """Tick 0 ziet binnen 60 min een piek van 105 → upside 5%."""
    dt = _dts([0, 10, 20, 30])
    up = _forward_upside(dt, [100, 102, 105, 103])
    assert abs(up[0] - 5.0) < 1e-6, up[0]            # max forward = 105 → +5%
    assert abs(up[1] - (105 - 102) / 102 * 100) < 1e-6, up[1]


def test_piek_buiten_venster_telt_niet():
    """De piek ligt 90 min later (buiten 60-min venster) → tick 0 ziet die niet."""
    dt = _dts([0, 30, 90])
    up = _forward_upside(dt, [100, 100, 200])
    assert abs(up[0]) < 1e-6, up[0]                  # 200 ligt buiten 60 min → geen upside voor tick 0
    assert abs(up[1] - 100.0) < 1e-6, up[1]          # tick op t=30 ziet 200 op t=90 (binnen 60 min)


def test_laatste_tick_geen_forward():
    dt = _dts([0, 10])
    up = _forward_upside(dt, [100, 110])
    assert abs(up[1]) < 1e-6, up[1]                  # geen ticks na de laatste → 0


def test_up_threshold_telling():
    """% ticks met upside >= UP_THRESHOLD — de kansrijk-maat zelf."""
    dt = _dts([0, 10, 20, 30])
    up = _forward_upside(dt, [100, 100, 100, 100.5])  # alleen tick met grote forward telt
    frac = float((up >= UP_THRESHOLD).mean() * 100)
    assert frac == 0.0, frac                          # geen enkele ≥3% → 0%
    dt2 = _dts([0, 10, 20, 30])
    up2 = _forward_upside(dt2, [100, 104, 100, 100])  # tick 0 ziet 104 → +4% ≥3%
    assert (up2 >= UP_THRESHOLD)[0], up2


if __name__ == "__main__":
    fns = [v for k, v in sorted(globals().items()) if k.startswith("test_") and callable(v)]
    for fn in fns:
        fn()
        print(f"  ok  {fn.__name__}")
    print(f"\n{len(fns)} tests geslaagd.")
