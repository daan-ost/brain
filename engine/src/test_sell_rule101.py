#!/usr/bin/env python3
"""
Tests voor rule_engine_101 — pint de sell_x_below-teller vast tegen de legacy-semantiek
(functions_br.php §sell_x_below). Plat assert-script, geen pytest. Draai:
    ../.venv/bin/python test_sell_rule101.py

Legacy-invariant die we bewaken:
  - sell_x_below telt EXACT `def1_value` rijen (i=0..limit-1), niet limit+1. De extra opgehaalde rij
    is enkel prijs-referentie voor de oudste getelde rij. (Regressie: een eerdere port deed +1.)
  - k=0 telt op val<0; k>0 telt op val<0 ÉN dalende prijs (nieuwer < ouder).
  - Een rij vóór de koopdatum (of te weinig history) breekt de hele subrule af: geen sell.
"""
from datetime import datetime, timedelta

from sell_rule101 import rule_engine_101, calc_percentage


def _dt(n):
    return datetime(2025, 1, 1, 0, 0, 0) + timedelta(minutes=n)


def _xbelow(limit, need, vc=0.999):
    return [{"subrulename": "sell_x_below", "def1_value": str(limit),
             "b_max": str(need), "value_condition": str(vc)}]


def _run(PX, VV, i, buy_dt, subrules):
    DT = [_dt(n) for n in range(len(PX))]
    return rule_engine_101(DT, PX, VV, i, buy_dt, PX[0], PX[i], subrules, max(PX[:i + 1]))


def test_xbelow_four_of_four_sells():
    """4 van 4 rijen negatief + dalend → sell (de happy path)."""
    PX = [10, 9, 8, 7, 6, 5]                 # strikt dalend
    VV = [1, 1, -1, -1, -1, -1]              # idx 2..5 negatief
    status, _ = _run(PX, VV, 5, _dt(0), _xbelow(4, 4))
    assert status == "sell", status


def test_xbelow_counts_exactly_limit_not_plus_one():
    """REGRESSIE: 3 van de eerste 4 kwalificeren, de 5e rij zou het 4e telpunt zijn.
    Legacy telt maar 4 rijen → cnt=3 → GEEN sell. De oude +1-port telde de 5e mee → sell."""
    PX = [20, 18, 16, 14, 12, 10, 8]         # strikt dalend
    VV = [1, 1, -1, 1, -1, -1, -1]           # idx3 (= k=3, het 4e telpunt) is POSITIEF
    status, _ = _run(PX, VV, 6, _dt(0), _xbelow(4, 4))
    assert status == "hold", f"verwacht hold (legacy telt 4 rijen), kreeg {status}"


def test_xbelow_aborts_before_buy_date():
    """Een rij vóór de koopdatum breekt de subrule af: geen sell, ook al haalt cnt de drempel.
    buy_dt = DT[i-1] → bij k=2 valt idx=i-2 vóór de koop. need=2 zou anders al gehaald zijn."""
    PX = [10, 9, 8, 7]                        # strikt dalend
    VV = [-1, -1, -1, -1]
    status, _ = _run(PX, VV, 3, _dt(2), _xbelow(4, 2))   # buy_dt = DT[2]; idx=1 < buy → abort
    assert status == "hold", f"verwacht hold (abort vóór koopdatum), kreeg {status}"


def test_xbelow_rising_price_does_not_count():
    """k>0 telt alleen bij DALENDE prijs. Stijgende prijs → geen telpunt → geen sell."""
    PX = [5, 6, 7, 8, 9, 10]                  # strikt STIJGEND
    VV = [-1, -1, -1, -1, -1, -1]             # allemaal negatief volume
    status, _ = _run(PX, VV, 5, _dt(0), _xbelow(4, 4))
    assert status == "hold", status


def test_calc_percentage_signs():
    """Vastpinnen: teken volgt frm>to (daling = negatief), frm==0 → 0.0 (geen oordeel)."""
    assert calc_percentage(100, 110) == 10.0
    assert calc_percentage(110, 99) == -10.0
    assert calc_percentage(0, 5) == 0.0
    assert calc_percentage(50, 50) == 0.0


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
