#!/usr/bin/env python3
"""
Tests voor de outlier-guard — bewijst dat een corrupte schaal-glitch-tick (bv. price=23044 waar
~0,02304 hoort) wordt afgewezen, en dat bekend-goede momenten (een normale reeks én een echte
aanhoudende pump) ongemoeid blijven. Plat assert-script, geen pytest. Draai:
    python3 test_outlier_guard.py

Read-only: raakt geen database aan; werkt op in-geheugen prijsreeksen.

Invarianten die we bewaken:
  - één tick die ~1e6x van zijn buren afwijkt → outlier (de echte bug, DOGEAI 2025-06-10 13:06:37).
  - een normale, vlakke reeks → geen enkele outlier.
  - een echte pump van ~2-3x over meerdere ticks (DOGEAI 2025-02-20) → GEEN outlier (niet snoeien).
  - filter_outliers houdt DT/PX/VV uitgelijnd en laat een schone reeks intact (0 dropped).
"""
from datetime import datetime, timedelta

from outlier_guard import (OUTLIER_FACTOR, is_price_outlier, outlier_indices,
                           filter_outliers)


def _series(prices):
    DT = [datetime(2025, 6, 10, 13, 0, 0) + timedelta(minutes=i) for i in range(len(prices))]
    VV = [float(i) for i in range(len(prices))]
    return DT, list(prices), VV


def test_scale_glitch_tick_is_rejected():
    """De echte bug: één tick van 23044 te midden van ~0,023-prijzen is een outlier."""
    PX = [0.02289, 0.02287, 0.02289, 23044.0, 0.02304, 0.02303, 0.02305]
    assert is_price_outlier(PX, 3), "23044-tick had outlier moeten zijn"
    assert outlier_indices(PX) == [3], outlier_indices(PX)


def test_flat_normal_series_has_no_outliers():
    """Een normale, licht bewegende reeks → niets snoeien."""
    PX = [0.0229, 0.0230, 0.0228, 0.0231, 0.0230, 0.0229, 0.0232]
    assert outlier_indices(PX) == [], outlier_indices(PX)


def test_real_pump_is_not_an_outlier():
    """Een echte aanhoudende pump (~0,033 → 0,055 over meerdere ticks, ~1,4x t.o.v. buur-mediaan)
    mag NIET als outlier worden weggegooid — anders missen we echte winst."""
    PX = [0.03341, 0.03053, 0.03192, 0.03303, 0.03572, 0.03963,
          0.05160, 0.05511, 0.04663, 0.04329, 0.04780, 0.04333]
    assert outlier_indices(PX) == [], outlier_indices(PX)


def test_low_glitch_below_neighbours_is_rejected():
    """Symmetrisch: een tick ver ONDER zijn buren (factor-glitch omlaag) is ook een outlier."""
    PX = [0.023, 0.023, 0.023, 0.0000001, 0.023, 0.023, 0.023]
    assert is_price_outlier(PX, 3), "near-zero tick had outlier moeten zijn"


def test_threshold_boundary():
    """Net binnen de factor blijft staan, net erboven wordt afgewezen (drempel = OUTLIER_FACTOR)."""
    base = [0.02] * 6
    just_under = base[:3] + [0.02 * (OUTLIER_FACTOR * 0.9)] + base[3:]
    just_over = base[:3] + [0.02 * (OUTLIER_FACTOR * 1.1)] + base[3:]
    assert not is_price_outlier(just_under, 3), "net onder de drempel mag blijven"
    assert is_price_outlier(just_over, 3), "net boven de drempel moet weg"


def test_filter_outliers_keeps_alignment_and_drops_bad():
    """filter_outliers verwijdert de glitch en houdt DT/PX/VV uitgelijnd."""
    DT, PX, VV = _series([0.0229, 0.0228, 0.0229, 23044.0, 0.0230, 0.0231])
    bad_dt = DT[3]
    DT2, PX2, VV2, dropped = filter_outliers(DT, PX, VV)
    assert dropped == 1, dropped
    assert bad_dt not in DT2
    assert len(DT2) == len(PX2) == len(VV2) == 5
    assert 23044.0 not in PX2
    # uitlijning intact: de VV bij de behouden prijzen klopt nog
    assert VV2 == [0.0, 1.0, 2.0, 4.0, 5.0], VV2


def test_filter_outliers_noop_on_clean_series():
    """Schone reeks → 0 dropped, reeksen ongewijzigd."""
    DT, PX, VV = _series([0.0229, 0.0230, 0.0228, 0.0231, 0.0230])
    DT2, PX2, VV2, dropped = filter_outliers(DT, PX, VV)
    assert dropped == 0
    assert PX2 == PX


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
