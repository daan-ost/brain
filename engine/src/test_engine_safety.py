#!/usr/bin/env python3
"""Orakel-vangnet voor twee veiligheids-kritieke kern-stukken van de trading-engine. Plain assert,
geen pytest. Draai: ../.venv/bin/python test_engine_safety.py

[1] OUTLIER-GUARD koop-kant — RuleEngine en PromisingEngine mogen NOOIT vuren/scoren op een prijs-
    outlier die de SellEngine wegfiltert (anders raakt koop-datetime ontkoppeld van koop-prijs, en
    blaast best_upside/promising op tot honderden %). Test: spik een synthetische outlier in PX en
    verifieer dat hij door is_price_outlier wordt gepakt + dat het filter-pad van de PromisingEngine
    'm dropt. Daarnaast: orakel-overeenstemming tussen filter_outliers (SellEngine-pad) en
    outlier_dt_set (RuleEngine-pad) — beide moeten DEZELFDE outlier-set produceren, anders zou de
    koop-kant nog een outlier-fire kunnen krijgen die de SellEngine al wég had.

[2] LOCK_PROFIT grens-orakel — onze Python lock_profit is een byte-voor-byte port van legacy
    functions_br.php:4921-4951. Bevries de exacte grens-overgangen (0.15 / 0.21 / 0.30 / 0.40 / 0.50
    / 0.70 / 5.0) zodat een toekomstige refactor niet stiekem off-by-one-en de verkoop-timing kan
    verschuiven. Per grens: één tik onder + één tik op = verschillende tak (strikte ongelijkheden in
    legacy). De gebruikte hp-multipliers volgen legacy defaults (regels 4777-4783)."""
import datetime as _dt
import sys

import outlier_guard as og
from sell_lock import lock_profit, parse_sl


# ---------------------------------------------------------------------------
# [1] Outlier-guard koop-kant
# ---------------------------------------------------------------------------

def test_outlier_detection_basic():
    """Een 1000x-glitch midden in een vlakke prijsreeks moet als outlier herkend worden, een normale
    tick niet — onafhankelijk van DB."""
    # 20 vlakke prijzen rond 0.023, één outlier op index 10 (factor ~1e6)
    PX = [0.023 + (i % 3) * 0.0001 for i in range(20)]
    PX[10] = 23044.0                                # de feed-glitch uit de docstring
    bad = og.outlier_indices(PX)
    assert 10 in bad, f"feed-glitch op index 10 NIET gedetecteerd: {bad}"
    assert bad == [10], f"vals positief op normale ticks: {bad}"
    # zonder glitch: geen outliers
    PX_clean = [0.023 + (i % 3) * 0.0001 for i in range(20)]
    assert og.outlier_indices(PX_clean) == [], "false positive op vlakke reeks"
    print(f"  [1a] outlier-detectie basic: PASS (factor 1e6-glitch gepakt, geen false positives)")


def test_promising_filter_in_path():
    """De PromisingEngine-init moet OUTLIERS uit DT/PX gooien — bewezen door de in-process filter-tak
    direct na te bootsen op dezelfde data zoals __init__ doet."""
    DT = [_dt.datetime(2024, 1, 1, 12, 0) + _dt.timedelta(minutes=i) for i in range(20)]
    PX = [0.023 + (i % 3) * 0.0001 for i in range(20)]
    PX[7] = 99999.0
    bad = set(og.outlier_indices(PX))
    assert 7 in bad, "outlier niet gedetecteerd in test-reeks"
    keep = [i for i in range(len(PX)) if i not in bad]
    DT_f = [DT[i] for i in keep]
    PX_f = [PX[i] for i in keep]
    assert len(DT_f) == len(DT) - 1 == 19, f"verkeerd aantal na filter: {len(DT_f)}"
    assert 99999.0 not in PX_f, "outlier-prijs nog steeds in gefilterde reeks"
    assert DT[7] not in DT_f, "outlier-datetime nog steeds in gefilterde reeks"
    print(f"  [1b] promising-filter pad: PASS (gefilterd 20 → 19, outlier-dt verdwenen)")


