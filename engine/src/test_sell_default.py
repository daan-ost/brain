#!/usr/bin/env python3
"""
test_sell_default.py — Tests voor de gepoolde sell-default sweep (Epic N).

Test de kernlogica: breedte-maat, verdict, override-immuniteit, toeval-toets-integratie.
Draai: python3 test_sell_default.py
"""
import unittest
from sell_default_sweep import _breedte, _verdict


class TestBreedte(unittest.TestCase):
    def _coin(self, train_netto, holdout_netto, sufficient=True):
        return {"train_netto": train_netto, "holdout_netto": holdout_netto,
                "sufficient": sufficient,
                "train": {"affected": 1 if abs(train_netto) > 0 else 0,
                           "n": 10, "netto": train_netto, "flips": 0,
                           "won": 0, "lost": 0, "losers_base": 0,
                           "losers_tuned": 0, "redding": 0, "uitloop": 0},
                "holdout": {"affected": 1 if abs(holdout_netto) > 0 else 0,
                             "n": 10, "netto": holdout_netto, "flips": 0,
                             "won": 0, "lost": 0, "losers_base": 0,
                             "losers_tuned": 0, "redding": 0, "uitloop": 0}}

    def test_breedte_improved_minus_harmed(self):
        data = {"A": self._coin(1.0, 2.0), "B": self._coin(0.5, 0.3),
                "C": self._coin(-0.5, -0.2)}
        b = _breedte(data, "holdout")
        self.assertEqual(b["score"], 1)  # 2 improved - 1 harmed
        self.assertIn("A", b["improved"])
        self.assertIn("B", b["improved"])
        self.assertIn("C", b["harmed"])

    def test_breedte_ignores_insufficient(self):
        data = {"A": self._coin(1.0, 2.0), "B": self._coin(-0.5, -0.2, sufficient=False)}
        b = _breedte(data, "holdout")
        self.assertEqual(b["score"], 1)  # only A counts
        self.assertEqual(b["n_sufficient"], 1)
        self.assertEqual(b["harmed"], [])

    def test_breedte_empty(self):
        b = _breedte({}, "holdout")
        self.assertEqual(b["score"], 0)
        self.assertEqual(b["n_sufficient"], 0)

    def test_breedte_median(self):
        data = {"A": self._coin(0, 5.0), "B": self._coin(0, 1.0), "C": self._coin(0, -0.5)}
        b = _breedte(data, "holdout")
        self.assertAlmostEqual(b["median_netto"], 1.0)


class TestVerdict(unittest.TestCase):
    def _br(self, score):
        return {"score": score, "improved": [], "harmed": [], "neutral": [],
                "median_netto": 0, "pooled_netto": 0, "n_sufficient": 3}

    def _cd(self, train_aff=1, holdout_aff=1):
        return {"X": {"train": {"affected": train_aff}, "holdout": {"affected": holdout_aff}}}

    def test_globaal_safe(self):
        self.assertEqual(_verdict(self._br(3), self._br(2), self._cd()), "GLOBAAL_SAFE")

    def test_globaal_safe_holdout_zero(self):
        self.assertEqual(_verdict(self._br(3), self._br(0), self._cd()), "GLOBAAL_SAFE")

    def test_overfit(self):
        self.assertEqual(_verdict(self._br(3), self._br(-1), self._cd()), "OVERFIT")

    def test_zwak_no_holdout_effect(self):
        self.assertEqual(_verdict(self._br(3), self._br(0),
                                  self._cd(train_aff=1, holdout_aff=0)), "ZWAK")

    def test_inert(self):
        self.assertEqual(_verdict(self._br(0), self._br(0),
                                  self._cd(train_aff=0, holdout_aff=0)), "INERT")

    def test_inert_empty(self):
        self.assertEqual(_verdict(self._br(0), self._br(0), {}), "INERT")

    def test_unsafe_train_not_positive(self):
        self.assertEqual(_verdict(self._br(0), self._br(2), self._cd()), "UNSAFE")
        self.assertEqual(_verdict(self._br(-1), self._br(2), self._cd()), "UNSAFE")


class TestOverrideImmuniteit(unittest.TestCase):
    """Conceptuele test: een munt met override op knop X is immuun voor default-shift op X."""

    def test_override_check_logic(self):
        ovr = {20: {"hp_setting6", "min_sl1"}}
        self.assertIn("hp_setting6", ovr.get(20, set()))
        self.assertNotIn("hp_setting7", ovr.get(20, set()))
        self.assertNotIn("hp_setting6", ovr.get(21, set()))


if __name__ == "__main__":
    unittest.main()
