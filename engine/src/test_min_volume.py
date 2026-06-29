#!/usr/bin/env python3
"""
test_min_volume.py — Feature 1 van Epic K: nul-guard + integrity min_volume>0.

Dekt:
  - check_volumeud_3 crasht NIET (geen ZeroDivisionError) bij min_volume = 0 of negatief;
    de tick wordt veilig overgeslagen (False). Vóór de fix crashte volume.py:72.
  - check_rule_settings faalt expliciet als een coin × buy-rule geen POSITIEVE min_volume heeft
    (NULL óf <= 0).
Draai: python3 test_min_volume.py
"""
import unittest

from volume import check_volumeud_3, RULE21_VOLUME_SETTINGS
from integrity import check_rule_settings


def _rows(n=10):
    """newest-first dummy volumeud-rows met value + price (>= minimal_rows_to_analyse)."""
    return [{"value": 1000 + i * 10, "price": 0.01 + i * 0.0001} for i in range(n)]


class TestVolumeNulGuard(unittest.TestCase):
    def test_min_volume_zero_no_crash(self):
        # vóór de fix: ZeroDivisionError op volume.py:72 (round(value_0 / 0, 2))
        self.assertFalse(check_volumeud_3(_rows(), 0, RULE21_VOLUME_SETTINGS))

    def test_min_volume_negative_no_crash(self):
        self.assertFalse(check_volumeud_3(_rows(), -5, RULE21_VOLUME_SETTINGS))

    def test_valid_min_volume_runs(self):
        # geldige schaal: uitkomst mag True/False zijn, maar moet zonder exception draaien
        self.assertIn(check_volumeud_3(_rows(), 1000, RULE21_VOLUME_SETTINGS), (True, False))


class _FakeCtx:
    """Minimale Context-vervanger voor check_rule_settings. q() bootst de DB-filter na
    (rule in 20-23 AND min_volume IS NOT NULL AND min_volume > 0)."""
    def __init__(self, settings, coins):
        self._settings = settings          # list of (symid, rule, min_volume); min_volume None = NULL
        self.coins = coins
        self.symbol = {c: f"COIN{c}" for c in coins}

    def q(self, sql, args=()):
        return [{"trading_symbol_id": s, "rule_number": r}
                for (s, r, mv) in self._settings
                if r in (20, 21, 22, 23) and mv is not None and mv > 0]


class TestRuleSettingsIntegrity(unittest.TestCase):
    def _full(self, mv):
        return [(1, r, mv) for r in (20, 21, 22, 23)]

    def test_all_positive_ok(self):
        self.assertEqual(check_rule_settings(_FakeCtx(self._full(1000), coins=[1])).status, "ok")

    def test_zero_min_volume_fails(self):
        settings = [(1, 20, 0)] + [(1, r, 1000) for r in (21, 22, 23)]
        res = check_rule_settings(_FakeCtx(settings, coins=[1]))
        self.assertEqual(res.status, "fail")
        self.assertEqual(res.details["missing"], [{"coin": "COIN1", "rule": 20}])

    def test_null_min_volume_fails(self):
        settings = [(1, 20, None)] + [(1, r, 1000) for r in (21, 22, 23)]
        self.assertEqual(check_rule_settings(_FakeCtx(settings, coins=[1])).status, "fail")


if __name__ == "__main__":
    unittest.main()