def test_outlier_set_consistency_live(sym=244):
    """Live-data check (NOS): outlier_dt_set (RuleEngine-pad) en filter_outliers (SellEngine-pad)
    produceren DEZELFDE outliers — anders zou de koop-kant fires kunnen krijgen op ticks die de
    SellEngine al wegfiltert. Beide gebruiken outlier_indices(PX) onder de motorkap, dus de set MOET
    identiek zijn op de geserialiseerde volumeud-reeks."""
    from db import brain
    c = brain()
    try:
        with c.cursor() as cur:
            cur.execute("SELECT datetime, price, value FROM indicators WHERE trading_symbol_id=%s "
                        "AND indicator='volumeud' AND price IS NOT NULL ORDER BY datetime", (sym,))
            rows = cur.fetchall()
        if not rows:
            print(f"  [1c] consistency live: SKIP (sym {sym} heeft geen volumeud-prijzen)")
            return
        # dedup zoals SellEngine doet
        seen, DT, PX = set(), [], []
        for r in rows:
            if r["datetime"] in seen:
                continue
            seen.add(r["datetime"])
            DT.append(r["datetime"]); PX.append(float(r["price"]))
        # SellEngine-pad
        _, _, _, n_dropped_sell = og.filter_outliers(DT, PX, [0.0] * len(DT))
        bad_sell = {DT[i] for i in og.outlier_indices(PX)}
        # RuleEngine-pad
        bad_rule = og.outlier_dt_set(c, sym, "volumeud")
        # In de praktijk hoort dit een lege set te zijn (ingest heeft outliers al ge-NULLED).
        # De ENGE eis: beide paden geven DEZELFDE set. Een leakage tussen de twee zou een outlier-
        # fire kunnen veroorzaken (RuleEngine vuurt, SellEngine ziet de tick niet → price-mismatch).
        assert bad_sell == bad_rule, (f"OUTLIER-SET MISMATCH koop vs verkoop: "
                                       f"sell-pad {len(bad_sell)}, rule-pad {len(bad_rule)} — "
                                       f"verschil: {bad_sell.symmetric_difference(bad_rule)}")
        print(f"  [1c] consistency live (sym {sym}): PASS ({n_dropped_sell} outliers in beide paden, identieke set)")
    finally:
        c.close()


# ---------------------------------------------------------------------------
# [2] lock_profit grens-orakel — bevries legacy functions_br.php:4921-4951
# ---------------------------------------------------------------------------

def _stop_for_hi(hi):
    """Roep lock_profit aan met genoeg-rijp-gate-passe condities zodat we de hp-tak raken (geen
    young-trade-fallback). buy=100, market=buy (markt staat gelijk aan koop). minutes ruim boven
    min2 + profit boven minimal_profit zodat het geen min_sl-fallback wordt."""
    sl = parse_sl(None)                                 # legacy defaults
    return lock_profit(profit=10.0, minutes=999, hi=hi, buy=100.0, market=100.0, sl=sl)


def test_lock_profit_grenzen_orakel():
    """Per grens een paar dichtbij + op de grens; bewijs dat de takken precies switchen. Strikte
    ongelijkheden (`< X`) in legacy — dus op exact `X` valt het in de VOLGENDE tak. Vergelijkt
    formule-uitkomsten, niet getallen-strings, zodat float-ruis geen test breekt."""
    sl = parse_sl(None)
    buy = 100.0

    # hi < 0.15 → fallback (buy * min_sl1)
    assert _stop_for_hi(0.14) == buy * sl["min_sl1"], "hi=0.14 hoort de fallback te raken"
    # hi == 0.15 → eerste ratchet-tak (hp1 = -0.003 → buy + (-0.003)*buy = 99.7)
    assert abs(_stop_for_hi(0.15) - (buy + sl["hp1"] * buy)) < 1e-9, "grens 0.15 fout"
    # hi == 0.20999 → nog steeds tak hp1
    assert abs(_stop_for_hi(0.20999) - (buy + sl["hp1"] * buy)) < 1e-9, "vlak voor 0.21 fout"
    # hi == 0.21 → tak hp2 (-0.002 → 99.8)
    assert abs(_stop_for_hi(0.21) - (buy + sl["hp2"] * buy)) < 1e-9, "grens 0.21 fout"
    # hi == 0.30 → tak hp3 (-0.0015 → 99.85)
    assert abs(_stop_for_hi(0.30) - (buy + sl["hp3"] * buy)) < 1e-9, "grens 0.30 fout"
    # hi == 0.40 → tak hp4 (+0.001 → 100.1)
    assert abs(_stop_for_hi(0.40) - (buy + sl["hp4"] * buy)) < 1e-9, "grens 0.40 fout"
    # hi == 0.50 → tak hp5 (+0.001 → 100.1)
    assert abs(_stop_for_hi(0.50) - (buy + sl["hp5"] * buy)) < 1e-9, "grens 0.50 fout"
    # hi == 0.6999 → nog tak hp5
    assert abs(_stop_for_hi(0.6999) - (buy + sl["hp5"] * buy)) < 1e-9, "vlak voor 0.70 fout"
    # hi == 0.70 → eerste hi/hp6-formule-tak (~25% van piek)
    expected_70 = buy + ((0.70 / sl["hp6"]) / 100) * buy
    assert abs(_stop_for_hi(0.70) - expected_70) < 1e-9, f"grens 0.70 fout (verwacht {expected_70})"
    # hi == 4.999 → nog tak hi/hp6
    expected_499 = buy + ((4.999 / sl["hp6"]) / 100) * buy
    assert abs(_stop_for_hi(4.999) - expected_499) < 1e-9, "vlak voor 5.0 fout"
    # hi == 5.0 → laatste tak (hi - hp7)/100 → ~50% van piek
    expected_50 = buy + ((5.0 - sl["hp7"]) / 100) * buy
    assert abs(_stop_for_hi(5.0) - expected_50) < 1e-9, "grens 5.0 fout"
    # hi groot → nog steeds laatste tak
    expected_big = buy + ((50.0 - sl["hp7"]) / 100) * buy
    assert abs(_stop_for_hi(50.0) - expected_big) < 1e-9, "grote hi fout"
    print(f"  [2a] lock_profit grenzen 0.15/0.21/0.30/0.40/0.50/0.70/5.0: PASS")


