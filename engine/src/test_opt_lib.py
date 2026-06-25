#!/usr/bin/env python3
"""
Vangnet voor de optimize-validatie-kern (Fase 0 van het schaalplan, zie docs/findings/optimize-scaling-
plan-2026-06-25.md). Legt de huidige bit-exacte semantiek van opt_lib vast ZODAT een latere
herimplementatie (fase 4: de groupby-vectorisatie van full_validation) er tegen getoetst kan worden.
Plat assert-script, geen pytest. Read-only, geen database. Draai:
    python3 test_opt_lib.py

Bewaakte invarianten:
  - bad_edge_conditions: drempel op de SLECHTE rand, ALLE goede behouden (strikt < / >, regel 159-163).
  - scale_unsafe: cache-drempels op volumeud-LEVEL-metrics zijn inert in de engine → afgewezen.
  - sidak / required_raw_p: de familiebrede correctie wordt strenger met meer pogingen.
  - full_validation: tijd-split + LEAVE-ONE-OUT cross-coin; verdict = MIN(good_keep) over splits.
    Een coin met te weinig goed/slecht levert GEEN split (geen rij met nullen). NaN-good_keep telt niet
    mee. Dit is de orakel-referentie voor fase 4.
  - crosscoin_splits: LOO over de optimize-coins (default DOGEAI+NOS) — N coins → N splits.
  - de fingerprint-classificatie-checksum (Fase 0a): invariant onder magnitude-drift BINNEN een klasse,
    maar gevoelig voor een goed<->slecht RUIL bij gelijke counts (de gedichte blindspot).
"""
import zlib

import numpy as np
import pandas as pd

import opt_lib as o


def test_bad_edge_conditions():
    good = np.array([0.0, 10.0, 20.0])
    bad = np.array([-40.0, -30.0, 50.0])     # twee onder good.min(=0), één boven good.max(=20)
    conds = o.bad_edge_conditions(good, bad)
    low = [c for c in conds if c["bound"] == "lower"]
    up = [c for c in conds if c["bound"] == "upper"]
    # lower: drempel = hoogste slechte strikt onder de goede band = -30; dropt de -40 (1 slechte)
    assert low and abs(low[0]["threshold"] - (-30)) < 1e-9 and low[0]["drop_insample"] == 1, conds
    # upper: drempel = laagste slechte strikt boven de goede band = 50; dropt niets strikt erboven
    assert not up or up[0]["drop_insample"] >= 1, conds
    print("  bad_edge_conditions: PASS")


def test_scale_unsafe():
    assert o.scale_unsafe("volumeud", "median_value")           # volumeud LEVEL-metric → inert in engine
    assert o.scale_unsafe("volumeud", "diff_number_prev_min")
    assert not o.scale_unsafe("volumeud", "range_percentage")   # schaal-invariant → veilig
    assert not o.scale_unsafe("vzo", "median_value")            # vzo niet genormaliseerd → veilig
    print("  scale_unsafe: PASS")


def test_sidak():
    assert abs(o.sidak(0.05, 1) - 0.05) < 1e-9
    assert o.sidak(0.02, 50) > 0.05            # 1 'significant' ogende p is ruis over 50 pogingen
    assert o.required_raw_p(124) < 0.0005      # strenger met meer pogingen
    print("  sidak / required_raw_p: PASS")


def _mini_long():
    """Synthetische long-tabel: 2 coins (2525, 244), 1 feature (mfi/median_value/lb5), per coin een
    train- en test-helft met goede (hoge value) en slechte (lage value) trades. Een lower-bound op de
    slechte rand houdt alle goede en dropt de slechte → SAFE op alle splits."""
    # per coin ≥5 goed én ≥5 slecht zodat de cross-coin minima (min_tr_good/bad=5) gehaald worden;
    # goede waarden ruim boven de slechte zodat een lower-bound alle goede behoudt.
    rows = []
    dt = pd.Timestamp("2025-01-01")
    for sym in (2525, 244):
        for split in ("train", "test"):
            for cls, vals in (("goed", [50.0, 60.0, 70.0, 80.0]), ("slecht", [10.0, 20.0, 30.0, 40.0])):
                for v in vals:
                    dt += pd.Timedelta(minutes=1)
                    rows.append(dict(sym=sym, datetime=dt, rule=20, cls=cls, split=split,
                                     best_upside=0.0, indicator="mfi", lookback=5,
                                     calc="median_value", value=v))
    return pd.DataFrame(rows)


def test_full_validation_oracle():
    long = _mini_long()
    res = o.full_validation(long, 20, "mfi", 5, "median_value", "lower")
    # tijd-split + beide LOO cross-coin richtingen aanwezig (default optimize-coins = DOGEAI, NOS)
    assert "time" in res, res
    cc_keys = [k for k in res if "->" in k]
    assert len(cc_keys) == 2, res                              # LOO over 2 coins = 2 splits
    # de duidelijke scheiding houdt ALLE goede op elke split (good_keep == 1.0)
    for k, v in res.items():
        assert v["good_keep"] == 1.0, (k, v)
    print(f"  full_validation orakel: PASS (splits: time + {cc_keys})")


def test_crosscoin_splits_loo():
    splits = o.crosscoin_splits()                              # default optimize-coins
    assert len(splits) == 2, splits                            # 2 coins → 2 LOO-splits
    for train, test in splits:
        assert test not in train                               # leave-one-out: test niet in train
    print(f"  crosscoin_splits LOO: PASS ({len(splits)} splits)")


def _cls_checksum(trades):
    """Python-spiegel van de SQL-fingerprint (SUM(CRC32(coin|datetime|rule|cls))) uit
    routines.input_fingerprint(with_fires) — bewijst de eigenschap los van de DB. SUM, niet XOR: CRC32 is
    lineair over XOR, dus een gelijk-lengte goed↔slecht-ruil zou onder XOR cancelen (deze test bewijst dat
    het met SUM niet cancelt)."""
    x = 0
    for sym, dt, rule, pl in trades:
        cls = "g" if pl >= 3 else ("b" if pl < 0 else "m")
        x += zlib.crc32(f"{sym}|{dt}|{rule}|{cls}".encode())
    return x


def test_fingerprint_classification_property():
    base = [(2525, "2025-01-01 10:00:00", 20, 5.0),    # goed
            (2525, "2025-01-01 11:00:00", 20, -2.0),   # slecht
            (244, "2025-01-02 09:00:00", 21, 8.0)]      # goed
    # magnitude-drift BINNEN een klasse (5.0 -> 6.5, beide 'goed') → checksum ONVERANDERD
    drift = [(2525, "2025-01-01 10:00:00", 20, 6.5), base[1], base[2]]
    assert _cls_checksum(base) == _cls_checksum(drift), "magnitude binnen klasse mag niet hertriggeren"
    # een RUIL goed<->slecht bij gelijke counts → checksum MOET veranderen (de gedichte blindspot)
    swap = [(2525, "2025-01-01 10:00:00", 20, -1.0),   # was goed, nu slecht
            (2525, "2025-01-01 11:00:00", 20, 4.0),     # was slecht, nu goed
            base[2]]
    assert _cls_checksum(base) != _cls_checksum(swap), "een goed<->slecht ruil moet hertriggeren"
    print("  fingerprint classificatie-checksum: PASS")


if __name__ == "__main__":
    print("test_opt_lib — optimize-validatie-kern vangnet")
    test_bad_edge_conditions()
    test_scale_unsafe()
    test_sidak()
    test_full_validation_oracle()
    test_crosscoin_splits_loo()
    test_fingerprint_classification_property()
    print("ALLE TESTS PASS")