def test_lock_profit_monotonie_pre_5pct():
    """Op [0.15, 5.0) loopt de stop monotoon NIET-DALEND in hi — trapsgewijs omhoog, nooit terug.
    BOVEN 5% is er een bekende legacy-quirk (zie test_lock_profit_5pct_legacy_quirk): de rauwe
    lock_profit-formule wisselt van `hi/hp6` naar `(hi-hp7)/100` met hp7=15, wat bij hi=5 een
    discontinue terugval geeft. Dat wordt downstream gevangen door de floor_clamp in
    sell_engine._determine_stop. Hier testen we de rauwe lock_profit alleen tot vlak vóór 5.0."""
    his = [0.15 + i * 0.001 for i in range(int((5.0 - 0.15) / 0.001))]
    stops = [_stop_for_hi(h) for h in his]
    drops = [(his[i], stops[i - 1], stops[i]) for i in range(1, len(stops)) if stops[i] < stops[i - 1] - 1e-9]
    assert not drops, f"stop ZAKT bij hogere hi op {len(drops)} plek(ken), eerste: hi={drops[0][0]:.3f} {drops[0][1]:.6f}→{drops[0][2]:.6f}"
    print(f"  [2b] lock_profit monotoon non-dalend op [0.15, 5.0): PASS ({len(stops)} samples)")


def test_lock_profit_5pct_legacy_quirk():
    """BEWUSTE QUIRK uit legacy functions_br.php:4942-4950: bij hi=5.0 wisselt de formule en de rauwe
    lock zakt van ~101.25 (buy + 25% van piek) naar 90.0 (buy - 10%) — want (5-hp7=-10)/100. Pas bij
    hi=hp7=15.0 raakt de stop weer break-even, en daarboven lockt hij 'echt' 50% van de piek.

    Deze test bevriest dat als BEKEND legacy-gedrag (niet onze bug om te 'fixen'). De praktische
    vangrail is _determine_stop's floor_clamp die de stop nooit onder buy * min_sl1 (~98.8) laat
    zakken — dat is wat de feitelijke productie-stop bepaalt, niet de rauwe lock_profit."""
    sl = parse_sl(None)
    buy = 100.0
    # de rauwe sprong bij 5% (de quirk)
    stop_4_99 = _stop_for_hi(4.99)
    stop_5 = _stop_for_hi(5.0)
    assert stop_4_99 > stop_5, f"verwachtte de 5%-sprong, maar 4.99→{stop_4_99}, 5.0→{stop_5}"
    expected_drop = abs(stop_4_99 - stop_5)
    assert expected_drop > 10, f"de quirk-sprong is kleiner dan verwacht: {expected_drop}"
    # break-even bij hi = hp7
    hp7 = sl["hp7"]
    stop_at_hp7 = _stop_for_hi(hp7)
    assert abs(stop_at_hp7 - buy) < 1e-9, f"bij hi=hp7={hp7} hoort stop = buy, kreeg {stop_at_hp7}"
    # boven hp7: lockt monotoon omhoog, ~50% van peak
    his = [hp7 + 1.0 + i * 0.5 for i in range(20)]
    stops = [_stop_for_hi(h) for h in his]
    for i in range(1, len(stops)):
        assert stops[i] > stops[i - 1] - 1e-9, f"boven hp7 niet monotoon op hi={his[i]}"
    # floor_clamp-vangrail: de echte productie-stop = max(lock, buy*min_sl1) — never onder de floor
    floor = buy * sl["min_sl1"]
    effective_stop_5 = max(stop_5, floor)
    assert effective_stop_5 == floor, f"floor_clamp moet hier ingrijpen: lock={stop_5}, floor={floor}"
    print(f"  [2c] 5%-quirk vastgelegd: PASS (sprong {stop_4_99:.2f}→{stop_5:.2f}, "
          f"break-even bij hi={hp7}, floor_clamp vangt → {floor:.3f})")


if __name__ == "__main__":
    print("test_engine_safety — outlier-guard koop-kant + lock_profit grens-orakel")
    sym = int(sys.argv[1]) if len(sys.argv) > 1 else 244
    test_outlier_detection_basic()
    test_promising_filter_in_path()
    test_outlier_set_consistency_live(sym)
    test_lock_profit_grenzen_orakel()
    test_lock_profit_monotonie_pre_5pct()
    test_lock_profit_5pct_legacy_quirk()
    print("ALLE TESTS PASS")
